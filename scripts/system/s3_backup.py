#!/usr/bin/env python3
# ==============================================================================
# s3_backup.py — copy completed backup archives to an S3-compatible bucket
#
# Usage: inetp s3_backup upload [--manual]     start a background upload of what
#                                              is not in the bucket yet, then
#                                              apply retention; returns at once
#        inetp s3_backup test                  check the saved settings, or the
#                                              JSON settings given on stdin
#        inetp s3_backup set-secret            store the secret key (stdin)
#        inetp s3_backup clear-secret          forget the stored secret key
#        inetp s3_backup status                show the last upload run
#        inetp s3_backup retention [--dry-run] apply remote retention now
#        inetp s3_backup cron                  install/remove the hourly retry job
#        inetp s3_backup selftest              built-in signing/retention checks
#        add --json for machine-readable output (the panel uses it)
#
# Works with AWS S3 and S3-compatible services (Cloudflare R2, Backblaze B2,
# Wasabi, DigitalOcean Spaces, MinIO). Standard library only: nothing to
# install, and this file is deployed root-owned to /root/scripts/ like the
# other system scripts — the panel never runs it from its own www-data tree.
#
# WHERE THINGS LIVE, AND WHY
# --------------------------
# Settings (endpoint, bucket, folder, retention, ...) are ordinary panel
# settings. The SECRET KEY is not: it is in /root/.inetpanel_s3_secret (0600),
# never in the panel database or in any file www-data can read. The panel hands
# the secret over on stdin — never argv, which Shell::run() logs and process
# listings show, and never a staging file — and only root can read it.
#
# WHAT IS UPLOADED
# ----------------
# The newest archive of each account, the newest server-configuration archive
# (unless turned off) and every pre-deletion archive in /backup/deleted. Older
# local copies are never chased: a slow uplink works on the newest set only,
# and the account that has waited longest goes first so none is starved.
# backup_accounts.sh writes archives under a hidden .partial name and renames
# them into place when complete, so a *.tgz seen here is always finished; each
# is read through one open descriptor, so a rename over it mid-upload cannot
# mix two archives.
#
# HOW
# ---
# backup_accounts.sh calls `upload` when it finishes. That only launches the
# worker and returns: the worker runs in its own transient systemd unit, so
# nothing waits on the network and a php-fpm restart cannot kill an upload the
# panel started. One worker at a time (flock); it re-scans after every file and
# after retention, so an archive finished at any moment is never stranded.
#
# Every request carries the SHA-256 of its body in its signature, so the
# provider rejects anything altered in transit. Large archives go up as a
# multipart upload with equal-size parts (Cloudflare R2 rejects unequal ones);
# a failed or interrupted one is aborted, because unfinished parts are billed.
# CompleteMultipartUpload can answer HTTP 200 with an <Error> body, and is
# parsed for one; the object's size is then confirmed with HEAD.
#
# A dropped connection is retried for up to 20 minutes per request, which rides
# out a router reboot or a PPPoE reconnect. If the link stays down the run
# stops, and an hourly cron job (installed only while this is enabled) retries
# — it does nothing unless a run failed or was cut off.
#
# RETENTION
# ---------
# Every upload is tagged with this server's id (x-amz-meta-inetpanel-server),
# and retention only ever deletes objects carrying it, directly under the
# folder, named exactly <account>_<YYYY-MM-DD>.tgz. One is deleted only when
# both its backup date and its upload time are past the cut-off. The newest
# backup of each account is always kept, and nothing is deleted for an account
# whose newest local archive has not reached the bucket yet — a run of failed
# uploads must never age out the last good copy. Pre-deletion archives
# (deleted/) are never removed, matching the local rule for /backup/deleted.
# ==============================================================================

import calendar
import datetime
import email.utils
import errno
import fcntl
import getpass
import hashlib
import hmac
import http.client
import ipaddress
import json
import math
import os
import random
import re
import secrets
import signal
import socket
import sqlite3
import ssl
import stat
import subprocess
import sys
import threading
import time
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

# ── Fixed paths. Not configurable: nothing a caller passes can move them. ──────
PANEL_DB       = '/var/www/inetpanel/db/inetpanel.db'
# backup_accounts.sh always writes here, whatever the backup_destination
# setting says, so this follows the script that produces the archives.
BACKUP_DIR     = '/backup'
FINAL_DIR      = '/backup/deleted'
SECRET_FILE    = '/root/.inetpanel_s3_secret'
STATE_DIR      = '/var/lib/inetpanel/s3'
STATE_FILE     = STATE_DIR + '/state.json'
LOCK_FILE      = STATE_DIR + '/worker.lock'
PID_FILE       = STATE_DIR + '/worker.pid'
SERVER_ID_FILE = STATE_DIR + '/server_id'
LOG_FILE       = '/var/log/inetpanel_s3.log'
CRON_FILE      = '/etc/cron.d/inetpanel_s3'
# The panel tree's copy of this script. rsync --delete removes it if the
# feature is ever reverted, while /root/scripts is never pruned — so its
# absence means "switched off by an update": remove the cron and do nothing.
PANEL_COPY     = '/var/www/inetpanel/scripts/system/s3_backup.py'
SCRIPT_PATH    = os.path.abspath(__file__)
PYTHON         = '/usr/bin/python3'

MIB = 1024 * 1024
PART_SIZE = 32 * MIB             # preferred multipart part size
MIN_PART_SIZE = 5 * MIB          # S3 / R2 minimum for every part but the last
MAX_PARTS = 10000
READ_CHUNK = 256 * 1024
CONNECT_TIMEOUT = 15             # per address
IO_TIMEOUT = 120                 # a stall this long fails the request, which is then retried
COMPLETE_TIMEOUT = 600           # S3 may take minutes to assemble a large object
DATA_RETRY_WINDOW = 20 * 60      # PutObject / UploadPart / Complete
META_RETRY_WINDOW = 2 * 60       # HEAD / LIST / DELETE / Create / Abort
MAX_BACKOFF = 60
FILE_ATTEMPTS_PER_RUN = 2
FAILURES_TO_END_PASS = 3         # consecutive archives, any reason
OBJECT_BACKOFF = (15 * 60, 3600, 3 * 3600, 6 * 3600, 12 * 3600)
CONFLICT_BACKOFF = 24 * 3600
INFLIGHT_EXPIRE = 7 * 86400      # stop retrying an abort the key may not be allowed to do
MAX_RESPONSE = 4 * MIB           # S3 XML answers are a few hundred KB at most
MAX_LIST_PAGES = 10000
MAX_DELETES_PER_RUN = 1000
HEARTBEAT_EVERY = 30             # liveness ping (s3_heartbeat), from its own thread
PROGRESS_EVERY = 15              # progress writes to s3_status
STALE_AFTER = 600                # the cron restarts a run silent for this long
LOG_MAX_BYTES = 10 * MIB
LOG_KEEP_BYTES = 2 * MIB
EMPTY_SHA256 = hashlib.sha256(b'').hexdigest()
ALLOW_LOCAL_HTTP = False         # tests only: plain http to this machine

# <account>_<YYYY-MM-DD>.tgz — the only names this script uploads or deletes.
ARCHIVE_RE = re.compile(r'([A-Za-z0-9][A-Za-z0-9._-]*)_(\d{4}-\d{2}-\d{2})\.tgz')
SYSTEM_SERIES = 'system_config'

# Validation — mirrored in api/backups.php. Settings come from a database the
# panel writes, so they are checked again here before root acts on them.
# Always applied with fullmatch(): `$` would also accept a trailing newline.
ENDPOINT_RE   = re.compile(r'https?://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?')
REGION_RE     = re.compile(r'[a-z0-9-]{1,32}')
BUCKET_RE     = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]')
DNS_BUCKET_RE = re.compile(r'[a-z0-9][a-z0-9-]{1,61}[a-z0-9]')
PREFIX_SEG_RE = re.compile(r'[A-Za-z0-9._-]+')
KEY_ID_RE     = re.compile(r'[\x21-\x7e]{3,128}')
SECRET_RE     = re.compile(r'[\x21-\x7e]{8,256}')
SERVER_ID_RE  = re.compile(r'[0-9a-f]{32}')

# Plain http only to a storage server on a private network, never to this
# machine: any local account can bind a free port here and receive uploads.
PRIVATE_NETS = [ipaddress.ip_network(n) for n in (
    '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7')]

# Error classes. Transient ones are retried within a time budget; the rest end
# the request at once.
TRANSIENT_CODES = {
    'Network', 'DNS', 'InternalError', 'SlowDown', 'RequestTimeout', 'ServiceUnavailable',
    'Throttling', 'RequestTimeoutException', 'OperationAborted', 'IncompleteBody', 'BadResponse',
}
INTEGRITY_CODES = {'BadDigest', 'XAmzContentSHA256Mismatch'}
# Retrying cannot fix these, and every other archive would hit them too: they
# stop the whole run with a clear message.
FATAL_CODES = {
    'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied', 'NoSuchBucket',
    'AuthorizationHeaderMalformed', 'PermanentRedirect', 'IllegalLocationConstraintException',
    'InvalidBucketName', 'AllAccessDisabled', 'AccountProblem', 'InvalidRequest',
    'RequestTimeTooSkewed', 'TLSCertificate', 'Config', 'NotImplemented',
}

STOP_REQUESTED = False
_STATUS = None


class Interrupted(Exception):
    """SIGTERM/SIGINT arrived; the current upload must be aborted cleanly."""


class FileChanged(Exception):
    """The archive was modified while it was being read."""


class FolderConflict(Exception):
    """Another server's backup already has this name in the folder."""


class S3Error(Exception):
    def __init__(self, op, status=0, code='', message='', extra=None):
        self.op = op
        self.status = status
        self.code = code or (('HTTP%d' % status) if status else 'Network')
        self.message = message or ''
        self.extra = extra or {}
        super().__init__('%s: %s' % (op, explain_error(self)))

    @property
    def transient(self):
        return self.code in TRANSIENT_CODES or self.status in (408, 429, 500, 502, 503, 504)

    @property
    def integrity(self):
        return self.code in INTEGRITY_CODES

    @property
    def fatal(self):
        return (self.code in FATAL_CODES or self.status in (301, 501)) and not self.transient


_EXPECTED_REGION_RE = re.compile(r"expected:?\s*'?\d{8}/([a-z0-9-]{1,32})/s3/aws4_request")


def explain_error(e):
    """One actionable sentence for the admin, from an S3Error."""
    code, x = e.code, e.extra
    lowered = (e.message or '').lower()
    # AWS names the region in <Region> or a header; Garage only in the message
    # ("unexpected scope ..., expected: '<date>/garage/s3/aws4_request'").
    m = _EXPECTED_REGION_RE.search(e.message or '')
    region = x.get('Region') or x.get('x-amz-bucket-region') or (m.group(1) if m else '')
    # Garage answers a bad key ID or secret with AccessDenied plus a message.
    if code == 'InvalidAccessKeyId' or (code == 'AccessDenied' and 'no such key' in lowered):
        return 'the access key ID was not recognised by the provider.'
    if code == 'SignatureDoesNotMatch' or (code == 'AccessDenied' and 'invalid signature' in lowered):
        return ('the secret key does not match the access key (also check that the '
                'endpoint and region are right for this bucket).')
    if region and (e.status in (301, 400) or code in ('AuthorizationHeaderMalformed', 'PermanentRedirect')):
        return 'the storage service expects region "%s" — set Region to %s.' % (region, region)
    if code in ('AuthorizationHeaderMalformed', 'PermanentRedirect') or e.status == 301:
        endpoint = x.get('Endpoint', '')
        if endpoint:
            return 'the bucket must be addressed through %s — check the endpoint and region.' % endpoint
        return 'wrong region or endpoint for this bucket.'
    if code == 'NoSuchBucket':
        return 'the bucket does not exist at this endpoint (check the name, endpoint and region).'
    if code in ('AccessDenied', 'HTTP403'):
        return 'access denied — the key does not have permission for this operation.'
    if code == 'RequestTimeTooSkewed':
        return "this server's clock is off by more than 15 minutes; fix NTP and try again."
    if code == 'InvalidBucketName':
        return 'the bucket name is not valid.'
    if code == 'TLSCertificate':
        return 'TLS certificate check failed for the endpoint (%s).' % e.message
    if code == 'DNS':
        return 'could not resolve the endpoint host name (%s).' % e.message
    if code == 'Network':
        return 'network error: %s' % (e.message or 'connection failed')
    if code == 'Config':
        return e.message
    msg = e.message or ''
    if e.status:
        return '%s (HTTP %d)%s' % (code, e.status, (': ' + msg) if msg else '')
    return '%s%s' % (code, (': ' + msg) if msg else '')


