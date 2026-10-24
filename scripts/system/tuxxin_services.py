#!/usr/bin/env python3
# ==============================================================================
# tuxxin_services.py — iNetPanel's optional integrations with Tuxxin services
#
# Usage: inetp tsvc status                    modules, saved keys, quotas
#        inetp tsvc set-secret <name>         store a key, token or webhook (stdin)
#        inetp tsvc clear-secret <name>       forget it
#        inetp tsvc migrate                   one-time moves, run by panel_update.php
#        inetp tsvc cron                      install/remove the worker cron to match settings
#        inetp tsvc worker                    run queued jobs within each service's quota
#        inetp tsvc notify-test               send a test message to the saved webhook
#        inetp tsvc qsa run|start|status|history|show <id>|diff|monitor|sync-cron
#        inetp tsvc worldip <ip>|self [--refresh]
#        inetp tsvc issued <domain> [--refresh]
#        inetp tsvc webshot queue <domain>|capture <domain>|status <domain>
#        inetp tsvc whack <domain> [--refresh]
#        inetp tsvc selftest
#        add --json for machine-readable output (the panel uses it)
#
# THE MODULES
# -----------
#   qsa      qsa.sh       external exposure scan of this server's public IPv4
#   worldip  worldip.io   IP intelligence (country, ASN, rDNS) for IPs the panel shows
#   issued   issued.live  domain registration expiry and certificate details
#   webshot  webshot.site screenshots of hosted sites
#   whack    whack.sh     multi-egress malware / phishing scan of a hosted site
#
# Every module is off unless both the master switch (tsvc_master) and its own
# switch (tsvc_<module>) are '1'. Off means it does not run at all: no request
# leaves this server for it, nothing is enriched, nothing is queued. New installs
# are seeded with the modules on except whack (public/install.php; whack.sh has no
# keyless tier, so it waits for a key); an upgrade seeds the master
# switch on and every new module off (db/migrations/004). qsa predates the
# switches and stays on for existing installs, so an upgrade does not take away
# a feature someone was already using.
#
# WHERE THINGS LIVE, AND WHY
# --------------------------
# Keys, the qsa.sh token and the webhook URL live in /root/.inetpanel_tsvc/
# (0700, files 0600) — never in the panel database, never in a file www-data
# can read. The panel hands a secret over on stdin and records only
# "<name>_set" = 1 in the database.
#
# qsa.sh reports are a map of this server's open ports and known CVEs. They are
# kept in /var/lib/inetpanel/qsa as root:root 0700, and the panel reads them
# through this script.
#
# Cached answers (worldip, issued.live, whack.sh verdicts) and the job queue are
# in the panel database: none of it is secret, and the panel reads it directly
# instead of going through sudo for every page view.
#
# RATE LIMITS
# -----------
# Every service except qsa.sh reports its quota on each response — JSON
# _meta.rate {limit, remaining, reset} and X-RateLimit-* headers. The last seen
# values are kept per service (settings tsvc_quota_<service>), and nothing is
# requested while a service says it is out of quota and its reset time has not
# passed. Background work (screenshots, expiry refreshes) goes through one job
# queue that the worker drains within those limits; a click in the panel is
# queued ahead of background refreshes.
#
# qsa.sh has no quota JSON: free is one scan per 24 hours per IP, a Full token
# one an hour, a Deep token one credit per scan. A Deep credit is spent the
# moment a scan is queued, so a Deep token is never scheduled.
# ==============================================================================

import datetime
import fcntl
import getpass
import ipaddress
import json
import os
import pwd
import random
import re
import socket
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# ── Fixed paths. Not configurable: nothing a caller passes can move them. ──────
PANEL_DB      = '/var/www/inetpanel/db/inetpanel.db'
PANEL_VERSION = '/var/www/inetpanel/TiCore/Version.php'
SECRETS_DIR   = '/root/.inetpanel_tsvc'
STATE_DIR     = '/var/lib/inetpanel/tsvc'
SHOTS_DIR     = STATE_DIR + '/shots'
WORKER_LOCK   = STATE_DIR + '/worker.lock'
LOG_FILE      = '/var/log/inetpanel_tsvc.log'
WORKER_CRON   = '/etc/cron.d/inetpanel_tsvc'
SCRIPT_PATH   = os.path.abspath(__file__)
PYTHON        = '/usr/bin/python3'
CURL          = '/usr/bin/curl'

QSA_DIR       = '/var/lib/inetpanel/qsa'
QSA_LIVE      = QSA_DIR + '/live.txt'
QSA_STATE     = QSA_DIR + '/state.json'
QSA_LOCK      = QSA_DIR + '/scan.lock'
QSA_BASELINE  = QSA_DIR + '/latest.txt'      # newest COMPLETE scan: what monitoring compares with
QSA_PREVIOUS  = QSA_DIR + '/previous.txt'    # the baseline before the last detected change
QSA_LOG       = '/var/log/qsa-monitor.log'
QSA_CRON      = '/etc/cron.d/inetpanel_qsa_monitor'
QSA_KEEP      = 30

MODULES = ('qsa', 'worldip', 'issued', 'webshot', 'whack')
# Default when a module's switch has never been written. qsa existed before
# the switches; the others are new and stay off until seeded or turned on.
MODULE_DEFAULT = {'qsa': '1', 'worldip': '0', 'issued': '0', 'webshot': '0', 'whack': '0'}

SECRET_RULES = {
    # qsa.sh redeems exactly 32 hex characters; anything else is a 404 there.
    'qsa_token':   re.compile(r'[A-Fa-f0-9]{32}'),
    'webhook_url': re.compile(r'https://[^\s\'"<>\\]{4,2040}'),
    'worldip_key': re.compile(r'[A-Za-z0-9._~+/=-]{8,256}'),
    'issued_key':  re.compile(r'[A-Za-z0-9._~+/=-]{8,256}'),
    'webshot_key': re.compile(r'[A-Za-z0-9._~+/=-]{8,256}'),
    'whack_key':   re.compile(r'[A-Za-z0-9._~+/=-]{8,256}'),
}

# Service endpoints. Module constants rather than literals so the integration
# tests can point them at a local mock; nothing at runtime changes them.
QSA_BASE     = 'https://qsa.sh'
WORLDIP_BASE = 'https://worldip.io'
ISSUED_BASE  = 'https://issued.live'
WEBSHOT_BASE = 'https://webshot.site'
WHACK_BASE   = 'https://whack.sh'

HTTP_TIMEOUT = 15
MIGRATION_MARK = '1'          # bump to re-run migrate_legacy() on every install
LOG_MAX_BYTES = 5 * 1024 * 1024
LOG_KEEP_BYTES = 1024 * 1024

_INTERACTIVE = sys.stdout.isatty()


class ServiceError(Exception):
    """A request to a service that failed in a way worth showing to the admin."""

    def __init__(self, message, code='error', status=0):
        super().__init__(message)
        self.code = code
        self.status = status


# ── Logging ────────────────────────────────────────────────────────────────────

def now_str(ts=None):
    return time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(ts) if ts else time.localtime())


def log(msg, level='INFO', path=LOG_FILE):
    line = '[%s] %s %s\n' % (now_str(), level, msg)
    try:
        fd = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        # cron's ">>" may have created the file first; keep it root-only.
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'a') as fh:
            fh.write(line)
    except OSError:
        pass
    if _INTERACTIVE and path == LOG_FILE and level != 'INFO':
        sys.stderr.write(line)


def trim_log(path):
    """Keep a log bounded without needing a logrotate rule."""
    try:
        if os.path.islink(path) or not os.path.isfile(path) or os.path.getsize(path) <= LOG_MAX_BYTES:
            return
        with open(path, 'rb') as fh:
            fh.seek(-LOG_KEEP_BYTES, os.SEEK_END)
            tail = fh.read()
        tail = tail[tail.find(b'\n') + 1:]
        tmp = path + '.trim'
        try:
            os.unlink(tmp)
        except FileNotFoundError:
            pass
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
        with os.fdopen(fd, 'wb') as fh:
            fh.write(tail)
        os.replace(tmp, path)
    except OSError:
        pass


def emit(as_json, payload, text=None):
    if as_json:
        print(json.dumps(payload))
    elif text is not None:
        print(text)


# ── Panel database ─────────────────────────────────────────────────────────────

SCHEMA = (
    """CREATE TABLE IF NOT EXISTS tsvc_cache (
        service    TEXT NOT NULL,
        item       TEXT NOT NULL,
        status     INTEGER NOT NULL DEFAULT 200,
        body       TEXT,
        fetched_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        PRIMARY KEY (service, item))""",
    """CREATE TABLE IF NOT EXISTS tsvc_jobs (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        service      TEXT NOT NULL,
        item         TEXT NOT NULL,
        priority     INTEGER NOT NULL DEFAULT 5,
        state        TEXT NOT NULL DEFAULT 'queued',
        attempts     INTEGER NOT NULL DEFAULT 0,
        not_before   INTEGER NOT NULL DEFAULT 0,
        requested_by TEXT,
        last_error   TEXT,
        created_at   INTEGER NOT NULL,
        updated_at   INTEGER NOT NULL,
        UNIQUE (service, item))""",
    "CREATE INDEX IF NOT EXISTS idx_tsvc_jobs_due ON tsvc_jobs (state, priority, not_before)",
)


def db_connect():
    con = sqlite3.connect(PANEL_DB, timeout=15)
    con.execute('PRAGMA busy_timeout = 15000')
    return con


def ensure_schema(con=None):
    own = con is None
    con = con or db_connect()
    try:
        for stmt in SCHEMA:
            con.execute(stmt)
        con.commit()
    finally:
        if own:
            con.close()


def read_settings(keys):
    con = db_connect()
    try:
        qs = ','.join('?' * len(keys))
        rows = con.execute('SELECT key, value FROM settings WHERE key IN (%s)' % qs, list(keys)).fetchall()
        return {k: (v if v is not None else '') for k, v in rows}
    finally:
        con.close()


def setting(key, default=''):
    return read_settings([key]).get(key, default)


def write_setting(key, value, category='tuxxin'):
    # settings is (key, value, category, updated_at): name the columns.
    try:
        con = db_connect()
        try:
            con.execute(
                "INSERT INTO settings (key, value, category, updated_at) VALUES (?, ?, ?, datetime('now')) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (key, str(value), category))
            con.commit()
        finally:
            con.close()
        return True
    except sqlite3.Error as e:
        log('could not record %s in the panel database: %s' % (key, e), 'WARN')
        return False


def panel_log(source, level, message, details=''):
    """A row on the panel's Logs page: an audit line. Callers keep secrets
    and scan detail out of it."""
    try:
        con = db_connect()
        try:
            con.execute(
                "INSERT INTO logs (source, level, message, details, user, created_at) "
                "VALUES (?, ?, ?, ?, 'system', ?)",
                (source, level, message, (details or '')[:2000], now_str()))
            con.commit()
        finally:
            con.close()
    except sqlite3.Error as e:
        log('could not write to the panel log: %s' % e, 'WARN')


# ── Module switches ────────────────────────────────────────────────────────────

def module_states():
    keys = ['tsvc_master'] + ['tsvc_' + m for m in MODULES]
    s = read_settings(keys)
    master = s.get('tsvc_master', '1') == '1'
    return master, {m: master and s.get('tsvc_' + m, MODULE_DEFAULT[m]) == '1' for m in MODULES}


def module_enabled(name):
    return module_states()[1].get(name, False)


# ── Secrets ────────────────────────────────────────────────────────────────────

def secret_path(name):
    if name not in SECRET_RULES:
        raise ValueError('unknown secret name')
    return os.path.join(SECRETS_DIR, name)


def ensure_secrets_dir():
    if os.path.islink(SECRETS_DIR):
        raise OSError('%s is a symlink; refusing to use it' % SECRETS_DIR)
    os.makedirs(SECRETS_DIR, mode=0o700, exist_ok=True)
    st = os.lstat(SECRETS_DIR)
    if st.st_uid != 0:
        os.chown(SECRETS_DIR, 0, 0)
    os.chmod(SECRETS_DIR, 0o700)


def read_secret(name):
    try:
        fd = os.open(secret_path(name), os.O_RDONLY | os.O_NOFOLLOW)
    except OSError:
        return ''
    with os.fdopen(fd, 'r') as fh:
        return fh.read(4096).strip()


def store_secret(name, value):
    ensure_secrets_dir()
    path = secret_path(name)
    tmp = path + '.tmp'
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as fh:
        fh.write(value + '\n')
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)
    os.chmod(path, 0o600)
    write_setting(name + '_set', '1')