# ── Logging ────────────────────────────────────────────────────────────────────

_INTERACTIVE = sys.stdout.isatty()


def now_str(ts=None):
    return time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(ts) if ts else time.localtime())


def log(msg, level='INFO'):
    line = '[%s] %s %s\n' % (now_str(), level, msg)
    try:
        fd = os.open(LOG_FILE, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o640)
        with os.fdopen(fd, 'a') as fh:
            fh.write(line)
    except OSError:
        pass
    if _INTERACTIVE:
        sys.stdout.write(line)
        sys.stdout.flush()


def trim_log():
    """Keep the log bounded without needing a logrotate rule."""
    try:
        if os.path.islink(LOG_FILE) or not os.path.isfile(LOG_FILE):
            return
        if os.path.getsize(LOG_FILE) <= LOG_MAX_BYTES:
            return
        with open(LOG_FILE, 'rb') as fh:
            fh.seek(-LOG_KEEP_BYTES, os.SEEK_END)
            tail = fh.read()
        tail = tail[tail.find(b'\n') + 1:]
        tmp = LOG_FILE + '.trim'
        try:
            os.unlink(tmp)
        except FileNotFoundError:
            pass
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
        with os.fdopen(fd, 'wb') as fh:
            fh.write(tail)
        os.replace(tmp, LOG_FILE)
    except OSError:
        pass


def human_size(n):
    n = float(n)
    for unit in ('B', 'KB', 'MB', 'GB'):
        if n < 1024:
            return ('%d %s' % (n, unit)) if unit == 'B' else ('%.1f %s' % (n, unit))
        n /= 1024
    return '%.1f TB' % n


# ── Panel database ─────────────────────────────────────────────────────────────

def db_connect():
    con = sqlite3.connect(PANEL_DB, timeout=15)
    con.execute('PRAGMA busy_timeout = 15000')
    return con


def read_settings(keys):
    con = db_connect()
    try:
        qs = ','.join('?' * len(keys))
        rows = con.execute('SELECT key, value FROM settings WHERE key IN (%s)' % qs, list(keys)).fetchall()
        return {k: (v if v is not None else '') for k, v in rows}
    finally:
        con.close()


def write_setting(key, value):
    # settings is (key, value, category, updated_at): name the columns.
    try:
        con = db_connect()
        try:
            con.execute(
                "INSERT INTO settings (key, value, category, updated_at) VALUES (?, ?, 'backup', datetime('now')) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (key, value))
            con.commit()
        finally:
            con.close()
        return True
    except sqlite3.Error as e:
        log('could not record %s in the panel database: %s' % (key, e), 'WARN')
        return False


def panel_log(level, message, details=''):
    """A row on the panel's Logs page (source 'backup')."""
    try:
        con = db_connect()
        try:
            con.execute(
                "INSERT INTO logs (source, level, message, details, user, created_at) "
                "VALUES ('backup', ?, ?, ?, 'system', ?)",
                (level, message, (details or '')[:2000], now_str()))
            con.commit()
        finally:
            con.close()
    except sqlite3.Error as e:
        log('could not write to the panel log: %s' % e, 'WARN')


# ── Configuration ──────────────────────────────────────────────────────────────

SETTING_KEYS = ('s3_enabled', 's3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_access_key',
                's3_path_style', 's3_retention_enabled', 's3_retention_days', 's3_bandwidth_mbit',
                's3_include_system')


def normalise_prefix(p):
    return (p or '').strip().strip('/')


class Config:
    def __init__(self, d, secret):
        self.enabled = str(d.get('s3_enabled', '0')) == '1'
        self.endpoint = (d.get('s3_endpoint') or '').strip().rstrip('/')
        self.region = (d.get('s3_region') or '').strip() or 'us-east-1'
        self.bucket = (d.get('s3_bucket') or '').strip()
        self.prefix = normalise_prefix(d.get('s3_prefix'))
        self.access_key = (d.get('s3_access_key') or '').strip()
        self.path_style = (d.get('s3_path_style') or 'auto').strip() or 'auto'
        self.retention_enabled = str(d.get('s3_retention_enabled', '0')) == '1'
        self.include_system = str(d.get('s3_include_system', '1')) != '0'
        self.retention_days = _int(d.get('s3_retention_days'), 30)
        self.bandwidth_mbit = _int(d.get('s3_bandwidth_mbit'), 0)
        self.secret = secret or ''

    def errors(self):
        errs = []
        if self.endpoint:
            port_ok = True
            try:
                port = urlsplit(self.endpoint).port
                port_ok = port is None or 1 <= port <= 65535
            except ValueError:
                port_ok = False
            if not ENDPOINT_RE.fullmatch(self.endpoint) or not port_ok:
                errs.append('Endpoint must be a URL like https://host or https://host:port, with no path.')
        if not REGION_RE.fullmatch(self.region):
            errs.append('Region must be lowercase letters, digits and dashes (e.g. us-east-1, or auto for R2).')
        if not BUCKET_RE.fullmatch(self.bucket):
            errs.append('Bucket name must be 3-63 characters: letters, digits, dots, dashes, underscores.')
        if self.prefix:
            segs = self.prefix.split('/')
            if len(self.prefix) > 200 or any(s in ('.', '..') or not PREFIX_SEG_RE.fullmatch(s) for s in segs):
                errs.append('Folder may use letters, digits, ".", "_", "-" and "/" between folders.')
        if not KEY_ID_RE.fullmatch(self.access_key):
            errs.append('Access key ID is missing or contains spaces.')
        if not self.secret:
            errs.append('No secret access key is saved.')
        elif not SECRET_RE.fullmatch(self.secret):
            errs.append('Secret access key must be 8-256 printable characters with no spaces.')
        if self.path_style not in ('auto', 'path', 'virtual'):
            errs.append('Addressing must be auto, path or virtual.')
        if not 1 <= self.retention_days <= 3650:
            errs.append('Retention must be between 1 and 3650 days.')
        if not 0 <= self.bandwidth_mbit <= 100000:
            errs.append('Speed limit must be between 0 (no limit) and 100000 Mbit/s.')
        return errs

    def key_for(self, rel):
        return (self.prefix + '/' + rel) if self.prefix else rel

    def list_prefix(self):
        return (self.prefix + '/') if self.prefix else ''

    def destination_id(self):
        ident = '%s|%s|%s' % (self.endpoint or 'aws', self.bucket, self.prefix)
        return hashlib.sha256(ident.encode()).hexdigest()[:16]

    def describe(self):
        where = self.endpoint or ('AWS S3, %s' % self.region)
        return 's3://%s/%s (%s)' % (self.bucket, self.prefix, where)


def _int(v, default):
    try:
        return int(str(v if v not in (None, '') else default).strip())
    except ValueError:
        return -1


def read_secret():
    try:
        with open(SECRET_FILE, 'r') as fh:
            return fh.read().strip()
    except OSError:
        return ''


def load_saved_config():
    return Config(read_settings(SETTING_KEYS), read_secret())


def server_id():
    """This server's id, tagged on every upload; retention deletes only objects
    carrying it. Root-only file first; after a restore of the panel database
    onto new hardware the id is adopted from there, so the server's earlier
    uploads stay its own."""
    try:
        with open(SERVER_ID_FILE) as fh:
            sid = fh.read().strip()
        if SERVER_ID_RE.fullmatch(sid):
            return sid
    except OSError:
        pass
    ensure_state_dir()
    try:
        sid = read_settings(('s3_server_id',)).get('s3_server_id', '')
    except sqlite3.Error:
        sid = ''
    if not SERVER_ID_RE.fullmatch(sid or ''):
        sid = secrets.token_hex(16)
    try:
        fd = os.open(SERVER_ID_FILE, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as fh:
            fh.write(sid + '\n')
    except FileExistsError:
        with open(SERVER_ID_FILE) as fh:
            sid = fh.read().strip()
    write_setting('s3_server_id', sid)
    return sid


# ── AWS Signature Version 4 ────────────────────────────────────────────────────

_UNRESERVED = frozenset(b'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~')


def uri_encode(s, keep_slash):
    """RFC 3986 encoding as SigV4 requires. S3 paths are encoded once, not twice."""
    out = []
    for b in s.encode('utf-8'):
        if b in _UNRESERVED or (keep_slash and b == 0x2F):
            out.append(chr(b))
        else:
            out.append('%%%02X' % b)
    return ''.join(out)


def canonical_query(params):
    pairs = sorted((uri_encode(k, False), uri_encode(v, False)) for k, v in params)
    return '&'.join('%s=%s' % kv for kv in pairs)


def _hmac(key, msg):
    return hmac.new(key, msg.encode('utf-8'), hashlib.sha256).digest()


def sigv4_authorization(method, canonical_uri, params, headers, payload_hash,
                        access_key, secret_key, region, amz_date, service='s3'):
    """headers: dict of lower-case name -> value; every one of them is signed."""
    names = sorted(headers)
    canonical_headers = ''.join('%s:%s\n' % (n, ' '.join(str(headers[n]).split())) for n in names)
    signed_headers = ';'.join(names)
    creq = '\n'.join([method, canonical_uri, canonical_query(params), canonical_headers,
                      signed_headers, payload_hash])
    date = amz_date[:8]
    scope = '%s/%s/%s/aws4_request' % (date, region, service)
    sts = '\n'.join(['AWS4-HMAC-SHA256', amz_date, scope,
                     hashlib.sha256(creq.encode('utf-8')).hexdigest()])
    k = _hmac(('AWS4' + secret_key).encode('utf-8'), date)
    k = _hmac(k, region)
    k = _hmac(k, service)
    k = _hmac(k, 'aws4_request')
    sig = hmac.new(k, sts.encode('utf-8'), hashlib.sha256).hexdigest()
    return 'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s' % (
        access_key, scope, signed_headers, sig)


# ── HTTP / S3 client ───────────────────────────────────────────────────────────

def _local(tag):
    return tag.rsplit('}', 1)[-1]


def parse_xml(body):
    # CompleteMultipartUpload pads its response with whitespace while it works.
    text = body.strip()
    if not text:
        return None
    try:
        return ET.fromstring(text)
    except ET.ParseError:
        return None


def xml_children(elem):
    return {_local(c.tag): (c.text or '') for c in list(elem)}


def error_from_response(op, status, headers, body):
    extra = {}
    root = parse_xml(body) if body else None
    code = message = ''
    if root is not None and _local(root.tag) == 'Error':
        extra = xml_children(root)
        code, message = extra.get('Code', ''), extra.get('Message', '')
    for h in ('x-amz-bucket-region', 'retry-after'):
        if headers.get(h):
            extra[h] = headers[h]
    if not code:
        code = {301: 'PermanentRedirect', 403: 'AccessDenied', 404: 'NotFound'}.get(status, '')
    return S3Error(op, status, code, message, extra)


def _is_ip(host):
    for fam in (socket.AF_INET, socket.AF_INET6):
        try:
            socket.inet_pton(fam, host)
            return True
        except OSError:
            pass
    return False


def _is_this_machine(fam, sockaddr):
    """An address is ours if a socket can bind to it."""
    s = socket.socket(fam, socket.SOCK_STREAM)
    try:
        s.bind((sockaddr[0], 0) if fam == socket.AF_INET else (sockaddr[0], 0, 0, sockaddr[3]))
        return True
    except OSError:
        return False
    finally:
        s.close()


class S3Client:
    def __init__(self, cfg):
        self.cfg = cfg
        if cfg.endpoint:
            u = urlsplit(cfg.endpoint)
            self.scheme = u.scheme
            host = u.hostname or ''
            port = u.port
            # bucket.<IP address> can never resolve, so an IP endpoint is
            # always addressed path-style, as the AWS SDKs do.
            style = 'virtual' if (cfg.path_style == 'virtual' and not _is_ip(host)) else 'path'
        else:
            self.scheme = 'https'
            host = 's3.%s.amazonaws.com' % cfg.region
            port = None
            # Virtual-hosted is the AWS default, but a name that is not a valid
            # DNS label (dots break the *.s3 certificate; legacy upper case or
            # underscores do not resolve) has to go path-style.
            dns_ok = DNS_BUCKET_RE.fullmatch(cfg.bucket) is not None
            style = 'virtual' if (cfg.path_style != 'path' and dns_ok) else 'path'
        self.style = style
        if style == 'virtual':
            host = '%s.%s' % (cfg.bucket, host)
        self.host_only = host.strip('[]')
        default_port = 443 if self.scheme == 'https' else 80
        self.port = port or default_port
        h = ('[%s]' % self.host_only) if ':' in self.host_only else self.host_only
        self.host_header = h if self.port == default_port else '%s:%d' % (h, self.port)
        self.base_path = '' if style == 'virtual' else '/' + uri_encode(cfg.bucket, False)
        self.tls = ssl.create_default_context()
        self.tls.minimum_version = ssl.TLSVersion.TLSv1_2
        self.rate = cfg.bandwidth_mbit * 125000 if cfg.bandwidth_mbit > 0 else 0
        self._addr = None

    def endpoint_url(self):
        return '%s://%s%s' % (self.scheme, self.host_header, self.base_path)

    def _resolve(self):
        try:
            return socket.getaddrinfo(self.host_only, self.port, 0, socket.SOCK_STREAM)
        except socket.gaierror as e:
            raise S3Error('connect', 0, 'DNS', '%s: %s' % (self.host_only, e.strerror or e))

    def _check_plain_http(self, infos):
        if self.scheme != 'http' or ALLOW_LOCAL_HTTP:
            return
        for fam, _, _, _, sa in infos:
            ip = ipaddress.ip_address(sa[0])
            if not any(ip in net for net in PRIVATE_NETS) or _is_this_machine(fam, sa):
                raise S3Error('connect', 0, 'Config',
                              'plain http:// is only allowed for a storage server on your private network '
                              '(not this server, where any local account could stand in for it) — use https://')

    def open_socket(self):
        """Connect, remembering the first address that worked.

        Trying every address of every family per request would make a host with
        broken IPv6 pay the full connect timeout on every part of every upload.
        Connecting to the exact address that was checked also means DNS cannot
        be used to swing a plain-http endpoint somewhere else afterwards.
        """
        if self._addr is not None:
            fam, sa = self._addr
            s = socket.socket(fam, socket.SOCK_STREAM)
            s.settimeout(CONNECT_TIMEOUT)
            try:
                s.connect(sa)
                return s
            except OSError:
                s.close()
                self._addr = None
        infos = self._resolve()
        self._check_plain_http(infos)
        last = None
        for fam, typ, proto, _, sa in infos:
            s = socket.socket(fam, typ, proto)
            s.settimeout(CONNECT_TIMEOUT)
            try:
                s.connect(sa)
                self._addr = (fam, sa)
                return s
            except OSError as e:
                last = e
                s.close()
        raise S3Error('connect', 0, 'Network', 'could not connect to %s port %d (%s)' % (
            self.host_only, self.port, last or 'no address'))

    def _connection(self, timeout):
        sock = self.open_socket()
        sock.settimeout(timeout)
        if self.scheme == 'https':
            try:
                sock = self.tls.wrap_socket(sock, server_hostname=self.host_only)
            except ssl.SSLCertVerificationError as e:
                sock.close()
                raise S3Error('connect', 0, 'TLSCertificate', getattr(e, 'verify_message', '') or str(e))
            except (ssl.SSLError, OSError) as e:
                sock.close()
                raise S3Error('connect', 0, 'Network', 'TLS handshake failed: %s' % e)
            conn = http.client.HTTPSConnection(self.host_only, self.port, timeout=timeout, context=self.tls)
        else:
            conn = http.client.HTTPConnection(self.host_only, self.port, timeout=timeout)
        conn.sock = sock
        return conn

    def object_path(self, key):
        if key:
            return self.base_path + '/' + uri_encode(key, True)
        return self.base_path or '/'

    def request(self, op, method, key='', params=(), headers=None, body=b'', body_file=None,
                timeout=IO_TIMEOUT, progress=None, even_if_stopping=False):
        """Send one signed request and return (status, headers, body).

        body_file=(fd, offset, length, sha256_hex) streams that byte range of an
        open descriptor instead of sending `body`.
        Raises S3Error for anything other than a 2xx answer.
        """
        if STOP_REQUESTED and not even_if_stopping:
            raise Interrupted()
        params = list(params)
        path = self.object_path(key)
        amz_date = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        if body_file is not None:
            length, payload_hash = body_file[2], body_file[3]
        else:
            length, payload_hash = len(body), hashlib.sha256(body).hexdigest()
        signed = {'host': self.host_header, 'x-amz-date': amz_date, 'x-amz-content-sha256': payload_hash}
        for k, v in (headers or {}).items():
            signed[k.lower()] = v
        auth = sigv4_authorization(method, path, params, signed, payload_hash,
                                   self.cfg.access_key, self.cfg.secret, self.cfg.region, amz_date)
        qs = canonical_query(params)
        target = path + ('?' + qs if qs else '')

        conn = self._connection(timeout)
        try:
            try:
                conn.putrequest(method, target, skip_host=True, skip_accept_encoding=True)
                for k, v in signed.items():
                    conn.putheader(k, v)
                conn.putheader('Authorization', auth)
                conn.putheader('User-Agent', 'iNetPanel-s3-backup/1')
                if method in ('PUT', 'POST') or length:
                    conn.putheader('Content-Length', str(length))
                conn.endheaders()
                if body_file is not None:
                    self._send_range(conn, body_file, progress)
                elif body:
                    conn.send(body)
            except (Interrupted, FileChanged):
                raise
            except (OSError, http.client.HTTPException) as e:
                # The server may have answered early (a 403, say) and closed
                # before taking the whole body. Prefer its answer to a bare
                # "broken pipe", which would otherwise look like a network fault.
                early = None
                try:
                    conn.sock.settimeout(10)
                    resp = conn.getresponse()
                    early = error_from_response(op, resp.status, {k.lower(): v for k, v in resp.getheaders()},
                                                resp.read(MAX_RESPONSE))
                except Exception:
                    pass
                if early is not None and early.status >= 300:
                    raise early
                raise S3Error(op, 0, 'Network', str(e) or e.__class__.__name__)
            try:
                resp = conn.getresponse()
                data = resp.read(MAX_RESPONSE + 1)
            except (OSError, http.client.HTTPException) as e:
                raise S3Error(op, 0, 'Network', str(e) or e.__class__.__name__)
            if len(data) > MAX_RESPONSE:
                raise S3Error(op, resp.status, 'BadResponse', 'response larger than %s' % human_size(MAX_RESPONSE))
            hdrs = {k.lower(): v for k, v in resp.getheaders()}
            if not (200 <= resp.status < 300):
                raise error_from_response(op, resp.status, hdrs, data)
            return resp.status, hdrs, data
        finally:
            try:
                conn.close()
            except Exception:
                pass

    def _send_range(self, conn, body_file, progress):
        fd, offset, length, _ = body_file
        sent = 0
        t0 = last_tick = time.monotonic()
        while sent < length:
            if STOP_REQUESTED:
                raise Interrupted()
            chunk = os.pread(fd, min(READ_CHUNK, length - sent), offset + sent)
            if not chunk:
                raise FileChanged('archive shrank while uploading')
            conn.send(chunk)
            sent += len(chunk)
            if self.rate:
                # Speed limit: never be ahead of the allowed rate.
                ahead = sent / self.rate - (time.monotonic() - t0)
                if ahead > 0:
                    time.sleep(ahead)
            if progress and time.monotonic() - last_tick >= 2:
                progress(sent)
                last_tick = time.monotonic()
        if progress:
            progress(sent)

    # ── operations ──
    def put_object(self, key, fd, size, sha, headers=None, progress=None):
        _, h, _ = self.request('PutObject', 'PUT', key, headers=headers,
                               body_file=(fd, 0, size, sha), progress=progress)
        return h.get('etag', '')

    def put_bytes(self, key, data, headers=None, timeout=60):
        _, h, _ = self.request('PutObject', 'PUT', key, headers=headers, body=data, timeout=timeout)
        return h.get('etag', '')

    def head_object(self, key, timeout=60):
        _, h, _ = self.request('HeadObject', 'HEAD', key, timeout=timeout)
        return h

    def delete_object(self, key, timeout=60):
        self.request('DeleteObject', 'DELETE', key, timeout=timeout)

    def create_multipart(self, key, headers=None):
        _, _, data = self.request('CreateMultipartUpload', 'POST', key, params=[('uploads', '')],
                                  headers=headers, timeout=60)
        root = parse_xml(data)
        upload_id = xml_children(root).get('UploadId', '') if root is not None else ''
        if not upload_id:
            raise S3Error('CreateMultipartUpload', 200, 'BadResponse', 'no UploadId in the response')
        return upload_id

    def upload_part(self, key, upload_id, number, fd, offset, length, sha, progress=None):
        _, h, _ = self.request('UploadPart', 'PUT', key,
                               params=[('partNumber', str(number)), ('uploadId', upload_id)],
                               body_file=(fd, offset, length, sha), progress=progress)
        etag = h.get('etag', '')
        if not etag:
            raise S3Error('UploadPart', 200, 'BadResponse', 'no ETag for part %d' % number)
        return etag

    def complete_multipart(self, key, upload_id, parts):
        xml = ['<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">']
        for n, etag in parts:
            et = etag.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
            xml.append('<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>' % (n, et))
        xml.append('</CompleteMultipartUpload>')
        _, _, data = self.request('CompleteMultipartUpload', 'POST', key, params=[('uploadId', upload_id)],
                                  headers={'content-type': 'application/xml'}, body=''.join(xml).encode(),
                                  timeout=COMPLETE_TIMEOUT)
        root = parse_xml(data)
        if root is not None and _local(root.tag) == 'Error':
            # HTTP 200 with the failure in the body — documented S3 behaviour.
            ch = xml_children(root)
            code = ch.get('Code', 'InternalError')
            err = S3Error('CompleteMultipartUpload', 200, code, ch.get('Message', ''), ch)
            if not (err.transient or err.fatal):
                err.code = 'InternalError'      # treat as retryable
            raise err
        if root is None or _local(root.tag) != 'CompleteMultipartUploadResult':
            raise S3Error('CompleteMultipartUpload', 200, 'BadResponse', 'unexpected response body')
        return xml_children(root).get('ETag', '')

    def abort_multipart(self, key, upload_id):
        try:
            self.request('AbortMultipartUpload', 'DELETE', key, params=[('uploadId', upload_id)],
                         timeout=60, even_if_stopping=True)
        except S3Error as e:
            if e.status == 404:          # NoSuchUpload: already gone
                return
            raise

    def list_objects(self, prefix, max_keys=1000, limit_pages=None):
        """Objects directly under `prefix` (delimiter '/').
        Returns ([(key, size, last_modified)], provider_time or None)."""
        out, token, pages, seen, provider_now = [], None, 0, set(), None
        while True:
            params = [('list-type', '2'), ('prefix', prefix), ('delimiter', '/'), ('max-keys', str(max_keys))]
            if token:
                params.append(('continuation-token', token))
            _, hdrs, data = self.request('ListObjectsV2', 'GET', '', params=params, timeout=60)
            if provider_now is None and hdrs.get('date'):
                try:
                    provider_now = email.utils.parsedate_to_datetime(hdrs['date']).timestamp()
                except (TypeError, ValueError, OverflowError):
                    pass
            root = parse_xml(data)
            if root is None or _local(root.tag) != 'ListBucketResult':
                raise S3Error('ListObjectsV2', 200, 'BadResponse', 'unreadable listing')
            truncated, token = False, None
            for el in list(root):
                name = _local(el.tag)
                if name == 'Contents':
                    ch = xml_children(el)
                    try:
                        size = int(ch.get('Size', '0'))
                    except ValueError:
                        size = 0
                    out.append((ch.get('Key', ''), size, ch.get('LastModified', '')))
                elif name == 'IsTruncated':
                    truncated = (el.text or '').strip().lower() == 'true'
                elif name == 'NextContinuationToken':
                    token = (el.text or '').strip()
            pages += 1
            if not truncated or not token or (limit_pages and pages >= limit_pages):
                return out, provider_now
            if token in seen or pages >= MAX_LIST_PAGES:
                raise S3Error('ListObjectsV2', 200, 'BadResponse', 'the listing does not end')
            seen.add(token)


def with_retries(what, fn, window=META_RETRY_WINDOW):
    """Run fn(); retry transient failures until `window` seconds have passed,
    and a transit-integrity failure once."""
    start = time.monotonic()
    attempt = 0
    integrity_retried = False
    while True:
        try:
            result = fn()
            if _STATUS is not None:
                _STATUS.note('')
            return result
        except S3Error as e:
            if e.integrity and not integrity_retried:
                integrity_retried = True
                log('%s: %s — retrying once' % (what, explain_error(e)), 'WARN')
                continue
            if not e.transient:
                raise
            attempt += 1
            delay = random.uniform(1, min(MAX_BACKOFF, 2 * 2 ** attempt))
            try:
                delay = max(delay, min(300, float(e.extra.get('retry-after', 0))))
            except ValueError:
                pass
            if time.monotonic() - start + delay > window:
                raise
            log('%s failed (%s) — retry %d in %ds' % (what, explain_error(e), attempt, delay), 'WARN')
            if _STATUS is not None:
                _STATUS.note('Retrying: %s' % explain_error(e))
            time.sleep(delay)


# ── State (root-only): what is in the bucket, what failed, what is unfinished ──

def ensure_state_dir():
    if os.path.islink(STATE_DIR):
        raise RuntimeError('%s is a symlink — refusing to use it' % STATE_DIR)
    os.makedirs(STATE_DIR, mode=0o700, exist_ok=True)
    st = os.lstat(STATE_DIR)
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != os.geteuid():
        raise RuntimeError('%s is not a directory owned by root — refusing to use it' % STATE_DIR)
    os.chmod(STATE_DIR, 0o700)


def load_state(dest_id):
    try:
        with open(STATE_FILE, 'r') as fh:
            st = json.load(fh)
        if not isinstance(st, dict):
            raise ValueError
    except (OSError, ValueError):
        st = {}
    st.setdefault('version', 2)
    if not isinstance(st.get('inflight'), list):
        st['inflight'] = []
    if st.get('destination') != dest_id:
        # New bucket/folder/endpoint: nothing is known to be there.
        st['destination'] = dest_id
        st['uploaded'], st['owned'], st['failed'], st['series_up'] = {}, {}, {}, {}
    for k in ('uploaded', 'owned', 'failed', 'series_up'):
        if not isinstance(st.get(k), dict):
            st[k] = {}
    return st


def save_state(st):
    tmp = STATE_FILE + '.tmp'
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as fh:
        json.dump(st, fh, indent=1, sort_keys=True)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, STATE_FILE)


def local_path(rel):
    if rel.startswith('deleted/'):
        return os.path.join(FINAL_DIR, rel[len('deleted/'):])
    return os.path.join(BACKUP_DIR, rel)


def prune_state(state):
    """Forget archives local retention has already removed."""
    changed = False
    for k in ('uploaded', 'failed'):
        keep = {rel: v for rel, v in state[k].items() if os.path.exists(local_path(rel))}
        if len(keep) != len(state[k]):
            state[k] = keep
            changed = True
    if changed:
        save_state(state)


# ── Status shown on the Backups page (settings keys s3_status, s3_heartbeat) ───

def previous_status():
    try:
        raw = read_settings(('s3_status',)).get('s3_status', '')
        st = json.loads(raw) if raw else {}
        return st if isinstance(st, dict) else {}
    except (sqlite3.Error, ValueError):
        return {}


class Status:
    def __init__(self, cfg):
        t = int(time.time())
        prev = previous_status()
        self.prev = prev
        self.d = {
            'state': 'running', 'started': now_str(), 'started_ts': t, 'finished': '',
            'heartbeat': now_str(), 'heartbeat_ts': t, 'uploaded': 0, 'failed': 0, 'bytes': 0,
            'pending': 0, 'current': None, 'note': '', 'message': 'Uploading…', 'errors': [],
            'retention': None, 'retry': False, 'destination': cfg.describe(),
            'last_success': prev.get('last_success', ''), 'last_success_ts': prev.get('last_success_ts', 0),
        }
        self._last_write = 0.0
        self._stop = threading.Event()
        self._thread = None

    def write(self, force=True):
        if not force and time.monotonic() - self._last_write < PROGRESS_EVERY:
            return
        self.d['heartbeat'] = now_str()
        self.d['heartbeat_ts'] = int(time.time())
        self._last_write = time.monotonic()
        write_setting('s3_status', json.dumps(self.d))

    def note(self, text):
        if self.d.get('note') != text:
            self.d['note'] = text
            self.write()

    def error(self, text):
        self.d['errors'] = (self.d['errors'] + [text])[-5:]

    def start_heartbeat(self):
        """Liveness from its own thread, so a run that is waiting out an outage
        or a slow Complete never looks dead to the panel."""
        def beat():
            while not self._stop.wait(HEARTBEAT_EVERY):
                write_setting('s3_heartbeat', str(int(time.time())))
        write_setting('s3_heartbeat', str(int(time.time())))
        self._thread = threading.Thread(target=beat, daemon=True)
        self._thread.start()

    def stop_heartbeat(self):
        self._stop.set()


def write_simple_status(state, message, cfg=None, retry=False):
    t = int(time.time())
    prev = previous_status()
    d = {'state': state, 'message': message, 'started': now_str(), 'started_ts': t, 'finished': now_str(),
         'heartbeat': now_str(), 'heartbeat_ts': t, 'uploaded': 0, 'failed': 0, 'bytes': 0, 'pending': 0,
         'current': None, 'note': '', 'errors': [], 'retention': None, 'retry': retry,
         'destination': cfg.describe() if cfg else '',
         'last_success': prev.get('last_success', ''), 'last_success_ts': prev.get('last_success_ts', 0)}
    write_setting('s3_status', json.dumps(d))


# ── Finding work ───────────────────────────────────────────────────────────────

def scan_local(cfg):
    """Every local archive this feature could upload: {rel: info}."""
    found = {}
    for directory, sub in ((FINAL_DIR, 'deleted/'), (BACKUP_DIR, '')):
        try:
            entries = list(os.scandir(directory))
        except OSError:
            continue
        for e in entries:
            m = ARCHIVE_RE.fullmatch(e.name)
            if not m:
                continue
            try:
                if not e.is_file(follow_symlinks=False):
                    continue
                st = e.stat(follow_symlinks=False)
            except OSError:
                continue
            if st.st_size <= 0:
                continue
            found[sub + e.name] = {
                'path': e.path, 'series': m.group(1), 'date': m.group(2), 'final': bool(sub),
                'size': st.st_size, 'mtime': int(st.st_mtime),
                'version': '%s|%d|%d' % (sub + e.name, st.st_size, int(st.st_mtime)),
            }
    return found


def newest_per_series(local):
    newest = {}
    for rel, a in local.items():
        if a['final']:
            continue
        cur = newest.get(a['series'])
        if cur is None or (a['date'], a['mtime']) > (local[cur]['date'], local[cur]['mtime']):
            newest[a['series']] = rel
    return newest


def is_uploaded(state, rel, a):
    done = state['uploaded'].get(rel)
    return bool(done) and done.get('size') == a['size'] and int(done.get('mtime', -1)) == a['mtime']


def discover(cfg, state, attempts, manual=False, now=None):
    """Archives due for upload, best first. Returns [(rel, info)].

    Eligible: the newest archive of each account, the newest server
    configuration (unless excluded) and every pre-deletion archive. Order:
    pre-deletion archives (the only copy of a deleted account), then the
    server configuration, then the account that has waited longest, smallest
    first. A failed archive waits out its back-off unless this is Upload Now.
    """
    now = now or time.time()
    local = scan_local(cfg)
    eligible = set(newest_per_series(local).values()) | {r for r, a in local.items() if a['final']}
    due = []
    for rel in eligible:
        a = local[rel]
        if a['series'] == SYSTEM_SERIES and not a['final'] and not cfg.include_system:
            continue
        if is_uploaded(state, rel, a):
            continue
        if attempts.get(a['version'], 0) >= FILE_ATTEMPTS_PER_RUN:
            continue
        f = state['failed'].get(rel)
        if f and f.get('version') == a['version'] and f.get('next_at', 0) > now and not manual:
            continue
        kind = 0 if a['final'] else (1 if a['series'] == SYSTEM_SERIES else 2)
        waited = 0 if a['final'] else state['series_up'].get(a['series'], 0)
        due.append(((kind, -a['mtime'] if a['final'] else waited, a['size']), rel, a))
    due.sort(key=lambda t: t[0])
    return [(rel, a) for _, rel, a in due]


# ── Uploading one archive ──────────────────────────────────────────────────────

def choose_part_size(size, preferred=None):
    ps = max(preferred or PART_SIZE, MIN_PART_SIZE)
    need = math.ceil(size / MAX_PARTS)
    if ps < need:
        ps = math.ceil(need / MIB) * MIB
    return ps


def sha256_range(fd, offset, length):
    h = hashlib.sha256()
    done = 0
    while done < length:
        if STOP_REQUESTED:
            raise Interrupted()
        chunk = os.pread(fd, min(1 * MIB, length - done), offset + done)
        if not chunk:
            raise FileChanged('archive shrank while reading')
        h.update(chunk)
        done += len(chunk)
    return h.hexdigest()


def check_existing(s3, key, size, mtime, sid):
    """Look at what is already in the bucket under this name.

    'same'     — this exact archive is there (a lost state file, or a switch
                 back to an earlier destination, costs one HEAD, not a re-send)
    'free'     — nothing there, ours (an older copy), or the key cannot read
    Raises FolderConflict when another server's backup has the name: the
    folder is shared, and overwriting would destroy that server's copy.
    """
    try:
        h = with_retries('check %s' % key, lambda: s3.head_object(key))
    except S3Error as e:
        if e.fatal and e.status != 403:
            raise
        return 'free'
    owner = h.get('x-amz-meta-inetpanel-server', '')
    if owner and owner != sid:
        raise FolderConflict(owner)
    if not owner:
        raise FolderConflict('')
    if h.get('content-length') == str(size) and h.get('x-amz-meta-inetpanel-mtime') == str(int(mtime)):
        return 'same'
    return 'free'


def upload_archive(s3, cfg, state, rel, a, status, sid):
    """Upload one archive. Returns (size, note, sent) — sent is False when the
    bucket already had it."""
    key = cfg.key_for(rel)
    fd = os.open(a['path'], os.O_RDONLY | os.O_NOFOLLOW)
    try:
        st = os.fstat(fd)
        size, mtime = st.st_size, st.st_mtime
        if check_existing(s3, key, size, mtime, sid) == 'same':
            record_uploaded(state, rel, a, key, size, mtime)
            return size, 'already in the bucket', False
        meta = {'content-type': 'application/gzip',
                'x-amz-meta-inetpanel-server': sid,
                'x-amz-meta-inetpanel-host': socket.gethostname()[:200],
                'x-amz-meta-inetpanel-mtime': str(int(mtime))}
        part_size = choose_part_size(size)
        status.d['current'] = {'file': rel, 'done': 0, 'size': size}
        status.write()

        def progress_at(base):
            def cb(n):
                status.d['current']['done'] = base + n
                status.write(force=False)
            return cb

        if size <= part_size:
            sha = sha256_range(fd, 0, size)
            with_retries('upload %s' % rel,
                         lambda: s3.put_object(key, fd, size, sha, headers=meta, progress=progress_at(0)),
                         window=DATA_RETRY_WINDOW)
        else:
            multipart_upload(s3, state, key, fd, size, part_size, meta, rel, progress_at)

        after = os.fstat(fd)
        if after.st_size != size or after.st_mtime != mtime:
            raise FileChanged('archive was modified during the upload')

        verified = 'size verified'
        try:
            h = with_retries('verify %s' % rel, lambda: s3.head_object(key))
            remote = int(h.get('content-length', '-1'))
            if remote != size:
                raise S3Error('HeadObject', 200, 'SizeMismatch', 'bucket has %d bytes, archive is %d' % (remote, size))
        except S3Error as e:
            if e.status == 403:
                # A write-only key cannot HEAD. Every byte was still checked by
                # the provider against the signed SHA-256 of its request.
                verified = 'not re-checked: the key cannot read objects'
            else:
                raise
        record_uploaded(state, rel, a, key, size, mtime)
        return size, verified, True
    finally:
        os.close(fd)


def record_uploaded(state, rel, a, key, size, mtime):
    state['uploaded'][rel] = {'size': size, 'mtime': int(mtime), 'key': key, 'at': now_str()}
    state['owned'][key] = int(time.time())
    state['failed'].pop(rel, None)
    if not a['final']:
        state['series_up'][a['series']] = int(time.time())
    save_state(state)


def multipart_upload(s3, state, key, fd, size, part_size, meta, rel, progress_at):
    upload_id = with_retries('start upload of %s' % rel, lambda: s3.create_multipart(key, headers=meta))
    state['inflight'].append({'key': key, 'upload_id': upload_id, 'destination': state['destination'],
                              'started': now_str(), 'started_ts': int(time.time())})
    save_state(state)
    nparts = math.ceil(size / part_size)
    try:
        parts = []
        for n in range(1, nparts + 1):
            off = (n - 1) * part_size
            ln = min(part_size, size - off)
            sha = sha256_range(fd, off, ln)
            etag = with_retries('%s part %d/%d' % (rel, n, nparts),
                                lambda: s3.upload_part(key, upload_id, n, fd, off, ln, sha,
                                                       progress=progress_at(off)),
                                window=DATA_RETRY_WINDOW)
            parts.append((n, etag))
        complete_with_check(s3, key, upload_id, parts, size, rel)
    except BaseException:
        log('aborting the unfinished multipart upload of %s' % rel, 'WARN')
        try:
            with_retries('abort upload of %s' % rel, lambda: s3.abort_multipart(key, upload_id))
            forget_inflight(state, upload_id)
        except BaseException as ae:
            # Still listed in state['inflight']: the next run aborts it.
            log('could not abort upload %s of %s: %s' % (upload_id, rel, ae), 'ERROR')
        raise
    forget_inflight(state, upload_id)


def complete_with_check(s3, key, upload_id, parts, size, rel):
    attempt = 0
    start = time.monotonic()
    while True:
        try:
            return s3.complete_multipart(key, upload_id, parts)
        except S3Error as e:
            # A Complete whose answer was lost succeeded anyway; the retry then
            # sees NoSuchUpload. The object being there at full size settles it.
            if attempt > 0 and (e.code == 'NoSuchUpload' or e.status == 404):
                try:
                    if int(s3.head_object(key).get('content-length', '-1')) == size:
                        return ''
                except S3Error:
                    pass
            if not e.transient or time.monotonic() - start > DATA_RETRY_WINDOW:
                raise
            attempt += 1
            delay = random.uniform(1, min(MAX_BACKOFF, 2 * 2 ** attempt))
            log('finishing %s failed (%s) — retrying in %ds' % (rel, explain_error(e), delay), 'WARN')
            if _STATUS is not None:
                _STATUS.note('Retrying: %s' % explain_error(e))
            time.sleep(delay)


def forget_inflight(state, upload_id):
    state['inflight'] = [x for x in state['inflight'] if x.get('upload_id') != upload_id]
    save_state(state)


def abort_leftovers(s3, state):
    """Abort multipart uploads a killed earlier run left behind (billed until aborted)."""
    keep = []
    for x in state['inflight']:
        if x.get('destination') != state['destination']:
            log('cannot abort old upload %s of %s: the destination has changed since — '
                'a bucket lifecycle rule has to clean it up' % (x.get('upload_id'), x.get('key')), 'WARN')
            continue
        try:
            s3.abort_multipart(x['key'], x['upload_id'])
            log('aborted an unfinished upload left by an earlier run: %s' % x['key'])
        except S3Error as e:
            if time.time() - int(x.get('started_ts', 0)) > INFLIGHT_EXPIRE:
                log('giving up on aborting upload %s of %s (%s) — set an "abort incomplete multipart '
                    'uploads" lifecycle rule on the bucket' % (x.get('upload_id'), x['key'], explain_error(e)),
                    'WARN')
                continue
            log('could not abort the earlier upload of %s: %s' % (x['key'], explain_error(e)), 'WARN')
            keep.append(x)
    if keep != state['inflight']:
        state['inflight'] = keep
        save_state(state)


def note_failure(state, rel, a, why, conflict=False):
    f = state['failed'].get(rel)
    fails = (f.get('fails', 0) + 1) if f and f.get('version') == a['version'] else 1
    wait = CONFLICT_BACKOFF if conflict else OBJECT_BACKOFF[min(fails, len(OBJECT_BACKOFF)) - 1]
    state['failed'][rel] = {'version': a['version'], 'fails': fails, 'next_at': int(time.time()) + wait,
                            'error': why[:300]}
    save_state(state)


# ── Retention ──────────────────────────────────────────────────────────────────

def parse_last_modified(s):
    for fmt in ('%Y-%m-%dT%H:%M:%S.%fZ', '%Y-%m-%dT%H:%M:%SZ'):
        try:
            return calendar.timegm(time.strptime(s, fmt))
        except (ValueError, TypeError):
            pass
    return None


def retention_cutoffs(days, now=None):
    now = time.time() if now is None else now
    cutoff_date = (datetime.date.fromtimestamp(now) - datetime.timedelta(days=days)).isoformat()
    return cutoff_date, now - days * 86400


def plan_retention(objects, list_prefix, days, now=None, provider_now=None, hold=()):
    """Decide what retention deletes. Pure: no network.

    objects: [(key, size, last_modified)] directly under list_prefix, already
    limited to this server's own uploads. hold: account names whose newest
    local archive has not reached the bucket yet — nothing of theirs is
    deleted. Returns (delete, keep) — lists of (key, reason).
    """
    cutoff_date, _ = retention_cutoffs(days, now)
    # The upload time is judged by the provider's clock when it gave us one,
    # so a wrong local clock cannot make fresh uploads look old.
    cutoff_ts = (provider_now if provider_now else (time.time() if now is None else now)) - days * 86400
    tomorrow = (datetime.date.fromtimestamp(time.time() if now is None else now)
                + datetime.timedelta(days=1)).isoformat()
    groups, keep, delete = {}, [], []
    for key, _size, lm in objects:
        if not key.startswith(list_prefix):
            continue
        m = ARCHIVE_RE.fullmatch(key[len(list_prefix):])
        if not m:
            continue                      # not something this script uploaded
        groups.setdefault(m.group(1), []).append((m.group(2), parse_last_modified(lm), key))
    for series, items in groups.items():
        items.sort(key=lambda t: (t[0], t[1] or 0), reverse=True)
        # A future-dated name (a clock that was wrong once) never counts as
        # the newest, or it would shield itself and expose the real newest.
        real = [t for t in items if t[0] <= tomorrow] or items
        newest = real[0]
        keep.append((newest[2], 'newest backup of %s' % series))
        for date, ts, key in items:
            if key == newest[2]:
                continue
            if series in hold:
                keep.append((key, 'newest local backup of %s is not uploaded yet' % series))
            elif ts is None:
                keep.append((key, 'unreadable upload time'))
            elif date < cutoff_date and ts < cutoff_ts:
                delete.append((key, 'backup from %s' % date))
            else:
                keep.append((key, 'within %d days' % days))
    delete.sort(key=lambda kv: kv[0])
    return delete, keep


def owned_objects(s3, cfg, state, objects, sid):
    """Keep only objects this server uploaded: known from the state ledger, or
    proven by the server id on the object (one HEAD each, then remembered)."""
    mine, foreign, unknown = [], 0, 0
    lp = cfg.list_prefix()
    present = set()
    for key, size, lm in objects:
        if not key.startswith(lp) or not ARCHIVE_RE.fullmatch(key[len(lp):]):
            continue
        present.add(key)
        if key in state['owned']:
            mine.append((key, size, lm))
            continue
        try:
            owner = s3.head_object(key).get('x-amz-meta-inetpanel-server', '')
        except S3Error:
            unknown += 1
            continue
        if owner == sid:
            state['owned'][key] = int(time.time())
            mine.append((key, size, lm))
        else:
            foreign += 1
    state['owned'] = {k: v for k, v in state['owned'].items() if k in present}
    save_state(state)
    return mine, foreign, unknown


def run_retention(s3, cfg, state, sid, dry_run=False):
    objects, provider_now = with_retries('list bucket', lambda: s3.list_objects(cfg.list_prefix()))
    mine, foreign, unknown = owned_objects(s3, cfg, state, objects, sid)
    local = scan_local(cfg)
    hold = {local[rel]['series'] for rel in newest_per_series(local).values()
            if not is_uploaded(state, rel, local[rel])
            and (cfg.include_system or local[rel]['series'] != SYSTEM_SERIES)}
    delete, keep = plan_retention(mine, cfg.list_prefix(), cfg.retention_days,
                                  provider_now=provider_now, hold=hold)
    deferred = max(0, len(delete) - MAX_DELETES_PER_RUN)
    deleted, errors = [], []
    for key, why in delete[:MAX_DELETES_PER_RUN]:
        if dry_run:
            deleted.append(key)
            continue
        try:
            with_retries('delete %s' % key, lambda: s3.delete_object(key))
            deleted.append(key)
            state['owned'].pop(key, None)
            log('retention: deleted %s (%s)' % (key, why))
        except S3Error as e:
            errors.append('%s: %s' % (key, explain_error(e)))
            log('retention: could not delete %s: %s' % (key, explain_error(e)), 'ERROR')
            if e.fatal:
                break
    if not dry_run:
        save_state(state)
    return {'deleted': deleted, 'keep': keep, 'errors': errors, 'foreign': foreign,
            'unknown': unknown, 'deferred': deferred}


# ── The worker ─────────────────────────────────────────────────────────────────

def _on_signal(signum, frame):
    global STOP_REQUESTED
    if STOP_REQUESTED:
        return              # already stopping: let the abort request finish
    STOP_REQUESTED = True
    raise Interrupted()


def acquire_lock():
    ensure_state_dir()
    fd = os.open(LOCK_FILE, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError as e:
        os.close(fd)
        if e.errno in (errno.EWOULDBLOCK, errno.EAGAIN, errno.EACCES):
            return None
        raise
    try:
        fd2 = os.open(PID_FILE, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd2, 'w') as fh:
            fh.write(str(os.getpid()))
    except OSError:
        pass
    return fd


def release_lock(fd):
    # The pid file goes first: from here on, a new trigger starts a worker of
    # its own instead of trusting this one to see its archive.
    try:
        os.unlink(PID_FILE)
    except OSError:
        pass
    try:
        fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)


def worker_running():
    """True when a worker holds the lock. Read from its pid file rather than by
    probing the lock, which would turn away a worker starting at that moment."""
    try:
        with open(PID_FILE) as fh:
            pid = int(fh.read().strip())
        with open('/proc/%d/cmdline' % pid, 'rb') as fh:
            return os.path.basename(SCRIPT_PATH).encode() in fh.read()
    except (OSError, ValueError):
        return False


def feature_removed():
    return not os.path.isfile(PANEL_COPY)


def run_worker(manual=False):
    """Upload everything due, then apply retention. Returns an exit code."""
    global _STATUS
    signal.signal(signal.SIGTERM, _on_signal)
    signal.signal(signal.SIGINT, _on_signal)
    trim_log()
    if feature_removed():
        sync_cron(False)
        log('the panel no longer has remote backup — nothing uploaded, retry job removed', 'WARN')
        return 0
    cfg = load_saved_config()
    if not cfg.enabled:
        sync_cron(False)
        log('remote backups are disabled — nothing to do')
        return 0
    errs = cfg.errors()
    if errs:
        msg = 'Remote backup is enabled but not usable: ' + ' '.join(errs)
        log(msg, 'ERROR')
        write_simple_status('failed', msg, cfg)
        panel_log('ERROR', 'S3 backup not run: settings incomplete', ' '.join(errs))
        return 1
    sync_cron(True)               # heals a cron the panel could not write
    sid = server_id()

    status = None
    totals = {'uploaded': 0, 'failed': 0, 'bytes': 0, 'present': 0}
    attempts = {}
    fatal = None
    link_down = False
    retention = None
    try:
        while True:
            lock = acquire_lock()
            if lock is None:
                if status is None:
                    log('another upload is already running; it will pick up any new archives')
                    return 0
                # A worker started between our unlock and this re-check, and
                # owns the status line now.
                log('upload run handed over to a newer worker: %d uploaded, %d failed'
                    % (totals['uploaded'], totals['failed']))
                return 1 if totals['failed'] else 0
            try:
                if status is None:
                    status = Status(cfg)
                    _STATUS = status
                    status.start_heartbeat()
                    status.write()
                    log('upload run started → %s%s' % (cfg.describe(), ' (Upload Now)' if manual else ''))
                s3 = S3Client(cfg)
                state = load_state(cfg.destination_id())
                prune_state(state)
                if state['inflight']:
                    abort_leftovers(s3, state)
                streak = 0
                while True:
                    todo = discover(cfg, state, attempts, manual=manual)
                    status.d['pending'] = len(todo)
                    if not todo:
                        break
                    rel, a = todo[0]
                    attempts[a['version']] = attempts.get(a['version'], 0) + 1
                    started = time.monotonic()
                    try:
                        size, verified, sent = upload_archive(s3, cfg, state, rel, a, status, sid)
                        streak = 0
                        if sent:
                            secs = max(time.monotonic() - started, 0.001)
                            totals['uploaded'] += 1
                            totals['bytes'] += size
                            log('uploaded %s (%s in %ds, %s/s; %s)' % (
                                rel, human_size(size), secs, human_size(size / secs), verified))
                        else:
                            totals['present'] += 1
                            log('%s is already in the bucket — not sent again' % rel)
                    except FileChanged as e:
                        log('%s changed during the upload (%s) — the new version goes next' % (rel, e), 'WARN')
                    except FileNotFoundError:
                        log('%s disappeared before it could be uploaded' % rel, 'WARN')
                    except FolderConflict as e:
                        why = ('another server%s already has a backup named %s in this folder — '
                               'give each server its own folder' % ((' (%s)' % e.args[0][:12]) if e.args[0] else '',
                                                                     rel))
                        totals['failed'] += 1
                        streak += 1
                        status.error(why)
                        note_failure(state, rel, a, why, conflict=True)
                        log(why, 'ERROR')
                    except (S3Error, OSError) as e:
                        why = explain_error(e) if isinstance(e, S3Error) else str(e)
                        streak += 1
                        if isinstance(e, S3Error) and e.fatal:
                            fatal = e
                        elif isinstance(e, S3Error) and e.transient:
                            link_down = True      # retried for its whole window: the link is down
                        if fatal or link_down or attempts[a['version']] >= FILE_ATTEMPTS_PER_RUN:
                            totals['failed'] += 1
                            status.error('%s: %s' % (rel, why))
                            # Back-off is for an archive that fails on its own.
                            # A dead link is paced by the hourly retry instead.
                            if not (fatal or link_down):
                                note_failure(state, rel, a, why)
                        log('upload of %s failed: %s%s' % (rel, why, ' — stopping this run'
                                                          if (fatal or link_down) else ''), 'ERROR')
                    status.d.update(uploaded=totals['uploaded'], failed=totals['failed'],
                                    bytes=totals['bytes'], current=None)
                    status.write()
                    if fatal or link_down or streak >= FAILURES_TO_END_PASS:
                        break
                if cfg.retention_enabled and retention is None and not (fatal or link_down):
                    retention = retention_step(s3, cfg, state, sid, status)
            finally:
                release_lock(lock)
            if fatal or link_down or STOP_REQUESTED:
                break
            # Re-check after unlocking (and after retention): an archive that
            # finished meanwhile had its own worker turned away — ours to take.
            try:
                more = discover(cfg, load_state(cfg.destination_id()), attempts, manual=manual)
            except Exception:
                more = []
            if not more:
                break
    except Interrupted:
        log('stopped by a signal; the unfinished upload was aborted', 'WARN')
        if status is not None:
            status.d.update(state='interrupted', finished=now_str(), current=None, note='', retry=True,
                            message='Stopped before finishing. It carries on within the hour.')
            status.write()
            status.stop_heartbeat()
        return 1
    except Exception as e:
        log('upload run failed: %s' % e, 'ERROR')
        if status is None or status.prev.get('message') != 'Upload run failed: %s' % e:
            panel_log('ERROR', 'S3 backup upload run failed', str(e))
        if status is not None:
            status.d.update(state='failed', finished=now_str(), current=None, note='', retry=True,
                            message='Upload run failed: %s' % e)
            status.write()
            status.stop_heartbeat()
        return 1
    finally:
        _STATUS = None
    return finish_run(cfg, status, totals, fatal, link_down, retention)


def retention_step(s3, cfg, state, sid, status):
    status.d['current'] = None
    status.note('Applying retention…')
    try:
        r = run_retention(s3, cfg, state, sid)
        ret = {'deleted': len(r['deleted']), 'kept': len(r['keep']), 'foreign': r['foreign'],
               'error': '; '.join(r['errors'])[:500]}
        if r['deleted']:
            panel_log('INFO', 'S3 retention removed %d old backup(s)' % len(r['deleted']), '\n'.join(r['deleted']))
        if r['errors']:
            panel_log('ERROR', 'S3 retention could not delete %d object(s)' % len(r['errors']),
                      '\n'.join(r['errors']))
        if r['deferred']:
            log('retention: %d more old backup(s) left for the next run' % r['deferred'])
    except S3Error as e:
        ret = {'deleted': 0, 'kept': 0, 'foreign': 0, 'error': explain_error(e)}
        log('retention skipped: %s' % explain_error(e), 'ERROR')
        panel_log('ERROR', 'S3 retention failed', explain_error(e))
    status.note('')
    return ret


def finish_run(cfg, status, totals, fatal, link_down, ret):
    prev = status.prev
    status.d.update(uploaded=totals['uploaded'], failed=totals['failed'], bytes=totals['bytes'],
                    current=None, finished=now_str(), retention=ret, pending=0, note='')
    summary = '%d archive(s) uploaded (%s)' % (totals['uploaded'], human_size(totals['bytes']))
    if totals['present']:
        summary += ', %d already in the bucket' % totals['present']
    if fatal:
        status.d.update(state='failed', retry=False, message='Upload stopped: %s' % explain_error(fatal))
    elif link_down:
        status.d.update(state='failed', retry=True,
                        message='%s. The storage service could not be reached; trying again within the hour.'
                        % summary)
    elif totals['failed']:
        status.d.update(state='partial', retry=True,
                        message='%s, %d failed — retried automatically.' % (summary, totals['failed']))
    else:
        status.d.update(state='ok', retry=False, last_success=now_str(), last_success_ts=int(time.time()),
                        message=(summary + '.') if totals['uploaded'] else 'Everything is already uploaded.')
    if ret and ret.get('error'):
        status.d['message'] += ' Retention failed: %s' % ret['error']
        if status.d['state'] == 'ok':
            status.d['state'] = 'partial'
    elif ret:
        status.d['message'] += ' Retention removed %d old backup(s).' % ret['deleted']
        if ret.get('foreign'):
            status.d['message'] += ' %d backup(s) in the folder belong to another server and were left alone.' \
                                   % ret['foreign']
    status.write()
    status.stop_heartbeat()
    log('upload run finished: %s' % status.d['message'])
    bad = status.d['state'] in ('failed', 'partial')
    # One panel log row per change, not one per hourly retry of the same fault.
    if bad and not (prev.get('state') == status.d['state'] and prev.get('message') == status.d['message']):
        panel_log('ERROR', 'S3 backup: %s' % status.d['message'][:200], cfg.describe())
    elif not bad and (totals['uploaded'] or prev.get('state') in ('failed', 'partial', 'interrupted')):
        panel_log('INFO', 'S3 backup: %s' % status.d['message'][:200], cfg.describe())
    return 1 if bad else 0


# ── Detaching, and the hourly retry job ────────────────────────────────────────

def spawn_worker(manual=False):
    """Start run_worker() detached from the caller. Returns how it was started."""
    cmd = [PYTHON, '-I', SCRIPT_PATH, 'upload', '--foreground'] + (['--manual'] if manual else [])
    for sr in ('/usr/bin/systemd-run', '/bin/systemd-run'):
        if not os.access(sr, os.X_OK):
            continue
        unit = 'inetpanel-s3-upload-%d-%d' % (int(time.time()), os.getpid())
        try:
            # Type=exec: systemd-run returns once the worker is really running,
            # so a unit that cannot start is noticed here, not silently lost.
            r = subprocess.run([sr, '--quiet', '--collect', '--unit=' + unit, '--property=Type=exec',
                                '--description=iNetPanel S3 backup upload'] + cmd,
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                               stderr=subprocess.PIPE, timeout=60)
            if r.returncode == 0:
                return 'systemd-run'
            log('systemd-run failed (%s) — starting the upload as a background process instead'
                % r.stderr.decode(errors='replace').strip()[:200], 'WARN')
        except (OSError, subprocess.SubprocessError) as e:
            log('systemd-run unavailable (%s) — starting the upload as a background process' % e, 'WARN')
        break
    # stdout/stderr must not be the caller's pipes: PHP's exec() would wait for
    # them to close, i.e. for the whole upload.
    fd = os.open(LOG_FILE, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o640)
    try:
        subprocess.Popen(cmd, stdin=subprocess.DEVNULL, stdout=fd, stderr=fd,
                         start_new_session=True, close_fds=True)
    finally:
        os.close(fd)
    return 'background process'


CRON_CONTENT = (
    '# iNetPanel remote backup retry — written by /root/scripts/s3_backup.py while\n'
    '# remote backup is enabled; removed when it is turned off. Does nothing unless\n'
    '# an upload run failed or was cut off.\n'
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
    '23 * * * * root /usr/bin/python3 -I /root/scripts/s3_backup.py upload --if-needed >> %s 2>&1\n'
    % LOG_FILE)


def sync_cron(want):
    """Install or remove the hourly retry job. Returns 'installed', 'removed',
    'unchanged' or 'deferred' (/etc read-only here, e.g. inside php-fpm's
    sandbox; the next upload run, outside it, puts it right)."""
    want = want and not feature_removed()
    try:
        if want:
            try:
                with open(CRON_FILE) as fh:
                    if fh.read() == CRON_CONTENT:
                        return 'unchanged'
            except OSError:
                pass
            tmp = os.path.join(os.path.dirname(CRON_FILE), '.inetpanel_s3.new')
            try:
                os.unlink(tmp)
            except FileNotFoundError:
                pass
            fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            with os.fdopen(fd, 'w') as fh:
                fh.write(CRON_CONTENT)
            os.chmod(tmp, 0o644)
            os.replace(tmp, CRON_FILE)
            return 'installed'
        if os.path.lexists(CRON_FILE):
            os.unlink(CRON_FILE)
            return 'removed'
        return 'unchanged'
    except OSError as e:
        if e.errno in (errno.EROFS, errno.EACCES, errno.EPERM):
            return 'deferred'
        raise


def retry_needed():
    """For the hourly job: is there an interrupted or failed run to pick up?"""
    st = previous_status()
    state = st.get('state')
    beat = max(int(st.get('heartbeat_ts') or 0), _int(read_settings(('s3_heartbeat',)).get('s3_heartbeat'), 0))
    if state == 'running':
        return time.time() - beat > STALE_AFTER       # the worker died without a word
    return state == 'interrupted' or bool(st.get('retry'))


# ── Commands ───────────────────────────────────────────────────────────────────

def emit(as_json, payload, text):
    print(json.dumps(payload) if as_json else text)


def cmd_upload(args, as_json):
    manual = '--manual' in args
    if '--foreground' in args:
        return run_worker(manual=manual)
    if feature_removed():
        sync_cron(False)
        emit(as_json, {'success': False, 'started': False, 'error': 'Remote backup is not part of this panel.'},
             'remote backup is not part of this panel — retry job removed')
        return 1
    cfg = load_saved_config()
    if '--if-needed' in args:
        # The hourly job: quiet unless there is something to retry.
        if not cfg.enabled:
            sync_cron(False)
            return 0
        if cfg.errors() or worker_running() or not retry_needed():
            return 0
        try:
            due = discover(cfg, load_state(cfg.destination_id()), {})
        except (OSError, RuntimeError):
            due = []
        if not due and previous_status().get('state') != 'running':
            return 0
        log('retrying the last upload run (%s)' % spawn_worker())
        return 0
    if not cfg.enabled:
        emit(as_json, {'success': False, 'started': False, 'error': 'Remote backups are disabled.'},
             'remote backups are disabled — nothing to upload')
        return 1
    errs = cfg.errors()
    if errs:
        msg = 'Remote backup is enabled but not usable: ' + ' '.join(errs)
        write_simple_status('failed', msg, cfg)
        panel_log('ERROR', 'S3 backup not run: settings incomplete', ' '.join(errs))
        emit(as_json, {'success': False, 'started': False, 'error': msg}, 'not started: ' + msg)
        return 1
    if worker_running():
        emit(as_json, {'success': True, 'started': False, 'running': True,
                       'message': 'An upload is already running; it picks up new backups by itself.'},
             'an upload is already running; it picks up new backups by itself')
        return 0
    how = spawn_worker(manual=manual)
    emit(as_json, {'success': True, 'started': True, 'how': how, 'message': 'Upload started in the background.'},
         'upload started in the background (%s) — progress on the Backups page, log in %s' % (how, LOG_FILE))
    return 0


def cmd_test(args, as_json):
    raw = '' if sys.stdin.isatty() else sys.stdin.read(65536)
    if raw.strip():
        try:
            form = json.loads(raw)
            if not isinstance(form, dict):
                raise ValueError
        except ValueError:
            emit(as_json, {'success': False, 'error': 'Invalid test input.', 'steps': []}, 'invalid test input')
            return 1
        given = str(form.get('secret') or '').strip()
        settings = {(k if k.startswith('s3_') else 's3_' + k): v for k, v in form.items() if k != 'secret'}
        cfg = Config(settings, given or read_secret())
    else:
        cfg = load_saved_config()
    errs = cfg.errors()
    if errs:
        emit(as_json, {'success': False, 'error': ' '.join(errs), 'steps': []}, 'FAILED: ' + ' '.join(errs))
        return 1

    s3 = S3Client(cfg)
    steps = []
    key = cfg.key_for('.inetpanel-connection-test-%s' % secrets.token_hex(6))
    body = ('iNetPanel S3 connection test from %s at %s. Safe to delete.\n'
            % (socket.gethostname(), now_str())).encode()
    t0 = time.monotonic()

    def step(name, level, detail):
        steps.append({'name': name, 'ok': level != 'error', 'level': level, 'detail': detail})

    def finish(success, message):
        payload = {'success': success, 'message': message, 'steps': steps, 'endpoint': s3.endpoint_url(),
                   'style': s3.style, 'elapsed_ms': int((time.monotonic() - t0) * 1000)}
        lines = ['%s %s: %s' % ({'ok': 'OK  ', 'warn': 'WARN', 'error': 'FAIL'}[s['level']], s['name'], s['detail'])
                 for s in steps]
        emit(as_json, payload, '\n'.join(lines + [('PASSED: ' if success else 'FAILED: ') + message]))
        return 0 if success else 1

    try:
        s3.put_bytes(key, body, headers={'content-type': 'text/plain'}, timeout=20)
        step('Upload', 'ok', 'wrote a %d-byte test object to %s' % (len(body), key))
    except S3Error as e:
        step('Upload', 'error', explain_error(e))
        return finish(False, 'Could not write to the bucket: ' + explain_error(e))
    if s3.scheme == 'http':
        step('Encryption', 'warn', 'plain http:// — backups cross your network unencrypted')

    try:
        h = s3.head_object(key, timeout=20)
        if int(h.get('content-length', '-1')) == len(body):
            step('Read back', 'ok', 'the test object is there at the right size')
        else:
            step('Read back', 'error', 'the test object came back with the wrong size')
    except S3Error as e:
        if e.status == 403:
            step('Read back', 'warn', 'not permitted (a write-only key) — uploads are still checked by the '
                 'provider' + ('; retention can then only remove backups this server recorded uploading'
                               if cfg.retention_enabled else ''))
        else:
            step('Read back', 'error', explain_error(e))

    if cfg.retention_enabled:
        try:
            s3.list_objects(cfg.list_prefix(), max_keys=1, limit_pages=1)
            step('List', 'ok', 'can list the folder (retention needs this)')
        except S3Error as e:
            step('List', 'error', 'retention needs permission to list the bucket: ' + explain_error(e))

    try:
        s3.delete_object(key, timeout=20)
        step('Delete', 'ok', 'removed the test object')
    except S3Error as e:
        if cfg.retention_enabled:
            step('Delete', 'error', 'retention needs permission to delete objects: ' + explain_error(e))
        else:
            step('Delete', 'warn', 'could not delete the test object (%s) — remove %s by hand'
                 % (explain_error(e), key))

    problems = [s for s in steps if s['level'] == 'error']
    if problems:
        return finish(False, problems[0]['detail'])
    return finish(True, 'Connected to %s.' % cfg.describe())


def cmd_set_secret(args, as_json):
    if sys.stdin.isatty():
        secret = getpass.getpass('Secret access key: ').strip()
    else:
        secret = sys.stdin.read(4096).strip()
    if not SECRET_RE.fullmatch(secret):
        emit(as_json, {'success': False,
                       'error': 'Secret access key must be 8-256 printable characters with no spaces.'},
             'invalid secret key')
        return 1
    tmp = SECRET_FILE + '.tmp'
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as fh:
        fh.write(secret + '\n')
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, SECRET_FILE)
    os.chmod(SECRET_FILE, 0o600)
    emit(as_json, {'success': True}, 'secret key saved to %s' % SECRET_FILE)
    return 0