def remove_secret(name):
    try:
        os.unlink(secret_path(name))
    except FileNotFoundError:
        pass
    write_setting(name + '_set', '0')


def webhook_kind(url):
    """'discord', 'slack', 'ntfy', 'generic' — or '' when the URL is unusable."""
    if not SECRET_RULES['webhook_url'].fullmatch(url or ''):
        return ''
    host = (urllib.parse.urlsplit(url).hostname or '').lower()
    if not host:
        return ''
    if re.search(r'(^|\.)discord(app)?\.com$', host):
        return 'discord'
    if re.search(r'(^|\.)slack\.com$', host):
        return 'slack'
    if host == 'ntfy.sh' or host.startswith('ntfy.'):
        return 'ntfy'
    return 'generic'


# ── HTTP ───────────────────────────────────────────────────────────────────────

def panel_version():
    try:
        with open(PANEL_VERSION) as fh:
            m = re.search(r"APP_VERSION\s*=\s*'([^']+)'", fh.read())
            return m.group(1) if m else 'unknown'
    except OSError:
        return 'unknown'


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


_OPENER = urllib.request.build_opener(_NoRedirect)


def _ipv4_only():
    """Context manager forcing IPv4 for name resolution in this process."""
    class _Ctx:
        def __enter__(self):
            self._orig = socket.getaddrinfo

            def v4(host, port, family=0, *a, **kw):
                return self._orig(host, port, socket.AF_INET, *a, **kw)
            socket.getaddrinfo = v4

        def __exit__(self, *exc):
            socket.getaddrinfo = self._orig
    return _Ctx()


def http_request(url, method='GET', headers=None, body=None, timeout=HTTP_TIMEOUT, ipv4=False,
                 max_bytes=4 * 1024 * 1024):
    """Return (status, headers{lower: value}, body bytes). Never raises for an
    HTTP error status; raises ServiceError when the request could not be made."""
    h = {'User-Agent': 'iNetPanel/%s (+https://inetpanel.info)' % panel_version(),
         'Accept': 'application/json'}
    h.update(headers or {})
    req = urllib.request.Request(url, data=body, method=method, headers=h)

    def go():
        try:
            resp = _OPENER.open(req, timeout=timeout)
            status = resp.status
        except urllib.error.HTTPError as e:
            resp = e
            status = e.code
        except (urllib.error.URLError, OSError, ValueError) as e:
            reason = getattr(e, 'reason', e)
            raise ServiceError('could not reach %s: %s' % (urllib.parse.urlsplit(url).hostname,
                                                          _safe_reason(reason)), 'unreachable')
        except Exception:
            # http.client.BadStatusLine and friends carry the server's raw bytes;
            # a message shown in the panel must never include them.
            raise ServiceError('%s sent a malformed response' % urllib.parse.urlsplit(url).hostname,
                               'unreachable')
        hdrs = {k.lower(): v for k, v in resp.headers.items()} if resp.headers else {}
        try:
            data = resp.read(max_bytes + 1)
        except Exception:
            raise ServiceError('the connection to %s dropped' % urllib.parse.urlsplit(url).hostname,
                               'unreachable')
        if len(data) > max_bytes:
            raise ServiceError('response too large', 'error')
        return status, hdrs, data

    if ipv4:
        with _ipv4_only():
            return go()
    return go()


def _safe_reason(reason):
    """A connection error's reason, reduced to plain words."""
    return re.sub(r'[^A-Za-z0-9 .,:()_-]', '', str(reason))[:120]


def parse_json(data):
    try:
        v = json.loads(data.decode('utf-8', 'replace'))
        return v if isinstance(v, dict) else {'_value': v}
    except ValueError:
        return None


# ── Quotas ─────────────────────────────────────────────────────────────────────

def _epoch(v):
    """A reset time as epoch seconds, from an epoch number or an ISO string."""
    if v in (None, ''):
        return 0
    try:
        f = float(v)
        # Some APIs send "seconds until reset" rather than an epoch.
        return int(f if f > 10 ** 9 else time.time() + f)
    except (TypeError, ValueError):
        pass
    try:
        s = str(v).replace('Z', '+00:00')
        return int(datetime.datetime.fromisoformat(s).timestamp())
    except ValueError:
        return 0


def extract_quota(payload, headers):
    """{limit, remaining, reset} from a response, or None when it reports none."""
    rate = None
    if isinstance(payload, dict):
        meta = payload.get('_meta') if isinstance(payload.get('_meta'), dict) else {}
        for cand in (meta.get('rate'), meta.get('quota'), payload.get('rate'), payload.get('quota')):
            if isinstance(cand, dict) and ('remaining' in cand or 'limit' in cand):
                rate = cand
                break
    q = {}
    if rate:
        q = {'limit': rate.get('limit'), 'remaining': rate.get('remaining'), 'reset': _epoch(rate.get('reset'))}
    else:
        for prefix in ('x-ratelimit-', 'x-quota-', 'x-free-daily-'):
            if prefix + 'remaining' in headers:
                q = {'limit': headers.get(prefix + 'limit'), 'remaining': headers.get(prefix + 'remaining'),
                     'reset': _epoch(headers.get(prefix + 'reset'))}
                break
    if not q:
        return None
    for k in ('limit', 'remaining'):
        try:
            q[k] = int(q[k]) if q[k] not in (None, '') else None
        except (TypeError, ValueError):
            q[k] = None
    q['seen_at'] = int(time.time())
    return q


def record_quota(service, q):
    if q:
        write_setting('tsvc_quota_' + service, json.dumps(q))


def quota(service):
    try:
        return json.loads(setting('tsvc_quota_' + service, '') or 'null')
    except ValueError:
        return None


def quota_blocked(service):
    """Seconds until the service's quota resets when it said it has none left; else 0."""
    q = quota(service)
    if not q or q.get('remaining') is None or q['remaining'] > 0:
        return 0
    wait = int(q.get('reset') or 0) - int(time.time())
    return wait if wait > 0 else 0


# ── Cache ──────────────────────────────────────────────────────────────────────

def cache_get(service, item, allow_stale=False):
    con = db_connect()
    try:
        ensure_schema(con)
        row = con.execute('SELECT status, body, fetched_at, expires_at FROM tsvc_cache WHERE service=? AND item=?',
                          (service, item)).fetchone()
    finally:
        con.close()
    if not row:
        return None
    status, body, fetched, expires = row
    if expires < time.time() and not allow_stale:
        return None
    try:
        data = json.loads(body or 'null')
    except ValueError:
        data = None
    return {'status': status, 'data': data, 'fetched_at': fetched, 'expires_at': expires,
            'stale': expires < time.time()}


def cache_put(service, item, data, ttl, status=200):
    now = int(time.time())
    con = db_connect()
    try:
        ensure_schema(con)
        con.execute(
            'INSERT INTO tsvc_cache (service, item, status, body, fetched_at, expires_at) VALUES (?,?,?,?,?,?) '
            'ON CONFLICT(service, item) DO UPDATE SET status=excluded.status, body=excluded.body, '
            'fetched_at=excluded.fetched_at, expires_at=excluded.expires_at',
            (service, item, status, json.dumps(data), now, now + int(ttl)))
        con.commit()
    finally:
        con.close()


# ── Notifications ──────────────────────────────────────────────────────────────
#
# A stock iNetPanel box has no MTA, so a webhook is the only way a notice
# reaches someone who is not looking at the panel. Discord and Slack take a
# small JSON body but disagree on the field name ("content" vs "text"), Discord
# caps a message at 2000 characters, and ntfy wants the message as the raw body.

def send_webhook(subject, body):
    url = read_secret('webhook_url')
    if not url:
        return False, 'No webhook URL is configured.'
    kind = webhook_kind(url)
    if not kind:
        return False, 'The saved webhook URL is not usable (it must be https://).'
    host = socket.gethostname()
    if len(body) > 1500:
        body = body[:1500] + '\n... (truncated — see the panel for the full detail)'
    text = '**%s** — %s\n```\n%s\n```' % (subject, host, body)
    if kind == 'discord':
        data, ctype, extra = json.dumps({'content': text}).encode(), 'application/json', {}
    elif kind == 'slack':
        data, ctype, extra = json.dumps({'text': text}).encode(), 'application/json', {}
    elif kind == 'ntfy':
        data, ctype, extra = ('%s\n\n%s' % (host, body)).encode(), 'text/plain; charset=utf-8', \
                             {'Title': subject.encode('ascii', 'replace').decode()}
    else:
        data = json.dumps({'text': text, 'subject': subject, 'host': host, 'body': body,
                           'source': 'inetpanel'}).encode()
        ctype, extra = 'application/json', {}
    hdrs = {'Content-Type': ctype, 'Accept': '*/*'}
    hdrs.update(extra)
    try:
        status, _, _ = http_request(url, 'POST', hdrs, data)
    except ServiceError as e:
        return False, str(e)
    if 200 <= status < 300:
        return True, 'Delivered.'
    return False, 'The webhook answered HTTP %d.' % status


def cmd_notify_test(args, as_json):
    ok, msg = send_webhook('Test notification',
                           'If you can read this, iNetPanel alerts will reach you here.')
    emit(as_json, {'success': ok, 'message': msg if ok else '', 'error': '' if ok else msg}, msg)
    return 0 if ok else 1


# ── qsa.sh ─────────────────────────────────────────────────────────────────────
#
# qsa.sh scans the PUBLIC IPv4 that calls it, so running it from this server
# shows what the internet can actually reach — a different question from what
# firewalld was told to open. iNetPanel is built to need no open TCP ports at all
# (sites come in through the Cloudflare Tunnel; WireGuard is UDP, which these
# scans never probe), so "0 open ports" is the result to expect, and anything
# else usually means a router port-forward or DMZ.
#
# Requests go through curl, not urllib, on purpose. qsa.sh classifies callers by
# User-Agent and only scans for a CLI ("^curl|Wget|HTTPie|PowerShell"); a library
# UA gets a usage page and a browser UA the marketing page — the first version
# of this integration hit exactly that. -4 is required: qsa.sh refuses IPv6
# origins, and curl prefers its AAAA record on any dual-stack host.

QSA_FREE_MAXTIME = 330      # qsa.sh's own cap plus the countdown; the server may cut sooner
QSA_CAPPED_AFTER = 115      # a free stream that ends with no summary after this long hit a time limit
QSA_POLL_EVERY   = 20
QSA_DEADLINE     = {'full': 20 * 60, 'deep': 40 * 60, '': 20 * 60}
QSA_FREE_COOLDOWN = 24 * 3600 - 15 * 60
QSA_ID_RE        = re.compile(r'scan-\d{8}T\d{6}z')

PORT_RE    = re.compile(r'^\s*(\d{1,5})/tcp\s+open\s+(\S+)[ \t]*(.*?)\s*$', re.M)
FINDING_RE = re.compile(r'^\s+\[([A-Za-z0-9][A-Za-z0-9 ._/-]{0,30})\]\s+(.+?)\s*$', re.M)
IP_RE      = re.compile(r'(?:Detected source IP|Scan complete for):?\s+(\d{1,3}(?:\.\d{1,3}){3})')
OPEN_RE    = re.compile(r'^\s*Open ports:\s*(\d+)', re.M)
FIND_RE    = re.compile(r'^\s*Findings:\s*(\d+)\s*(?:\(([^)]*)\))?', re.M)


def qsa_classify(text, elapsed=None, tier='free', http_status=200):
    """How a qsa.sh answer ended.

    complete      summary printed, qsa.sh did not flag it partial
    partial       summary printed, but qsa.sh says some checks did not run
    capped        cut off at a time limit (qsa.sh's own, or the stream just stopped
                  at about the two-minute mark with no summary)
    interrupted   the scan started but the stream ended early
    rate_limited  qsa.sh's per-IP allowance is used up
    refused       qsa.sh will not scan this connection (CGNAT, VPN, IPv6, opt-out)
    failed        nothing usable came back
    """
    t = text or ''
    if http_status == 429 or (re.search(r'Rate limit reached|daily scan allowance|try again in',
                                        t, re.I) and not OPEN_RE.search(t)):
        return 'rate_limited'
    if http_status in (400, 403):
        return 'refused'
    if OPEN_RE.search(t):
        if re.search(r'did not fully complete|time cap', t, re.I):
            return 'partial'
        return 'complete'
    if re.search(r'reached its time cap', t, re.I):
        return 'capped'
    started = bool(re.search(r'^\s*»\s*naabu|^> Scanning', t, re.M)) or bool(PORT_RE.search(t))
    if started:
        if tier == 'free' and elapsed is not None and elapsed >= QSA_CAPPED_AFTER:
            return 'capped'
        return 'interrupted'
    return 'failed'


QSA_STATUS_LABEL = {
    'complete': 'Complete', 'partial': 'Partial results', 'capped': 'Reached the time limit',
    'interrupted': 'Did not finish', 'rate_limited': 'Rate limited', 'refused': 'Refused by qsa.sh',
    'failed': 'Failed', 'token_rejected': 'Token not accepted', 'no_credits': 'No Deep credits left',
}

QSA_ARCHIVED = ('complete', 'partial', 'capped', 'interrupted')


def qsa_extract(text):
    """The parts of a report that describe exposure, in a stable order."""
    t = text or ''
    ports = []
    for m in PORT_RE.finditer(t):
        ports.append({'port': int(m.group(1)), 'service': m.group(2), 'version': m.group(3).rstrip('…').strip()})
    ports.sort(key=lambda p: p['port'])
    findings = sorted({'[%s] %s' % (m.group(1).strip(), m.group(2).strip()) for m in FINDING_RE.finditer(t)
                       if not m.group(2).startswith('Ctrl-C')})
    ip = IP_RE.search(t)
    op = OPEN_RE.search(t)
    fi = FIND_RE.search(t)
    sev = {}
    if fi and fi.group(2):
        for part in fi.group(2).split(','):
            m = re.match(r'\s*(\d+)\s+([a-z]+)', part)
            if m:
                sev[m.group(2)] = int(m.group(1))
    return {
        'ip': ip.group(1) if ip else '',
        'open_ports': int(op.group(1)) if op else (len(ports) if ports else None),
        'ports': ports,
        'findings': findings,
        'findings_total': int(fi.group(1)) if fi else len(findings),
        'severity': sev,
    }


def qsa_fingerprint(ex):
    """Lines that change only when exposure changes. Timing, countdowns,
    heartbeats and certificate countdowns never appear here."""
    lines = ['public IP %s' % ex['ip']] if ex.get('ip') else []
    lines += ['%d/tcp open %s %s' % (p['port'], p['service'], p['version']) for p in ex['ports']]
    lines += ex['findings']
    return lines


def qsa_diff(old_text, new_text):
    import difflib
    a = qsa_fingerprint(qsa_extract(old_text))
    b = qsa_fingerprint(qsa_extract(new_text))
    if a == b:
        return ''
    return '\n'.join(difflib.unified_diff(a, b, 'previous scan', 'this scan', lineterm='', n=0))


def qsa_clean_live(text):
    """What the panel shows while a scan runs: the newest countdown frame only,
    no heartbeat lines."""
    out, last_countdown = [], None
    for line in (text or '').splitlines():
        if re.search(r'Starting scan of .* in \d+s', line):
            last_countdown = line
            continue
        if re.fullmatch(r'\s*scanning…?\s*', line):
            continue
        if last_countdown is not None:
            out.append(last_countdown.rstrip(' ░▓'))
            last_countdown = None
        out.append(line)
    if last_countdown is not None:
        out.append(last_countdown.rstrip(' ░▓'))
    return '\n'.join(out).strip('\n')


def qsa_phase(text, state):
    t = text or ''
    if state.get('stage') in ('queued', 'polling'):
        return state['stage']
    for marker, name in (('» nuclei', 'nuclei'), ('» nmap', 'nmap'), ('» naabu', 'naabu')):
        if marker in t:
            return name
    if 'Starting scan of' in t:
        return 'countdown'
    return 'contacting'


def qsa_ensure_dir():
    if os.path.islink(QSA_DIR):
        raise OSError('%s is a symlink; refusing to use it' % QSA_DIR)
    os.makedirs(QSA_DIR, mode=0o700, exist_ok=True)
    os.chown(QSA_DIR, 0, 0)
    os.chmod(QSA_DIR, 0o700)


def qsa_fix_permissions():
    """Keep the archive root-only: root:root, 0700 / 0600."""
    if not os.path.isdir(QSA_DIR) or os.path.islink(QSA_DIR):
        return 0
    qsa_ensure_dir()
    fixed = 0
    for name in os.listdir(QSA_DIR):
        p = os.path.join(QSA_DIR, name)
        try:
            st = os.lstat(p)
        except OSError:
            continue
        if not os.path.isfile(p) or os.path.islink(p):
            continue
        if st.st_uid != 0 or st.st_gid != 0 or (st.st_mode & 0o077):
            os.chown(p, 0, 0)
            os.chmod(p, 0o600)
            fixed += 1
    return fixed


def _write_private(path, data):
    tmp = path + '.tmp'
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as fh:
        fh.write(data)
    os.replace(tmp, path)


def qsa_read_state():
    try:
        with open(QSA_STATE) as fh:
            st = json.load(fh)
            return st if isinstance(st, dict) else {}
    except (OSError, ValueError):
        return {}


def qsa_write_state(st):
    qsa_ensure_dir()
    _write_private(QSA_STATE, json.dumps(st))