def cmd_clear_secret(args, as_json):
    try:
        os.unlink(SECRET_FILE)
    except FileNotFoundError:
        pass
    write_setting('s3_secret_set', '0')
    emit(as_json, {'success': True}, 'secret key removed')
    return 0


def cmd_cron(args, as_json):
    cfg = load_saved_config()
    result = sync_cron(cfg.enabled)
    emit(as_json, {'success': True, 'cron': result}, 'retry job: %s' % result)
    return 0


def cmd_status(args, as_json):
    st = previous_status()
    running = worker_running()
    payload = {'success': True, 'status': st, 'running': running, 'secret_present': bool(read_secret())}
    if as_json:
        print(json.dumps(payload))
        return 0
    print('upload running: %s   secret key saved: %s' % ('yes' if running else 'no',
                                                         'yes' if payload['secret_present'] else 'no'))
    for k in ('state', 'started', 'finished', 'message', 'note', 'destination', 'last_success'):
        print('%-13s %s' % (k + ':', st.get(k, '')))
    for e in st.get('errors') or []:
        print('error:        %s' % e)
    return 0


def cmd_retention(args, as_json):
    dry = '--dry-run' in args
    cfg = load_saved_config()
    if not cfg.retention_enabled and not dry:
        emit(as_json, {'success': False, 'error': 'Retention is turned off.'}, 'retention is turned off')
        return 1
    errs = cfg.errors()
    if errs:
        emit(as_json, {'success': False, 'error': ' '.join(errs)}, 'FAILED: ' + ' '.join(errs))
        return 1
    sid = server_id()
    lock = acquire_lock()
    if lock is None:
        emit(as_json, {'success': False, 'error': 'An upload is running; try again when it finishes.'},
             'an upload is running; try again when it finishes')
        return 1
    try:
        r = run_retention(S3Client(cfg), cfg, load_state(cfg.destination_id()), sid, dry_run=dry)
    except S3Error as e:
        emit(as_json, {'success': False, 'error': explain_error(e)}, 'FAILED: ' + explain_error(e))
        return 1
    finally:
        release_lock(lock)
    if as_json:
        print(json.dumps({'success': not r['errors'], 'dry_run': dry, 'deleted': r['deleted'],
                          'kept': [k for k, _ in r['keep']], 'foreign': r['foreign'], 'errors': r['errors']}))
    else:
        for k in r['deleted']:
            print('%s %s' % ('would delete' if dry else 'deleted', k))
        for k, why in r['keep']:
            print('keep %s (%s)' % (k, why))
        if r['foreign']:
            print('left alone: %d backup(s) uploaded by another server' % r['foreign'])
        for e in r['errors']:
            print('error: %s' % e)
        if not r['deleted'] and not r['keep']:
            print('no backups of this server found under %s' % (cfg.list_prefix() or 'the bucket root'))
    return 1 if r['errors'] else 0