def qsa_running():
    """True while a scan holds the lock."""
    try:
        fd = os.open(QSA_LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    except OSError:
        return False
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(fd)
        return True
    fcntl.flock(fd, fcntl.LOCK_UN)
    os.close(fd)
    return False


def qsa_token_tier():
    t = setting('qsa_token_tier', '')
    return t if t in ('full', 'deep') else ''


def _curl(url, out_path, max_time, extra=()):
    """Run curl, streaming the body to out_path. Returns (http_status, curl_rc, stderr).

    The URL goes to curl on stdin (-K -), never on its command line, so the
    token and the single-read results link stay out of process listings."""
    cmd = [CURL, '-4', '-sS', '-N', '--max-time', str(max_time), '-H', 'Accept: text/plain',
           '-o', out_path, '-w', '%{http_code}', '-K', '-'] + list(extra)
    if not re.fullmatch(r'[A-Za-z0-9:/._?&=%-]+', url):
        raise ServiceError('refusing an unexpected URL', 'error')
    p = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    p.stdin.write(('url = "%s"\n' % url).encode())
    p.stdin.close()
    follow = _INTERACTIVE
    pos = 0
    while p.poll() is None:
        if follow:
            pos = _echo_new(out_path, pos)
        time.sleep(0.5)
    if follow:
        _echo_new(out_path, pos)
    # stdin is already closed (the URL was written to it), so read the pipes
    # directly rather than through communicate(), which would flush stdin.
    so, se = p.stdout.read(), p.stderr.read()
    p.wait()
    try:
        status = int((so or b'0').decode().strip()[-3:] or 0)
    except ValueError:
        status = 0
    return status, p.returncode, (se or b'').decode('utf-8', 'replace').strip()


def _echo_new(path, pos):
    try:
        with open(path, 'rb') as fh:
            fh.seek(pos)
            chunk = fh.read()
    except OSError:
        return pos
    if chunk:
        sys.stdout.write(chunk.decode('utf-8', 'replace'))
        sys.stdout.flush()
    return pos + len(chunk)


def _read(path):
    try:
        with open(path, 'rb') as fh:
            return fh.read().decode('utf-8', 'replace')
    except OSError:
        return ''


def _no_ipv4():
    try:
        r = subprocess.run(['ip', '-4', 'route', 'get', '1.1.1.1'], capture_output=True, timeout=5)
        return r.returncode != 0
    except (OSError, subprocess.SubprocessError):
        return False


def qsa_scan_free(state):
    t0 = time.time()
    status, rc, err = _curl(QSA_BASE + '/?nocolor=1&scan=1', QSA_LIVE, QSA_FREE_MAXTIME, ['-H', 'X-QSA-Scan: 1'])
    elapsed = int(time.time() - t0)
    text = _read(QSA_LIVE)
    if status == 0 and not text.strip():
        if _no_ipv4():
            text = ('This server has no IPv4 address. qsa.sh cannot scan IPv6-only hosts yet '
                    '(see https://qsa.sh/contact).\n')
        else:
            text = 'Could not reach qsa.sh: %s\n' % (err or 'no response')
    return text, qsa_classify(text, elapsed, 'free', status), elapsed, status


def qsa_scan_token(state, token):
    t0 = time.time()
    queued = QSA_LIVE + '.queued'
    status, rc, err = _curl('%s/%s?nocolor=1' % (QSA_BASE, token), queued, 120)
    body = _read(queued)
    try:
        os.unlink(queued)
    except OSError:
        pass
    m = re.search(r'/r/([a-f0-9]{32})\b', body)
    if status != 202 or not m:
        if status == 404:
            text = ('qsa.sh did not accept the saved token (it answered 404). The token may be '
                    'mistyped, expired, or used up.\n')
            cls = 'token_rejected'
        elif status == 402:
            text = body or 'This Deep token has no scans remaining.\n'
            cls = 'no_credits'
        elif status == 0:
            text = 'Could not reach qsa.sh: %s\n' % (err or 'no response')
            cls = 'failed'
        else:
            text = body or ('qsa.sh answered HTTP %d.\n' % status)
            cls = qsa_classify(text, None, 'token', status)
            if cls in QSA_ARCHIVED:
                cls = 'failed'
        _write_private(QSA_LIVE, text)
        return text, cls, int(time.time() - t0), status

    # The queued message names the tier, so the panel learns whether this token
    # is a Full subscription or Deep credits from qsa.sh itself.
    tier = 'deep' if re.search(r'Deep scan queued', body) else 'full'
    if qsa_token_tier() != tier:
        write_setting('qsa_token_tier', tier, 'security')
        # Monitoring waits for this (see qsa_cron_wanted); schedule it, or drop
        # it for a Deep token, now that the tier is known.
        qsa_sync_cron()
    rt = m.group(1)
    state.update({'stage': 'queued', 'tier': tier, 'result_token': rt})
    qsa_write_state(state)
    _write_private(QSA_LIVE, body.strip() + '\n\nWaiting for the report...\n')

    deadline = t0 + QSA_DEADLINE[tier]
    url = '%s/r/%s?nocolor=1' % (QSA_BASE, rt)
    poll_file = QSA_LIVE + '.poll'
    while time.time() < deadline:
        time.sleep(QSA_POLL_EVERY)
        st, rc, err = _curl(url, poll_file, 120)
        txt = _read(poll_file)
        if st == 200 and re.search(r'still running', txt, re.I):
            state['stage'] = 'polling'
            qsa_write_state(state)
            _write_private(QSA_LIVE, body.strip() + '\n\n' + txt.strip() + '\n')
            continue
        if st == 200 and txt.strip():
            # Single-read: this was the only copy. Keep it before anything else.
            os.replace(poll_file, QSA_LIVE)
            os.chmod(QSA_LIVE, 0o600)
            elapsed = int(time.time() - t0)
            return txt, qsa_classify(txt, elapsed, tier, 200), elapsed, 200
        if st == 404:
            text = ('The results link expired or had already been read before this server '
                    'fetched it. Run the scan again.\n')
            _write_private(QSA_LIVE, text)
            return text, 'failed', int(time.time() - t0), st
        # 0 / 5xx: transient. Keep polling until the deadline.
    text = ('Gave up waiting for the report after %d minutes. If it finishes later it can be '
            'fetched once with: curl https://qsa.sh/r/%s\n' % (QSA_DEADLINE[tier] // 60, rt))
    _write_private(QSA_LIVE, text)
    return text, 'failed', int(time.time() - t0), 0


def qsa_meta_path(scan_id):
    return os.path.join(QSA_DIR, scan_id + '.json')


def qsa_archive(text, cls, elapsed, tier, trigger):
    scan_id = 'scan-' + time.strftime('%Y%m%dT%H%M%Sz', time.gmtime())
    ex = qsa_extract(text)
    meta = {'id': scan_id, 'ts': int(time.time()), 'status': cls, 'tier': tier, 'elapsed': elapsed,
            'trigger': trigger, 'ip': ex['ip'], 'open_ports': ex['open_ports'], 'ports': ex['ports'],
            'findings_total': ex['findings_total'], 'severity': ex['severity']}
    _write_private(os.path.join(QSA_DIR, scan_id + '.txt'), text)
    _write_private(qsa_meta_path(scan_id), json.dumps(meta))
    ids = sorted((n[:-4] for n in os.listdir(QSA_DIR) if n.endswith('.txt') and QSA_ID_RE.fullmatch(n[:-4])),
                 reverse=True)
    for old in ids[QSA_KEEP:]:
        for ext in ('.txt', '.json'):
            try:
                os.unlink(os.path.join(QSA_DIR, old + ext))
            except FileNotFoundError:
                pass
    return meta


def qsa_meta(scan_id):
    try:
        with open(qsa_meta_path(scan_id)) as fh:
            m = json.load(fh)
            if isinstance(m, dict):
                return m
    except (OSError, ValueError):
        pass
    # A scan archived before 1.29 has no sidecar: derive what we can.
    text = _read(os.path.join(QSA_DIR, scan_id + '.txt'))
    ex = qsa_extract(text)
    try:
        ts = int(datetime.datetime.strptime(scan_id[5:], '%Y%m%dT%H%M%Sz')
                 .replace(tzinfo=datetime.timezone.utc).timestamp())
    except ValueError:
        ts = 0
    return {'id': scan_id, 'ts': ts, 'status': qsa_classify(text, None, 'unknown'), 'tier': '',
            'elapsed': None, 'trigger': '', 'ip': ex['ip'], 'open_ports': ex['open_ports'],
            'ports': ex['ports'], 'findings_total': ex['findings_total'], 'severity': ex['severity']}


def qsa_history(limit=30):
    if not os.path.isdir(QSA_DIR):
        return []
    ids = sorted((n[:-4] for n in os.listdir(QSA_DIR) if n.endswith('.txt') and QSA_ID_RE.fullmatch(n[:-4])),
                 reverse=True)
    return [qsa_meta(i) for i in ids[:limit]]


def qsa_record_result(meta, cls):
    write_setting('qsa_last_run', now_str(), 'security')
    write_setting('qsa_last_status', cls, 'security')
    if meta:
        write_setting('qsa_last_scan_id', meta['id'], 'security')
        write_setting('qsa_last_open_ports', '' if meta['open_ports'] is None else str(meta['open_ports']),
                      'security')
        if meta['status'] == 'complete':
            write_setting('qsa_last_complete', now_str(), 'security')
            write_setting('qsa_last_complete_ports',
                          '' if meta['open_ports'] is None else str(meta['open_ports']), 'security')
            write_setting('qsa_last_ip', meta.get('ip') or '', 'security')


def qsa_run(trigger='manual'):
    """Run one scan in this process. Returns (meta-or-None, class, text)."""
    qsa_ensure_dir()
    lock = os.open(QSA_LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    # qsa_running() takes this lock for an instant to test it, and the page
    # polls right after starting a scan; retry briefly instead of reporting busy.
    for attempt in range(30):
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except OSError:
            if attempt == 29:
                os.close(lock)
                return None, 'busy', 'A scan is already running.'
            time.sleep(0.1)
    try:
        token = read_secret('qsa_token')
        if not token and setting('qsa_token', ''):
            migrate_legacy()            # an update that has not run `tsvc migrate` yet
            token = read_secret('qsa_token')
        tier = qsa_token_tier() if token else 'free'
        state = {'state': 'running', 'stage': 'contacting', 'started_at': int(time.time()),
                 'tier': tier or 'token', 'trigger': trigger, 'pid': os.getpid()}
        qsa_write_state(state)
        _write_private(QSA_LIVE, '')
        if token:
            text, cls, elapsed, status = qsa_scan_token(state, token)
            tier = state.get('tier') or tier or 'full'
        else:
            text, cls, elapsed, status = qsa_scan_free(state)
        meta = qsa_archive(text, cls, elapsed, tier, trigger) if cls in QSA_ARCHIVED else None
        qsa_record_result(meta, cls)
        state.update({'state': 'finished', 'finished_at': int(time.time()), 'status': cls,
                      'elapsed': elapsed, 'http_status': status, 'scan_id': meta['id'] if meta else '',
                      'tier': tier})
        state.pop('result_token', None)
        qsa_write_state(state)
        level = 'INFO' if cls == 'complete' else 'WARN'
        log('qsa %s scan (%s): %s in %ss' % (trigger, tier, cls, elapsed), level, QSA_LOG)
        return meta, cls, text
    finally:
        fcntl.flock(lock, fcntl.LOCK_UN)
        os.close(lock)


def qsa_spawn():
    # The scan lives in its own unit, so a php-fpm restart cannot kill it.
    return spawn_detached(['qsa', 'run', '--background'], 'inetpanel-qsa-scan',
                          'iNetPanel qsa.sh exposure scan', QSA_LOG)


def qsa_settings_view():
    keys = ['qsa_monitor', 'qsa_monitor_schedule', 'qsa_last_run', 'qsa_last_change', 'qsa_change_unacked',
            'qsa_token_set', 'qsa_token_tier', 'webhook_url_set', 'tsvc_webhook_kind', 'qsa_last_status',
            'qsa_last_scan_id', 'qsa_last_open_ports', 'qsa_last_complete', 'qsa_last_ip']
    s = read_settings(keys)
    return {
        'has_token': s.get('qsa_token_set') == '1',
        'token_tier': s.get('qsa_token_tier', ''),
        'monitor': s.get('qsa_monitor') == '1',
        'monitor_active': os.path.exists(QSA_CRON),
        'schedule': s.get('qsa_monitor_schedule') or 'daily',
        'last_run': s.get('qsa_last_run', ''),
        'last_change': s.get('qsa_last_change', ''),
        'unacked': s.get('qsa_change_unacked') == '1',
        'webhook_set': s.get('webhook_url_set') == '1',
        'webhook_kind': s.get('tsvc_webhook_kind', ''),
        'last_status': s.get('qsa_last_status', ''),
        'last_scan_id': s.get('qsa_last_scan_id', ''),
        'last_open_ports': s.get('qsa_last_open_ports', ''),
        'last_complete': s.get('qsa_last_complete', ''),
        'last_ip': s.get('qsa_last_ip', ''),
    }


def qsa_cron_spec(schedule):
    s = read_settings(['qsa_cron_minute', 'qsa_cron_hour'])
    try:
        minute = int(s.get('qsa_cron_minute', ''))
    except ValueError:
        minute = -1
    try:
        hour = int(s.get('qsa_cron_hour', ''))
    except ValueError:
        hour = -1
    # Spread installs over the night instead of every server asking qsa.sh for a
    # scan at the same minute. Chosen once, then kept.
    if not 0 <= minute <= 59:
        minute = random.randint(0, 59)
        write_setting('qsa_cron_minute', str(minute), 'security')
    if not 1 <= hour <= 5:
        hour = random.randint(1, 5)
        write_setting('qsa_cron_hour', str(hour), 'security')
    return {'hourly': '%d * * * *' % minute,
            'daily': '%d %d * * *' % (minute, hour),
            'weekly': '%d %d * * 1' % (minute, hour)}[schedule]


def qsa_cron_wanted():
    """(wanted, why-not) for the monitoring cron, from the saved settings."""
    if not module_enabled('qsa'):
        return False, 'the External Security Audit module is off'
    s = read_settings(['qsa_monitor', 'qsa_monitor_schedule'])
    if s.get('qsa_monitor') != '1':
        return False, 'monitoring is off'
    has_token = bool(read_secret('qsa_token'))
    tier = qsa_token_tier() if has_token else 'free'
    if tier == 'deep':
        return False, 'a Deep token is never scheduled (every scan spends a credit)'
    if tier == '':
        # Full or Deep is only known once qsa.sh has answered a scan with this
        # token; until then a scheduled scan could spend a Deep credit.
        return False, 'run one scan with the token first, so the panel can confirm it is a Full token'
    if s.get('qsa_monitor_schedule') == 'hourly' and tier != 'full':
        return False, 'hourly scans need a Full token'
    return True, ''


def qsa_sync_cron():
    want, why = qsa_cron_wanted()
    if want:
        schedule = setting('qsa_monitor_schedule', 'daily')
        if schedule not in ('hourly', 'daily', 'weekly'):
            schedule = 'daily'
        content = ('# iNetPanel qsa.sh exposure monitor - managed by the panel (Firewall -> Exposure Scan)\n'
                   '# /etc/cron.d entries do not inherit PATH from /etc/crontab.\n'
                   'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
                   '%s root %s -I /root/scripts/tuxxin_services.py qsa monitor >> %s 2>&1\n'
                   % (qsa_cron_spec(schedule), PYTHON, QSA_LOG))
        _write_cron(QSA_CRON, content)
    else:
        try:
            os.unlink(QSA_CRON)
        except FileNotFoundError:
            pass
    return want, why


def _write_cron(path, content):
    try:
        with open(path) as fh:
            if fh.read() == content:
                return
    except OSError:
        pass
    tmp = path + '.tmp'
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    with os.fdopen(fd, 'w') as fh:
        fh.write(content)
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)


def qsa_monitor():
    """Scheduled scan: compare with the last complete scan and alert on change."""
    trim_log(QSA_LOG)
    if not module_enabled('qsa'):
        log('monitor: the module is off; removing the schedule', 'INFO', QSA_LOG)
        qsa_sync_cron()
        return 0
    has_token = bool(read_secret('qsa_token'))
    tier = qsa_token_tier() if has_token else 'free'
    if tier in ('deep', ''):
        log('monitor: not scanning — %s' % ('a Deep token is never scheduled' if tier == 'deep'
            else 'the token type is not confirmed yet (run one manual scan)'), 'WARN', QSA_LOG)
        qsa_sync_cron()
        return 0
    if tier == 'free':
        last = qsa_history(1)
        if last and time.time() - (last[0].get('ts') or 0) < QSA_FREE_COOLDOWN:
            log('monitor: skipped; the free tier allows one scan per 24 hours and the last one ran %s'
                % now_str(last[0]['ts']), 'INFO', QSA_LOG)
            return 0
    meta, cls, text = qsa_run('monitor')
    if cls == 'busy':
        log('monitor: a scan was already running', 'INFO', QSA_LOG)
        return 0
    if cls != 'complete':
        # A cut-off or refused scan says nothing reliable about what changed, so
        # it never moves the baseline and never raises an alert.
        panel_log('qsa', 'WARNING', 'Scheduled exposure scan: %s' % QSA_STATUS_LABEL.get(cls, cls))
        return 0
    baseline = _read(QSA_BASELINE)
    if baseline and setting('qsa_baseline_tier', '') != (meta.get('tier') or ''):
        # A free scan (top 1,000 ports) and a Full one (all 65,535) differ
        # without anything having changed. Start a new baseline instead.
        log('monitor: scan depth changed (%s -> %s); starting a new baseline'
            % (setting('qsa_baseline_tier', '') or 'unknown', meta.get('tier')), 'INFO', QSA_LOG)
        baseline = ''
    diff = qsa_diff(baseline, text) if baseline else ''
    if baseline and diff:
        if os.path.exists(QSA_BASELINE):
            os.replace(QSA_BASELINE, QSA_PREVIOUS)
        _write_private(QSA_BASELINE, text)
        added = len([l for l in diff.splitlines() if l.startswith('+') and not l.startswith('+++')])
        removed = len([l for l in diff.splitlines() if l.startswith('-') and not l.startswith('---')])
        # Counts only in the logs table; the detail stays in the root-only
        # archive and on the Firewall page.
        panel_log('qsa', 'WARNING', 'External exposure changed',
                  '%d line(s) added, %d removed. Open ports now: %s. See Firewall -> Exposure Scan.'
                  % (added, removed, meta['open_ports']))
        write_setting('qsa_last_change', now_str(), 'security')
        write_setting('qsa_change_unacked', '1', 'security')
        ok, msg = send_webhook('Exposure changed',
                               'Open ports now: %s\n\n%s' % (meta['open_ports'], diff))
        if not ok and read_secret('webhook_url'):
            log('monitor: webhook delivery failed: %s' % msg, 'WARN', QSA_LOG)
    else:
        _write_private(QSA_BASELINE, text)
        panel_log('qsa', 'INFO', 'External exposure unchanged' if baseline else 'External exposure baseline recorded')
    write_setting('qsa_baseline_tier', meta.get('tier') or '', 'security')
    return 0


def cmd_qsa(args, as_json):
    sub = args[0] if args else 'run'
    if sub in ('run',):
        background = '--background' in args
        if not module_enabled('qsa'):
            if background:
                st = qsa_read_state()
                st.update({'state': 'finished', 'status': 'failed', 'finished_at': int(time.time())})
                qsa_write_state(st)
                _write_private(QSA_LIVE, 'The External Security Audit was turned off before the scan started.\n')
            emit(as_json, {'success': False, 'error': 'The External Security Audit module is turned off '
                           'in Settings -> Tuxxin Services.'}, 'qsa: the module is turned off')
            return 1
        meta, cls, text = qsa_run('manual')
        if cls == 'busy':
            emit(as_json, {'success': False, 'error': text}, text)
            return 1
        if as_json:
            emit(True, {'success': cls in QSA_ARCHIVED, 'status': cls, 'meta': meta, 'output': text})
        elif not _INTERACTIVE and not background:
            # A background run's stdout is a log file; the report stays in the archive.
            print(text)
        if not as_json:
            print('\n[%s]' % QSA_STATUS_LABEL.get(cls, cls))
        return 0 if cls in QSA_ARCHIVED else 1
    if sub == 'start':
        if not module_enabled('qsa'):
            emit(as_json, {'success': False, 'error': 'The External Security Audit module is turned off '
                           'in Settings -> Tuxxin Services.'})
            return 1
        if qsa_running():
            emit(as_json, {'success': False, 'error': 'A scan is already running.', 'running': True})
            return 1
        qsa_ensure_dir()
        qsa_write_state({'state': 'starting', 'stage': 'contacting', 'started_at': int(time.time()),
                         'trigger': 'manual'})
        _write_private(QSA_LIVE, '')
        how = qsa_spawn()
        emit(as_json, {'success': True, 'started': True, 'how': how}, 'scan started (%s)' % how)
        return 0
    if sub == 'status':
        st = qsa_read_state()
        st.pop('result_token', None)
        running = qsa_running()
        if st.get('state') == 'starting' and not running and time.time() - st.get('started_at', 0) > 90:
            st['state'] = 'finished'
            st['status'] = st.get('status') or 'failed'
        if st.get('state') == 'running' and not running:
            st['state'] = 'finished'           # the worker died without recording an end
            st['status'] = st.get('status') or 'interrupted'
        busy = running or st.get('state') == 'starting'
        live = _read(QSA_LIVE) if (busy or st.get('state') == 'running') else ''
        payload = {'success': True, 'running': busy, 'state': st, 'phase': qsa_phase(live, st),
                   'live': qsa_clean_live(live)[-20000:],
                   'elapsed': int(time.time() - st['started_at']) if st.get('started_at') else 0,
                   'settings': qsa_settings_view()}
        if not busy and st.get('state') == 'finished':
            payload['status_label'] = QSA_STATUS_LABEL.get(st.get('status', ''), st.get('status', ''))
            if st.get('scan_id'):
                payload['result'] = qsa_meta(st['scan_id'])
            else:
                # Refused, rate-limited, token rejected: nothing was archived, but
                # qsa.sh's own explanation is what the admin needs to see.
                payload['final_output'] = qsa_clean_live(_read(QSA_LIVE))[-8000:]
        emit(as_json, payload, json.dumps(payload, indent=2))
        return 0
    if sub == 'history':
        emit(as_json, {'success': True, 'scans': qsa_history()},
             '\n'.join('%s  %-12s ports=%s' % (m['id'], m['status'], m['open_ports']) for m in qsa_history()))
        return 0
    if sub == 'show':
        scan_id = args[1] if len(args) > 1 else ''
        if not QSA_ID_RE.fullmatch(scan_id) or not os.path.isfile(os.path.join(QSA_DIR, scan_id + '.txt')):
            emit(as_json, {'success': False, 'error': 'No such scan in the archive.'}, 'no such scan')
            return 1
        text = _read(os.path.join(QSA_DIR, scan_id + '.txt'))
        emit(as_json, {'success': True, 'meta': qsa_meta(scan_id), 'output': text}, text)
        return 0
    if sub == 'diff':
        old, new = _read(QSA_PREVIOUS), _read(QSA_BASELINE)
        if not old or not new:
            emit(as_json, {'success': False, 'has_history': False, 'diff': '',
                           'error': 'Not enough history yet: the first change has not been seen.'},
                 'Not enough history yet.')
            return 1
        d = qsa_diff(old, new)
        emit(as_json, {'success': True, 'has_history': True, 'diff': d}, d or 'No difference.')
        return 0
    if sub == 'monitor':
        return qsa_monitor()
    if sub == 'sync-cron':
        want, why = qsa_sync_cron()
        emit(as_json, {'success': True, 'active': want, 'reason': why},
             'monitoring %s%s' % ('scheduled' if want else 'not scheduled', '' if want else ': ' + why))
        return 0
    emit(as_json, {'success': False, 'error': 'unknown qsa command'}, 'usage: inetp tsvc qsa '
         'run|start|status|history|show <id>|diff|monitor|sync-cron')
    return 2


# ── Migration ──────────────────────────────────────────────────────────────────

def migrate_legacy():
    """Idempotent. Moves the qsa.sh token and webhook URL into the secrets
    directory and makes the qsa archive root-only."""
    ensure_schema()
    s = read_settings(['qsa_token', 'qsa_webhook_url', 'qsa_webhook_kind', 'qsa_monitor'])
    moved = []
    tok = (s.get('qsa_token') or '').strip()
    if tok:
        if SECRET_RULES['qsa_token'].fullmatch(tok):
            store_secret('qsa_token', tok)
            moved.append('qsa_token')
        else:
            log('migrate: the saved qsa.sh token is not 32 hex characters; qsa.sh would reject it, '
                'so it was not kept', 'WARN')
        write_setting('qsa_token', '', 'security')
    hook = (s.get('qsa_webhook_url') or '').strip()
    if hook:
        if webhook_kind(hook):
            store_secret('webhook_url', hook)
            write_setting('tsvc_webhook_kind', webhook_kind(hook))
            moved.append('webhook_url')
        write_setting('qsa_webhook_url', '', 'security')
        write_setting('qsa_webhook_kind', '', 'security')
    # Before 1.29 "monitoring on" was only the existence of the cron file.
    if 'qsa_monitor' not in s:
        write_setting('qsa_monitor', '1' if os.path.exists(QSA_CRON) else '0', 'security')
    fixed = qsa_fix_permissions()
    # Before 1.29 a scan cut off at the time limit could become the baseline;
    # comparing a complete scan with it would report a change that is not one.
    if os.path.exists(QSA_BASELINE) and qsa_classify(_read(QSA_BASELINE), None, 'unknown') != 'complete':
        for f in (QSA_BASELINE, QSA_PREVIOUS):
            try:
                os.unlink(f)
            except FileNotFoundError:
                pass
        log('migrate: dropped an incomplete qsa.sh baseline; the next complete scan sets a new one')
    scrub_legacy_logs()
    ensure_state_dirs()
    if os.path.exists(QSA_CRON) or s.get('qsa_monitor') == '1':
        if read_secret('qsa_token') and not qsa_token_tier():
            panel_log('qsa', 'INFO', 'Exposure monitoring paused until one manual scan confirms the token type',
                      'A scheduled scan with a Deep token would spend a paid credit, so monitoring with a '
                      'token waits until a manual scan from Firewall -> Exposure Scan shows it is a Full token.')
        qsa_sync_cron()
    sync_worker_cron()
    write_setting('tsvc_migrated', MIGRATION_MARK)
    return moved, fixed


def scrub_legacy_logs():
    """Older versions kept qsa.sh scan output and change diffs in log rows and
    in /var/log/qsa-monitor.log. Keep the rows, drop the detail, and make the
    log file root-only."""
    try:
        con = db_connect()
        try:
            n = con.execute(
                "UPDATE logs SET details = '[scan output removed in 1.29: it is kept root-only now]' "
                "WHERE ((source = 'shell' AND message LIKE 'inetp qsa_scan%') "
                "OR (source = 'qsa' AND message = 'External exposure changed')) "
                "AND coalesce(details, '') NOT LIKE '[scan output removed%' AND coalesce(details, '') != ''").rowcount
            con.commit()
        finally:
            con.close()
        if n:
            log('migrate: removed qsa.sh scan detail from %d shared log row(s)' % n)
    except sqlite3.Error as e:
        log('migrate: could not scrub the logs table: %s' % e, 'WARN')
    for f in (QSA_LOG, QSA_LOG + '.1'):
        try:
            if os.path.isfile(f) and not os.path.islink(f):
                os.chown(f, 0, 0)
                os.chmod(f, 0o600)
        except OSError:
            pass


def cmd_migrate(args, as_json):
    moved, fixed = migrate_legacy()
    emit(as_json, {'success': True, 'moved': moved, 'permissions_fixed': fixed},
         'moved: %s; qsa archive files re-permissioned: %d' % (', '.join(moved) or 'nothing', fixed))
    return 0


# ── Status / secrets commands ──────────────────────────────────────────────────

def cmd_status(args, as_json):
    master, mods = module_states()
    secrets_set = {n: bool(read_secret(n)) for n in SECRET_RULES}
    payload = {'success': True, 'master': master, 'modules': mods, 'secrets': secrets_set,
               'quotas': {m: quota(m) for m in ('worldip', 'issued', 'webshot', 'whack')},
               'worker_cron': os.path.exists(WORKER_CRON), 'qsa': qsa_settings_view()}
    emit(as_json, payload, json.dumps(payload, indent=2))
    return 0


def cmd_set_secret(args, as_json):
    name = args[0] if args else ''
    if name not in SECRET_RULES:
        emit(as_json, {'success': False, 'error': 'Unknown key name.'}, 'unknown key name')
        return 1
    if sys.stdin.isatty():
        value = getpass.getpass('%s: ' % name).strip()
    else:
        value = sys.stdin.read(4096).strip()
    if not SECRET_RULES[name].fullmatch(value):
        err = {'qsa_token': 'A qsa.sh token is exactly 32 hexadecimal characters.',
               'webhook_url': 'The webhook must be an https:// URL.'}.get(
            name, 'That key contains characters an API key does not use, or is too short.')
        emit(as_json, {'success': False, 'error': err}, err)
        return 1
    store_secret(name, value)
    if name == 'webhook_url':
        write_setting('tsvc_webhook_kind', webhook_kind(value))
    if name == 'qsa_token':
        # The tier is learned from qsa.sh on the first scan with this token.
        write_setting('qsa_token_tier', '', 'security')
        qsa_sync_cron()
    emit(as_json, {'success': True, 'kind': webhook_kind(value) if name == 'webhook_url' else ''}, 'saved')
    return 0


def cmd_clear_secret(args, as_json):
    name = args[0] if args else ''
    if name not in SECRET_RULES:
        emit(as_json, {'success': False, 'error': 'Unknown key name.'}, 'unknown key name')
        return 1
    remove_secret(name)
    if name == 'webhook_url':
        write_setting('tsvc_webhook_kind', '')
    if name == 'qsa_token':
        write_setting('qsa_token_tier', '', 'security')
        qsa_sync_cron()
    emit(as_json, {'success': True}, 'removed')
    return 0


# ── Service modules (worldip, issued, webshot, whack) ──────────────────────────
def spawn_detached(cmd_args, unit_prefix, description, log_path):
    """Run this script with cmd_args in its own transient unit, returning at once."""
    cmd = [PYTHON, '-I', SCRIPT_PATH] + cmd_args
    for sr in ('/usr/bin/systemd-run', '/bin/systemd-run'):
        if not os.access(sr, os.X_OK):
            continue
        unit = '%s-%d-%d' % (unit_prefix, int(time.time()), os.getpid())
        try:
            r = subprocess.run([sr, '--quiet', '--collect', '--unit=' + unit, '--property=Type=exec',
                                '--description=' + description] + cmd,
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                               stderr=subprocess.PIPE, timeout=60)
            if r.returncode == 0:
                return 'systemd-run'
            log('systemd-run failed (%s); starting in the background instead'
                % r.stderr.decode(errors='replace').strip()[:200], 'WARN', log_path)
        except (OSError, subprocess.SubprocessError) as e:
            log('systemd-run unavailable (%s); starting in the background instead' % e, 'WARN', log_path)
        break
    fd = os.open(log_path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o640)
    try:
        subprocess.Popen(cmd, stdin=subprocess.DEVNULL, stdout=fd, stderr=fd,
                         start_new_session=True, close_fds=True)
    finally:
        os.close(fd)
    return 'background process'


DOMAIN_RE = re.compile(r'(?=.{1,253}$)(?:(?!-)[a-z0-9-]{1,63}(?<!-)\.)+(?!-)[a-z0-9-]{2,63}(?<!-)')


def hosted_domain(name):
    """The name, lower-cased, if it is a domain hosted on this server. Services
    that fetch a site (screenshots, malware scans, expiry lookups) only ever
    get one of this server's own domains, whatever the caller passes."""
    d = (name or '').strip().lower().rstrip('.')
    if not DOMAIN_RE.fullmatch(d):
        raise ServiceError('not a valid domain name', 'bad_input')
    con = db_connect()
    try:
        row = con.execute('SELECT domain_name FROM domains WHERE lower(domain_name) = ?', (d,)).fetchone()
    finally:
        con.close()
    if not row:
        raise ServiceError('%s is not hosted on this server' % d, 'bad_input')
    return d


def active_domains():
    con = db_connect()
    try:
        rows = con.execute("SELECT domain_name FROM domains WHERE coalesce(status, 'active') = 'active' "
                           "ORDER BY domain_name").fetchall()
    finally:
        con.close()
    return [r[0].lower() for r in rows if r[0] and DOMAIN_RE.fullmatch(r[0].lower())]


def ensure_state_dirs():
    """STATE_DIR and SHOTS_DIR are root's, 0711: traversable, not listable or
    writable by anyone else. Each screenshot file is owned by www-data (0600)
    so the panel can serve it; a directory www-data could write would let it
    swap a file root is about to touch for a symlink."""
    for d in (STATE_DIR, SHOTS_DIR):
        if os.path.islink(d):
            raise OSError('%s is a symlink; refusing to use it' % d)
        os.makedirs(d, mode=0o711, exist_ok=True)
        st = os.lstat(d)
        if (st.st_uid, st.st_gid) != (0, 0):
            os.chown(d, 0, 0)
        os.chmod(d, 0o711)


def require_module(name):
    if not module_enabled(name):
        raise ServiceError('This module is turned off in Settings -> Tuxxin Services.', 'disabled')


def _auth(name):
    key = read_secret(name)
    return {'Authorization': 'Bearer ' + key} if key else {}


def _retry_after(headers, payload, default=60):
    for v in (headers.get('retry-after'), (payload or {}).get('retry_after'),
              (payload or {}).get('retry_after_sec')):
        try:
            if v not in (None, ''):
                return max(1, int(float(v)))
        except (TypeError, ValueError):
            pass
    return default


def _quota_error(service, wait):
    when = time.strftime('%H:%M', time.localtime(time.time() + wait))
    return ServiceError('%s says this server has used its free allowance; it resets around %s.'
                        % (service, when), 'quota')


# ── worldip.io ─────────────────────────────────────────────────────────────────

WORLDIP_TTL = 24 * 3600


def worldip_trim(d, keyed):
    """One shape for the keyless and keyed answers (they differ: the keyed
    'forward_dns' is a hosted-domain object, not the rDNS check)."""
    fwd = d.get('forward_dns') if isinstance(d.get('forward_dns'), dict) else {}
    meta = d.get('_meta') if isinstance(d.get('_meta'), dict) else {}
    asn = d.get('asn') if isinstance(d.get('asn'), dict) else {}
    org = d.get('org') if isinstance(d.get('org'), dict) else {}
    country = d.get('country') if isinstance(d.get('country'), dict) else {}
    hosted = d.get('domains_hosted') if isinstance(d.get('domains_hosted'), dict) else {}
    out = {
        'ip': d.get('ip', ''),
        'ptr': d.get('ptr') or '',
        'forward_confirmed': fwd.get('confirmed') if 'confirmed' in fwd else None,
        'asn': asn.get('number'),
        'asn_name': asn.get('name') or '',
        'org': org.get('name') or '',
        'country_code': country.get('code') or '',
        'country': country.get('name') or '',
        'region': d.get('region') or '',
        'city': d.get('city') or '',
        'cidr': d.get('cidr') or '',
        'reserved': bool(d.get('reserved')),
        'domains_hosted': hosted.get('count'),
        'attribution': d.get('attribution') or meta.get('attribution') or '',
        'keyed': keyed,
    }
    cls = d.get('classification')
    if isinstance(cls, dict):
        out['classification'] = {k: cls.get(k) for k in ('verdict', 'is_vpn', 'is_proxy', 'is_tor',
                                                         'is_datacenter', 'is_relay', 'confidence')}
    trust = d.get('trust')
    if isinstance(trust, dict):
        out['trust'] = {'score': trust.get('score'), 'band': trust.get('band')}
    if isinstance(d.get('flags'), list):
        out['flags'] = [f.get('label') for f in d['flags'] if isinstance(f, dict) and f.get('label')][:8]
    return out


def worldip_lookup(target, refresh=False):
    require_module('worldip')
    if target == 'self':
        item = 'self'
    else:
        try:
            ip = ipaddress.ip_address(target.strip())
        except ValueError:
            raise ServiceError('not an IP address', 'bad_input')
        if ip.version == 6 and ip.ipv4_mapped:
            ip = ip.ipv4_mapped
        if not ip.is_global:
            # A LAN, loopback or reserved address has no public profile; asking
            # would only spend quota.
            return {'ip': str(ip), 'reserved': True, 'private': True}, False
        item = str(ip)
    if not refresh:
        c = cache_get('worldip', item)
        if c and isinstance(c['data'], dict):
            if c['data'].get('error'):
                raise ServiceError(c['data']['error'], 'unreachable')
            return c['data'], True
    wait = quota_blocked('worldip')
    if wait:
        c = cache_get('worldip', item, allow_stale=True)
        if c and isinstance(c['data'], dict):
            return c['data'], True
        raise _quota_error('worldip.io', wait)
    auth = _auth('worldip_key')
    keyed = bool(auth)
    data = status = None
    if keyed and item != 'self':
        # The enriched profile (VPN / proxy / Tor, trust score) needs a key with
        # the right scope; a key without it still lifts the free daily cap.
        status, hdrs, body = http_request(WORLDIP_BASE + '/api/v1/ext/lookup/' + item, headers=auth, ipv4=True)
        data = parse_json(body)
        record_quota('worldip', extract_quota(data, hdrs))
        if status in (401, 403, 404):
            data = None
    if data is None or status != 200:
        url = WORLDIP_BASE + '/' + ('' if item == 'self' else item)
        # Always IPv4: the free allowance is per calling address, so one address
        # family keeps one allowance — and 'self' must be the IPv4 qsa.sh scans.
        try:
            status, hdrs, body = http_request(url, headers=auth, ipv4=True)
        except ServiceError as e:
            if item == 'self':
                # Every dashboard and portal view asks for this one; while the
                # service is unreachable, ask once an hour, not on every view.
                cache_put('worldip', 'self', {'error': str(e)}, ERROR_TTL, 0)
            raise
        data = parse_json(body)
        record_quota('worldip', extract_quota(data, hdrs))
    if status == 200 and isinstance(data, dict) and data.get('ip'):
        out = worldip_trim(data, keyed)
        cache_put('worldip', item, out, WORLDIP_TTL)
        if item == 'self':
            write_setting('tsvc_public_ip', out['ip'])
        return out, False
    if status == 429:
        q = quota('worldip') or {}
        q.update({'remaining': 0, 'reset': int(time.time()) + _retry_after(hdrs, data, 3600),
                  'seen_at': int(time.time())})
        record_quota('worldip', q)
        raise _quota_error('worldip.io', _retry_after(hdrs, data, 3600))
    if status in (401, 403):
        raise ServiceError('worldip.io did not accept the saved API key (%s).'
                           % ((data or {}).get('error') or status), 'bad_key', status)
    if status == 400:
        raise ServiceError('worldip.io could not look up that address.', 'bad_input', status)
    raise ServiceError('worldip.io answered HTTP %s.' % status, 'error', status or 0)


def cmd_worldip(args, as_json):
    target = args[0] if args else ''
    data, cached = worldip_lookup(target, '--refresh' in args)
    emit(as_json, {'success': True, 'data': data, 'cached': cached, 'quota': quota('worldip')},
         json.dumps(data, indent=2))
    return 0


# ── issued.live ────────────────────────────────────────────────────────────────

ISSUED_TTL = 20 * 3600
ERROR_TTL = 3600              # a failed lookup is not retried for an hour
ISSUED_MISS_TTL = 12 * 3600
ISSUED_FIELDS = ('domain', 'registered', 'expires', 'ssl_expires', 'ssl_cert', 'ssl_issuer', 'ip',
                 'ip_observed_at', 'last_update', 'first_cert_seen', 'tracked', 'first_zone_seen')


def issued_lookup(domain, refresh=False, require=True):
    if require:
        require_module('issued')
    d = hosted_domain(domain)
    if not refresh:
        c = cache_get('issued', d)
        if c and isinstance(c['data'], dict):
            return c['data'], True
    wait = quota_blocked('issued')
    if wait:
        c = cache_get('issued', d, allow_stale=True)
        if c and isinstance(c['data'], dict):
            return c['data'], True
        raise _quota_error('issued.live', wait)
    # Accept: application/json, or issued.live serves HTML to a non-curl UA.
    try:
        status, hdrs, body = http_request(ISSUED_BASE + '/' + urllib.parse.quote(d), headers=_auth('issued_key'))
    except ServiceError as e:
        cache_put('issued', d, {'found': None, 'error': str(e), 'asked': d, 'fetched': int(time.time())},
                  ERROR_TTL, 0)
        raise
    data = parse_json(body)
    # The counters only come on no-store answers (errors, 404s); a cacheable 200
    # carries none, so keep the last value we saw.
    record_quota('issued', extract_quota(data, hdrs))
    if status == 200 and isinstance(data, dict) and data.get('domain'):
        out = {k: data.get(k) for k in ISSUED_FIELDS}
        out['found'] = True
        out['asked'] = d
        out['fetched'] = int(time.time())
        cache_put('issued', d, out, ISSUED_TTL)
        return out, False
    if status == 404:
        out = {'found': False, 'asked': d, 'fetched': int(time.time())}
        cache_put('issued', d, out, ISSUED_MISS_TTL, 404)
        return out, False
    if status == 429:
        wait = _retry_after(hdrs, data, 3600)
        record_quota('issued', {'limit': (quota('issued') or {}).get('limit'), 'remaining': 0,
                                'reset': int(time.time()) + wait, 'seen_at': int(time.time())})
        raise _quota_error('issued.live', wait)
    if status == 401:
        raise ServiceError('issued.live did not accept the saved API key.', 'bad_key', status)
    err = ServiceError('issued.live answered HTTP %s.' % status, 'error', status or 0)
    cache_put('issued', d, {'found': None, 'error': str(err), 'asked': d, 'fetched': int(time.time())},
              ERROR_TTL, 0)
    raise err


def cmd_issued(args, as_json):
    data, cached = issued_lookup(args[0] if args else '', '--refresh' in args)
    emit(as_json, {'success': True, 'data': data, 'cached': cached}, json.dumps(data, indent=2))
    return 0


# ── webshot.site ───────────────────────────────────────────────────────────────

SHOT_TTL = 7 * 86400
SHOT_FAIL_BACKOFF = (15 * 60, 3600, 6 * 3600, 24 * 3600)
SHOT_MAX_BYTES = 8 * 1024 * 1024


def shot_path(domain):
    return os.path.join(SHOTS_DIR, domain + '.jpg')


def job_queue(service, item, priority=5, who='system', not_before=0):
    now = int(time.time())
    con = db_connect()
    try:
        ensure_schema(con)
        # A click (lower priority number) moves an existing job ahead; it never
        # pushes a queued one back.
        con.execute(
            'INSERT INTO tsvc_jobs (service, item, priority, state, attempts, not_before, requested_by, '
            "created_at, updated_at) VALUES (?, ?, ?, 'queued', 0, ?, ?, ?, ?) "
            'ON CONFLICT(service, item) DO UPDATE SET '
            "priority = min(priority, excluded.priority), state = 'queued', "
            # Someone clicked (priority 1-3): run it now, whatever the backoff said.
            'not_before = CASE WHEN excluded.priority <= 3 THEN 0 ELSE not_before END, '
            'attempts = CASE WHEN excluded.priority <= 3 THEN 0 ELSE attempts END, '
            'requested_by = excluded.requested_by, updated_at = excluded.updated_at',
            (service, item, priority, not_before, who, now, now))
        con.commit()
    finally:
        con.close()


def job_due(service, limit):
    con = db_connect()
    try:
        ensure_schema(con)
        return con.execute(
            "SELECT id, item, attempts FROM tsvc_jobs WHERE service = ? AND state = 'queued' AND not_before <= ? "
            'ORDER BY priority, created_at LIMIT ?', (service, int(time.time()), limit)).fetchall()
    finally:
        con.close()


def job_done(job_id):
    con = db_connect()
    try:
        con.execute('DELETE FROM tsvc_jobs WHERE id = ?', (job_id,))
        con.commit()
    finally:
        con.close()


JOB_MAX_ATTEMPTS = 5          # then the job rests ('failed') until the next weekly refresh


def job_retry(job_id, attempts, error, delay=None):
    if delay is None:
        delay = SHOT_FAIL_BACKOFF[min(attempts, len(SHOT_FAIL_BACKOFF) - 1)]
    state = 'failed' if attempts + 1 >= JOB_MAX_ATTEMPTS else 'queued'
    con = db_connect()
    try:
        con.execute('UPDATE tsvc_jobs SET attempts = ?, not_before = ?, last_error = ?, state = ?, updated_at = ? '
                    'WHERE id = ?', (attempts + 1, int(time.time()) + delay, (error or '')[:300], state,
                                     int(time.time()), job_id))
        con.commit()
    finally:
        con.close()


def job_state(service, item):
    con = db_connect()
    try:
        ensure_schema(con)
        row = con.execute('SELECT state, priority, attempts, not_before, last_error FROM tsvc_jobs '
                          'WHERE service = ? AND item = ?', (service, item)).fetchone()
    finally:
        con.close()
    if not row:
        return None
    return {'state': row[0], 'priority': row[1], 'attempts': row[2], 'not_before': row[3], 'last_error': row[4]}


def webshot_capture(domain):
    """One screenshot. Returns the cache record; raises ServiceError."""
    d = hosted_domain(domain)
    wait = quota_blocked('webshot')
    if wait:
        raise ServiceError('webshot.site rate limit; next credit in %ds' % wait, 'quota')
    # desktop_viewport costs one credit (a full-page capture costs two), and a
    # thumbnail only needs what is above the fold.
    payload = json.dumps({'url': 'https://%s/' % d, 'format': 'jpg', 'mode': 'desktop_viewport'}).encode()
    hdrs = {'Content-Type': 'application/json', 'Accept': 'image/*, application/json'}
    hdrs.update(_auth('webshot_key'))
    status, rh, body = http_request(WEBSHOT_BASE + '/api/capture', 'POST', hdrs, payload, timeout=90,
                                    max_bytes=SHOT_MAX_BYTES)
    q = extract_quota(None, rh)
    if status == 200 and rh.get('content-type', '').startswith('image/') and body:
        record_quota('webshot', q)
        ensure_state_dirs()
        path = shot_path(d)
        tmp = path + '.tmp'
        try:
            os.unlink(tmp)
        except FileNotFoundError:
            pass
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            wd = pwd.getpwnam('www-data')
            os.fchown(fd, wd.pw_uid, wd.pw_gid)      # on the descriptor: no path to swap
        except KeyError:
            pass
        with os.fdopen(fd, 'wb') as fh:
            fh.write(body)
        os.replace(tmp, path)
        rec = {'file': os.path.basename(path), 'bytes': len(body), 'captured_at': int(time.time()), 'error': ''}
        cache_put('webshot', d, rec, SHOT_TTL)
        return rec
    data = parse_json(body) if body and not rh.get('content-type', '').startswith('image/') else None
    if status == 429:
        wait = _retry_after(rh, data, 180)
        record_quota('webshot', {'limit': (q or {}).get('limit') or (quota('webshot') or {}).get('limit'),
                                 'remaining': 0, 'reset': int(time.time()) + wait, 'seen_at': int(time.time())})
        raise ServiceError('rate limited for %ds' % wait, 'quota')
    record_quota('webshot', q)
    msg = (data or {}).get('error') if isinstance(data, dict) else ''
    if status == 401:
        raise ServiceError('webshot.site did not accept the saved API key.', 'bad_key', status)
    if status == 400:
        raise ServiceError(msg or 'webshot.site refused the URL.', 'refused', status)
    raise ServiceError(msg or ('webshot.site answered HTTP %s.' % status), 'error', status or 0)


def cmd_webshot(args, as_json):
    sub = args[0] if args else ''
    domain = args[1] if len(args) > 1 else ''
    require_module('webshot')
    d = hosted_domain(domain)
    if sub == 'queue':
        # An admin's click goes first, then a client's, then background refreshes.
        prio = 1 if '--now' in args else (3 if '--portal' in args else 5)
        who = 'portal' if '--portal' in args else ('admin' if '--now' in args else 'system')
        job_queue('webshot', d, prio, who)
        how = ''
        if ('--now' in args or '--portal' in args) and not worker_running():
            how = spawn_detached(['worker', '--quick'], 'inetpanel-tsvc-worker', 'iNetPanel Tuxxin Services worker',
                                 LOG_FILE)
        emit(as_json, {'success': True, 'queued': True, 'started': how, 'quota': quota('webshot')}, 'queued')
        return 0
    if sub == 'capture':
        rec = webshot_capture(d)
        emit(as_json, {'success': True, 'data': rec}, json.dumps(rec))
        return 0
    if sub == 'status':
        c = cache_get('webshot', d, allow_stale=True)
        emit(as_json, {'success': True, 'data': c['data'] if c else None, 'job': job_state('webshot', d),
                       'quota': quota('webshot')}, json.dumps(c))
        return 0
    emit(as_json, {'success': False, 'error': 'usage: inetp tsvc webshot queue|capture|status <domain>'})
    return 2


# ── whack.sh ───────────────────────────────────────────────────────────────────
#
# Every whack.sh call needs a key (a free account's key scans from datacenter
# egress at no cost); there is no keyless tier. Scans are asynchronous: submit,
# then poll until the status is terminal — a datacenter scan usually takes
# seconds. Residential and mobile legs spend prepaid credits and are never
# requested by the panel.

WHACK_TTL = 7 * 86400
WHACK_TERMINAL = ('done', 'partial', 'failed', 'scan_error', 'expired', 'cancelled')


def whack_start(domain, who='admin'):
    require_module('whack')
    d = hosted_domain(domain)
    auth = _auth('whack_key')
    if not auth:
        raise ServiceError('whack.sh needs an API key (a free account includes datacenter scans). '
                           'Add one in Settings -> Tuxxin Services.', 'no_key')
    c = cache_get('whack', d, allow_stale=True)
    if c and isinstance(c['data'], dict) and c['data'].get('state') == 'running' \
            and time.time() - c['data'].get('started_at', 0) < 900:
        return c['data']
    body = urllib.parse.urlencode({'url': 'https://%s/' % d, 'egress': 'datacenter'}).encode()
    hdrs = {'Content-Type': 'application/x-www-form-urlencoded'}
    hdrs.update(auth)
    status, rh, raw = http_request(WHACK_BASE + '/api/scan', 'POST', hdrs, body, timeout=30)
    data = parse_json(raw) or {}
    record_quota('whack', extract_quota(data, rh))
    if status in (200, 201, 202) and data.get('ok') and data.get('scan_id'):
        rec = {'state': 'running', 'scan_id': int(data['scan_id']), 'started_at': int(time.time()),
               'requested_by': who, 'verdict': None}
        cache_put('whack', d, rec, WHACK_TTL)
        return rec
    err = data.get('error') or ''
    if status == 401:
        raise ServiceError('whack.sh did not accept the saved API key.', 'bad_key', status)
    if status == 429 or err == 'rate_limited':
        raise ServiceError('whack.sh rate limit; try again in %ds.' % _retry_after(rh, data, 60), 'quota', status)
    if err == 'duplicate_pending':
        raise ServiceError('A scan of this site is already being created; try again in a few seconds.',
                           'busy', status)
    if err == 'verification_required':
        raise ServiceError('whack.sh needs the account verified first: %s' % (data.get('verify_url') or
                           'see your whack.sh dashboard'), 'refused', status)
    if err in ('blocked', 'bad_url', 'sensitive_target'):
        raise ServiceError('whack.sh will not scan this URL (%s).' % err, 'refused', status)
    if status == 402:
        raise ServiceError('whack.sh account limit reached (%s).' % (err or 'insufficient balance'), 'quota', status)
    raise ServiceError('whack.sh answered HTTP %s%s.' % (status, (' (%s)' % err) if err else ''), 'error', status)


def whack_poll(domain):
    require_module('whack')
    d = hosted_domain(domain)
    c = cache_get('whack', d, allow_stale=True)
    if not c or not isinstance(c['data'], dict):
        return None
    rec = c['data']
    if rec.get('state') != 'running':
        return rec
    auth = _auth('whack_key')
    if not auth:
        return rec
    status, rh, raw = http_request('%s/api/scan/%d' % (WHACK_BASE, int(rec['scan_id'])), headers=auth)
    data = parse_json(raw) or {}
    scan = data.get('scan') if isinstance(data.get('scan'), dict) else {}
    if status == 200 and scan.get('status') in WHACK_TERMINAL:
        rec.update({'state': 'finished', 'status': scan.get('status'), 'verdict': scan.get('verdict'),
                    'verdict_detail': scan.get('verdict_detail'), 'cloaking_score': scan.get('cloaking_score'),
                    'error': scan.get('error'), 'finished_at': scan.get('finished_at') or int(time.time())})
        cache_put('whack', d, rec, WHACK_TTL)
        if scan.get('verdict') in ('malware', 'phishing', 'cloaking'):
            panel_log('tuxxin', 'WARNING', 'whack.sh flagged %s as %s' % (d, scan.get('verdict')))
            send_webhook('Site flagged by whack.sh', '%s: %s' % (d, scan.get('verdict')))
    elif status == 404 or time.time() - rec.get('started_at', 0) > 900:
        rec.update({'state': 'finished', 'status': 'failed', 'error': 'the scan did not finish',
                    'finished_at': int(time.time())})
        cache_put('whack', d, rec, WHACK_TTL)
    return rec


def cmd_whack(args, as_json):
    sub = args[0] if args else ''
    domain = args[1] if len(args) > 1 else ''
    if sub == 'start':
        rec = whack_start(domain, 'portal' if '--portal' in args else 'admin')
        emit(as_json, {'success': True, 'data': rec}, json.dumps(rec))
        return 0
    if sub == 'status':
        rec = whack_poll(domain)
        emit(as_json, {'success': True, 'data': rec}, json.dumps(rec))
        return 0
    emit(as_json, {'success': False, 'error': 'usage: inetp tsvc whack start|status <domain>'})
    return 2


# ── Worker (cron, every 5 minutes, only while a queueing module is on) ─────────

WORKER_BUDGET = 240        # seconds per run; the next run starts 5 minutes after this one did
ISSUED_PER_RUN = 60


def worker_running():
    try:
        fd = os.open(WORKER_LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    except OSError:
        return False
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(fd)
        return True
    fcntl.flock(fd, fcntl.LOCK_UN)
    os.close(fd)
    return False


def _shot_failed(jid, item, attempts, why):
    prev = (cache_get('webshot', item, True) or {}).get('data') or {}
    cache_put('webshot', item, dict(prev, error=why, error_at=int(time.time())), SHOT_TTL)
    job_retry(jid, attempts, why)
    log('webshot %s: %s' % (item, why), 'WARN')


def cmd_worker(args, as_json):
    ensure_state_dirs()
    lock = os.open(WORKER_LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(lock)
        emit(as_json, {'success': True, 'skipped': 'already running'}, 'worker already running')
        return 0
    try:
        trim_log(LOG_FILE)
        quick = '--quick' in args
        t_end = time.time() + (90 if quick else WORKER_BUDGET)
        _, mods = module_states()
        done = {'webshot': 0, 'issued': 0, 'whack': 0}
        domains = active_domains()

        if mods.get('webshot') and not quick:
            # A job that kept failing rests for a week, then gets another go.
            con = db_connect()
            try:
                ensure_schema(con)
                con.execute("UPDATE tsvc_jobs SET state = 'queued', attempts = 0, not_before = 0 "
                            "WHERE service = 'webshot' AND state = 'failed' AND updated_at < ?",
                            (int(time.time()) - SHOT_TTL,))
                con.commit()
            finally:
                con.close()
            # Queue a background refresh for anything without a recent screenshot.
            for d in domains:
                c = cache_get('webshot', d, allow_stale=True)
                if not c or c['stale']:
                    if not job_state('webshot', d):
                        job_queue('webshot', d, 9, 'system')
        if mods.get('webshot'):
            while time.time() < t_end and not quota_blocked('webshot'):
                due = job_due('webshot', 1)
                if not due:
                    break
                jid, item, attempts = due[0]
                try:
                    webshot_capture(item)
                    job_done(jid)
                    done['webshot'] += 1
                except ServiceError as e:
                    if e.code == 'quota':
                        break
                    if e.code == 'bad_input':          # domain no longer hosted here
                        job_done(jid)
                        continue
                    _shot_failed(jid, item, attempts, str(e))
                except Exception as e:
                    # Anything unexpected still backs the job off; otherwise it
                    # would run first again — and spend a credit — every 5 minutes.
                    log('webshot %s: unexpected %r' % (item, e), 'ERROR')
                    _shot_failed(jid, item, attempts, 'internal error')
                time.sleep(1)

        if mods.get('issued') and not quick:
            for d in domains:
                if time.time() >= t_end or done['issued'] >= ISSUED_PER_RUN or quota_blocked('issued'):
                    break
                if cache_get('issued', d):
                    continue
                try:
                    issued_lookup(d, refresh=True)
                    done['issued'] += 1
                except ServiceError as e:
                    log('issued %s: %s' % (d, e), 'WARN')
                    if e.code in ('quota', 'bad_key'):
                        break
                except Exception as e:
                    log('issued %s: unexpected %r' % (d, e), 'ERROR')
                time.sleep(0.5)           # issued.live asks bulk work to be serialized

        if mods.get('whack'):
            con = db_connect()
            try:
                ensure_schema(con)
                running = [r[0] for r in con.execute(
                    "SELECT item FROM tsvc_cache WHERE service = 'whack' AND body LIKE '%\"state\": \"running\"%'")]
            finally:
                con.close()
            for d in running:
                if time.time() >= t_end:
                    break
                try:
                    whack_poll(d)
                    done['whack'] += 1
                except Exception as e:
                    log('whack %s: %r' % (d, e), 'WARN')
        write_setting('tsvc_worker_last_run', now_str())
        if any(done.values()):
            log('worker: %s' % ', '.join('%s %d' % kv for kv in done.items() if kv[1]))
        emit(as_json, {'success': True, 'done': done}, 'done: %s' % done)
        return 0
    finally:
        fcntl.flock(lock, fcntl.LOCK_UN)
        os.close(lock)



def sync_worker_cron():
    """The worker only exists while a module with background work is on:
    screenshots, expiry refreshes, or whack.sh scans left running."""
    _, mods = module_states()
    want = mods.get('webshot') or mods.get('issued') or mods.get('whack')
    if want:
        _write_cron(WORKER_CRON,
                    '# iNetPanel Tuxxin Services worker - managed by the panel (Settings -> Tuxxin Services)\n'
                    '# /etc/cron.d entries do not inherit PATH from /etc/crontab.\n'
                    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
                    '*/5 * * * * root %s -I /root/scripts/tuxxin_services.py worker >> %s 2>&1\n'
                    % (PYTHON, LOG_FILE))
    else:
        try:
            os.unlink(WORKER_CRON)
        except FileNotFoundError:
            pass
    return bool(want)


def cmd_cron(args, as_json):
    worker = sync_worker_cron()
    want, why = qsa_sync_cron()
    emit(as_json, {'success': True, 'worker': worker, 'qsa_monitor': want, 'qsa_reason': why},
         'worker cron: %s; qsa monitor: %s' % ('on' if worker else 'off', 'on' if want else 'off (%s)' % why))
    return 0


# ── Self-test ──────────────────────────────────────────────────────────────────

_SAMPLE_CLEAN = """Detected source IP: 147.135.10.188

Origin intel  (worldip.io)
  Network  AS16276 OVH SAS
  Starting scan of 147.135.10.188 in 15s  (Ctrl-C to abort)  ░░░░░░░░░░
  Starting scan of 147.135.10.188 in 1s  (Ctrl-C to abort)  ▓▓▓▓▓▓▓▓▓░

> Scanning...
» naabu discovering open ports
  scanning…
  no open ports detected in the discovery phase.
  stage timing — naabu 5s · nmap 0s · nuclei 0s

Scan complete for 147.135.10.188
Duration: 15.7s
Open ports: 0
No exposed services found on the scanned ports.
"""

_SAMPLE_CUT = """Detected source IP: 67.202.121.25
> Scanning...
» naabu discovering open ports
  scanning…
» nmap -sV --script vulners
  scanning…
25/tcp    open   smtp
53/tcp    open   domain    PowerDNS Authoritative …
465/tcp   open   smtp      Exim smtpd 4.100.1
3306/tcp  open   mysql     MySQL
» nuclei web exposures, misconfig & TLS
  [TLS] TLS Version - Detect
  scanning…
"""


def cmd_selftest(args, as_json):
    fails = []

    def check(name, got, want):
        if got != want:
            fails.append('%s: got %r, want %r' % (name, got, want))

    check('clean complete', qsa_classify(_SAMPLE_CLEAN, 31, 'free'), 'complete')
    check('cut at 122s is capped', qsa_classify(_SAMPLE_CUT, 122, 'free'), 'capped')
    check('cut at 40s is interrupted', qsa_classify(_SAMPLE_CUT, 40, 'free'), 'interrupted')
    check('time cap line', qsa_classify(_SAMPLE_CUT + '  scan reached its time cap — stopping.\n', 60), 'capped')
    check('partial', qsa_classify(_SAMPLE_CLEAN + 'Scan did not fully complete - these results are partial\n'),
          'partial')
    check('429', qsa_classify('Rate limit reached for your IP. Try again in about 20h', 1, 'free', 429),
          'rate_limited')
    check('403', qsa_classify('This connection appears to arrive via a proxy', 1, 'free', 403), 'refused')
    check('empty', qsa_classify('', 2, 'free', 0), 'failed')

    ex = qsa_extract(_SAMPLE_CUT)
    check('ports', [p['port'] for p in ex['ports']], [25, 53, 465, 3306])
    check('version ellipsis stripped', ex['ports'][1]['version'], 'PowerDNS Authoritative')
    check('finding', ex['findings'], ['[TLS] TLS Version - Detect'])
    check('ip', ex['ip'], '67.202.121.25')
    check('clean open ports', qsa_extract(_SAMPLE_CLEAN)['open_ports'], 0)

    # Two scans that differ only in timing, countdown and heartbeats are equal.
    noisy = _SAMPLE_CLEAN.replace('15.7s', '22.1s').replace('naabu 5s', 'naabu 9s') + '  scanning…\n'
    check('noise is not a change', qsa_diff(_SAMPLE_CLEAN, noisy), '')
    opened = _SAMPLE_CLEAN.replace('Open ports: 0', '22/tcp    open   ssh       OpenSSH 9.2\nOpen ports: 1')
    d = qsa_diff(_SAMPLE_CLEAN, opened)
    check('new port is a change', '+22/tcp open ssh OpenSSH 9.2' in d, True)

    live = qsa_clean_live(_SAMPLE_CLEAN)
    check('one countdown frame', live.count('Starting scan of'), 1)
    check('no heartbeats', 'scanning…' in live, False)
    check('phase', qsa_phase(_SAMPLE_CUT, {}), 'nuclei')

    check('discord', webhook_kind('https://discord.com/api/webhooks/1/abc'), 'discord')
    check('slack', webhook_kind('https://hooks.slack.com/services/T/B/x'), 'slack')
    check('ntfy', webhook_kind('https://ntfy.sh/my-topic'), 'ntfy')
    check('generic', webhook_kind('https://example.com/hook'), 'generic')
    check('http rejected', webhook_kind('http://example.com/hook'), '')
    check('token ok', bool(SECRET_RULES['qsa_token'].fullmatch('0123456789abcdef0123456789ABCDEF')), True)
    check('token short', bool(SECRET_RULES['qsa_token'].fullmatch('0123456789abcdef')), False)

    q = extract_quota({'_meta': {'rate': {'limit': 1000, 'remaining': 905, 'reset': '2026-10-25T00:00:00Z'}}}, {})
    check('json quota', (q['limit'], q['remaining'], q['reset']), (1000, 905, 1792886400))
    q = extract_quota(None, {'x-ratelimit-limit': '5', 'x-ratelimit-remaining': '4', 'x-ratelimit-reset': '1792874154'})
    check('header quota', (q['limit'], q['remaining'], q['reset']), (5, 4, 1792874154))
    check('no quota', extract_quota({'ip': '1.1.1.1'}, {}), None)

    t = worldip_trim({'ip': '8.8.8.8', 'ptr': 'dns.google', 'forward_dns': {'hostname': 'dns.google', 'confirmed': False},
                      'asn': {'number': 15169, 'name': 'Google LLC'}, 'country': {'code': 'US', 'name': 'United States'},
                      '_meta': {'attribution': 'GeoLite2'}}, False)
    check('worldip trim', (t['asn'], t['forward_confirmed'], t['country_code'], t['attribution']),
          (15169, False, 'US', 'GeoLite2'))

    check('domain ok', bool(DOMAIN_RE.fullmatch('cdn.cornholeboards.us')), True)
    check('domain path', bool(DOMAIN_RE.fullmatch('example.com/../etc')), False)
    check('domain dash', bool(DOMAIN_RE.fullmatch('-bad.com')), False)

    if as_json:
        emit(True, {'success': not fails, 'failures': fails})
    else:
        print('\n'.join(fails) if fails else 'selftest: all checks passed')
    return 1 if fails else 0


COMMANDS = {
    'status': cmd_status, 'set-secret': cmd_set_secret, 'clear-secret': cmd_clear_secret,
    'migrate': cmd_migrate, 'cron': cmd_cron, 'notify-test': cmd_notify_test, 'qsa': cmd_qsa,
    'worldip': cmd_worldip, 'issued': cmd_issued, 'webshot': cmd_webshot, 'whack': cmd_whack,
    'worker': cmd_worker, 'selftest': cmd_selftest,
}

USAGE = ('usage: inetp tsvc {status|set-secret <name>|clear-secret <name>|migrate|cron|worker|notify-test|'
         'qsa ...|worldip <ip>|issued <domain>|webshot ...|whack <domain>|selftest} [--json]')


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
        # The release that ships this helper is installed by the previous
        # panel_update.php, which has no `tsvc migrate` step — and later runs
        # stop at "Already up to date". So the helper converts a pre-1.29
        # install itself, the first time anything calls it (the old qsa cron
        # included). Idempotent; one SELECT when already done.
        if args[0] not in ('migrate', 'selftest') and setting('tsvc_migrated', '') != MIGRATION_MARK:
            try:
                migrate_legacy()
            except Exception as e:
                log('automatic migration failed: %r' % e, 'ERROR')
        return COMMANDS[args[0]](args[1:], as_json)
    except ServiceError as e:
        emit(as_json, {'success': False, 'error': str(e), 'code': e.code}, 'error: %s' % e)
        return 1
    except Exception as e:
        # Details go to the root-only log; the panel gets a fixed message, so
        # nothing from a remote response can reach a page.
        log('%s failed: %r' % (args[0], e), 'ERROR')
        emit(as_json, {'success': False, 'error': 'Internal error in the Tuxxin Services helper; '
                       'see /var/log/inetpanel_tsvc.log.'}, 'error: %s' % e)
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