def cmd_selftest(args, as_json):
    failures = []

    def check(name, got, want):
        if got != want:
            failures.append('%s: got %r, want %r' % (name, got, want))

    # AWS's published SigV4 examples for S3 (sig-v4-header-based-auth).
    ak, sk, d = 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', '20130524T000000Z'
    host = 'examplebucket.s3.amazonaws.com'

    def sig(auth):
        return auth.rsplit('Signature=', 1)[1]

    a = sigv4_authorization('GET', '/test.txt', [], {
        'host': host, 'range': 'bytes=0-9', 'x-amz-content-sha256': EMPTY_SHA256, 'x-amz-date': d},
        EMPTY_SHA256, ak, sk, 'us-east-1', d)
    check('SigV4 GET object', sig(a), 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41')
    payload = hashlib.sha256(b'Welcome to Amazon S3.').hexdigest()
    a = sigv4_authorization('PUT', '/' + uri_encode('test$file.text', True), [], {
        'host': host, 'date': 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-content-sha256': payload,
        'x-amz-date': d, 'x-amz-storage-class': 'REDUCED_REDUNDANCY'}, payload, ak, sk, 'us-east-1', d)
    check('SigV4 PUT object', sig(a), '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd')
    a = sigv4_authorization('GET', '/', [('lifecycle', '')], {
        'host': host, 'x-amz-content-sha256': EMPTY_SHA256, 'x-amz-date': d}, EMPTY_SHA256, ak, sk, 'us-east-1', d)
    check('SigV4 GET lifecycle', sig(a), 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543')
    a = sigv4_authorization('GET', '/', [('prefix', 'J'), ('max-keys', '2')], {
        'host': host, 'x-amz-content-sha256': EMPTY_SHA256, 'x-amz-date': d}, EMPTY_SHA256, ak, sk, 'us-east-1', d)
    check('SigV4 list objects', sig(a), '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7')

    check('uri_encode path', uri_encode('a b/c+d~e', True), 'a%20b/c%2Bd~e')
    check('uri_encode query', uri_encode('a/b=c', False), 'a%2Fb%3Dc')

    # Part sizing: uniform parts, never more than 10,000 of them.
    check('part size, ordinary archive', choose_part_size(100 * MIB), PART_SIZE)
    big = 400 * 1024 * MIB
    ps = choose_part_size(big)
    check('part size, 400 GB archive', math.ceil(big / ps) <= MAX_PARTS and ps % MIB == 0, True)

    # Retention (already limited to this server's own objects).
    now = calendar.timegm((2026, 10, 20, 12, 0, 0, 0, 0, 0))

    def lm(month, day):
        return '2026-%02d-%02dT04:00:00.000Z' % (month, day)

    objs = [
        ('p/alice_2026-10-01.tgz', 1, lm(10, 1)), ('p/alice_2026-10-19.tgz', 1, lm(10, 19)),
        ('p/alice_2026-10-20.tgz', 1, lm(10, 20)), ('p/bob_2026-09-01.tgz', 1, lm(9, 1)),
        ('p/carol_2026-10-02.tgz', 1, lm(10, 19)), ('p/carol_2026-10-18.tgz', 1, lm(10, 18)),
        ('p/eve_2026-10-03.tgz', 1, ''), ('p/eve_2026-10-04.tgz', 1, lm(10, 4)),
        ('p/fred_2099-01-01.tgz', 1, lm(10, 1)), ('p/fred_2026-10-02.tgz', 1, lm(10, 2)),
        ('p/fred_2026-10-01.tgz', 1, lm(10, 1)),
        ('p/gus_2026-10-01.tgz', 1, lm(10, 1)), ('p/gus_2026-10-02.tgz', 1, lm(10, 2)),
        ('p/notes.txt', 1, lm(10, 1)), ('p/deleted/dave_2026-01-01.tgz', 1, lm(10, 1)),
        ('other/alice_2026-01-01.tgz', 1, lm(10, 1)), ('p/alice_2026-10-01.tgz\n', 1, lm(10, 1)),
    ]
    delete, keep = plan_retention(objs, 'p/', 7, now, hold={'gus'})
    kept = [k for k, _ in keep]
    check('retention deletes only expired, unprotected backups', sorted(k for k, _ in delete),
          ['p/alice_2026-10-01.tgz', 'p/fred_2026-10-01.tgz'])
    check('retention keeps the newest even when old', 'p/bob_2026-09-01.tgz' in kept, True)
    check('retention keeps an old backup uploaded recently', 'p/carol_2026-10-02.tgz' in kept, True)
    check('retention keeps an unreadable upload time', 'p/eve_2026-10-03.tgz' in kept, True)
    check('a future-dated name is not "the newest"', ('p/fred_2026-10-02.tgz' in kept, 'p/fred_2099-01-01.tgz' in kept),
          (True, True))
    check('nothing deleted while the newest local backup is pending', 'p/gus_2026-10-01.tgz' in kept, True)
    check('retention ignores other names and folders',
          [k for k in kept + [x for x, _ in delete] if 'notes' in k or 'deleted/' in k or k.startswith('other/')
           or k.endswith('\n')], [])
    check('the provider clock decides upload age',
          [k for k, _ in plan_retention([('p/x_2026-10-01.tgz', 1, lm(10, 1)), ('p/x_2026-10-20.tgz', 1, lm(10, 20))],
                                        'p/', 7, now, provider_now=calendar.timegm((2026, 10, 3, 0, 0, 0, 0, 0, 0)))[0]],
          [])

    # Addressing.
    def client_for(endpoint, style, bucket='my-bucket', region='us-east-1'):
        return S3Client(Config({'s3_endpoint': endpoint, 's3_path_style': style, 's3_bucket': bucket,
                                's3_region': region}, ''))
    c = client_for('', 'auto')
    check('AWS default is virtual-hosted', (c.style, c.host_header, c.object_path('a b')),
          ('virtual', 'my-bucket.s3.us-east-1.amazonaws.com', '/a%20b'))
    c = client_for('', 'auto', bucket='my.dotted.bucket')
    check('AWS dotted bucket goes path-style', (c.style, c.host_header, c.object_path('k')),
          ('path', 's3.us-east-1.amazonaws.com', '/my.dotted.bucket/k'))
    c = client_for('', 'virtual', bucket='Legacy_Bucket')
    check('AWS legacy bucket name goes path-style', c.style, 'path')
    c = client_for('http://minio.lan:9000', 'auto')
    check('custom endpoint defaults to path-style', (c.style, c.host_header, c.object_path('')),
          ('path', 'minio.lan:9000', '/my-bucket'))
    c = client_for('https://minio.lan', 'virtual')
    check('custom endpoint honours virtual-hosted', (c.style, c.host_header), ('virtual', 'my-bucket.minio.lan'))
    c = client_for('http://10.0.0.5:9000', 'virtual')
    check('IP endpoint forces path-style', (c.style, c.host_header), ('path', '10.0.0.5:9000'))
    c = client_for('http://[::1]:9000', 'auto')
    check('IPv6 endpoint host header', (c.style, c.host_header), ('path', '[::1]:9000'))

    # Plain http policy (no network: resolution of literal addresses only).
    if not ALLOW_LOCAL_HTTP:
        for ep, ok in (('http://127.0.0.1:9000', False), ('http://8.8.8.8:9000', False),
                       ('http://192.168.255.254:9000', True)):
            try:
                client_for(ep, 'auto')._check_plain_http(socket.getaddrinfo(urlsplit(ep).hostname, 9000, 0,
                                                                          socket.SOCK_STREAM))
                got = True
            except S3Error:
                got = False
            check('plain http to %s %s' % (ep, 'allowed' if ok else 'refused'), got, ok)

    # Error explanations, including the forms Garage uses.
    for status, code, message, extra, want in (
            (403, 'SignatureDoesNotMatch', '', {}, 'secret key does not match'),
            (403, 'AccessDenied', 'Forbidden: Invalid signature', {}, 'secret key does not match'),
            (403, 'AccessDenied', 'Forbidden: No such key: GK00', {}, 'access key ID was not recognised'),
            (403, 'AccessDenied', 'Access Denied', {}, 'access denied'),
            (400, 'AuthorizationHeaderMalformed', '', {'Region': 'eu-west-2'}, 'expects region "eu-west-2"'),
            (400, 'AuthorizationHeaderMalformed',
             "unexpected scope: '20261020/us-east-1/s3/aws4_request', expected: '20261020/garage/s3/aws4_request'",
             {}, 'expects region "garage"')):
        got = explain_error(S3Error('t', status, code, message, extra))
        check('explains %s %s' % (code, message[:24]), want in got, True)

    # Validation.
    good = Config({'s3_bucket': 'my-bucket', 's3_access_key': 'AKIAEXAMPLE', 's3_region': 'auto',
                   's3_endpoint': 'https://abc.r2.cloudflarestorage.com', 's3_prefix': '/inetpanel/web01/'},
                  'secretsecret')
    check('valid settings accepted', good.errors(), [])
    check('prefix normalised', good.prefix, 'inetpanel/web01')
    bad = Config({'s3_bucket': 'x', 's3_access_key': 'a b', 's3_endpoint': 'ftp://x', 's3_prefix': 'a/../b'},
                 'short')
    check('invalid settings rejected', len(bad.errors()), 5)
    for name, field, value in (('a newline inside the folder', 's3_prefix', 'a\n/b'),
                               ('port above 65535', 's3_endpoint', 'https://host:99999'),
                               ('negative speed limit', 's3_bandwidth_mbit', '-1'),
                               ('retention 0 days', 's3_retention_days', '0')):
        c2 = Config(dict({'s3_bucket': 'my-bucket', 's3_access_key': 'AKIAEXAMPLE'}, **{field: value}), 'secretsecret')
        check('rejects ' + name, len(c2.errors()), 1)

    ok = not failures
    emit(as_json, {'success': ok, 'failures': failures},
         'selftest passed' if ok else 'selftest FAILED:\n  ' + '\n  '.join(failures))
    return 0 if ok else 1


COMMANDS = {
    'upload': cmd_upload, 'test': cmd_test, 'set-secret': cmd_set_secret, 'clear-secret': cmd_clear_secret,
    'status': cmd_status, 'retention': cmd_retention, 'cron': cmd_cron, 'selftest': cmd_selftest,
}

USAGE = ('usage: inetp s3_backup {upload [--manual]|test|set-secret|clear-secret|status|'
         'retention [--dry-run]|cron|selftest} [--json]')


def main(argv):
    as_json = '--json' in argv
    args = [a for a in argv if a != '--json']
    if not args or args[0] not in COMMANDS:
        print(USAGE)
        return 2
    if os.geteuid() != 0 and args[0] != 'selftest':
        emit(as_json, {'success': False, 'error': 'must run as root'}, 'error: must run as root')
        return 1
    os.umask(0o077)
    try:
        return COMMANDS[args[0]](args[1:], as_json)
    except Interrupted:
        return 1
    except Exception as e:
        log('%s failed: %s' % (args[0], e), 'ERROR')
        emit(as_json, {'success': False, 'error': str(e)}, 'error: %s' % e)
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
