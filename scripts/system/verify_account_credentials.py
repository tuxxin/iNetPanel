#!/usr/bin/env python3
"""
verify_account_credentials.py
Verifies Linux user credentials via PAM.
Usage: verify_account_credentials.py <username> <password>
Exits 0 if authenticated, 1 if not.
Security: only users in /etc/vsftpd.userlist are allowed.
"""
import sys
import os
import PAM

def main():
    if len(sys.argv) != 3:
        sys.exit(1)

    username = sys.argv[1]
    password = sys.argv[2]

    # Validate username characters
    import re
    if not re.match(r'^[a-zA-Z0-9._-]+$', username):
        sys.exit(1)

    # Security gate: only allow hosting accounts in vsftpd.userlist
    try:
        with open('/etc/vsftpd.userlist') as f:
            allowed = {line.strip() for line in f if line.strip()}
        if username not in allowed:
            sys.exit(1)
    except Exception:
        sys.exit(1)

    # PAM authentication
    def conv(auth, query_list, userdata):
        resp = []
        for query, qtype in query_list:
            if qtype == PAM.PAM_PROMPT_ECHO_OFF:
                resp.append((password, 0))
            else:
                resp.append(('', 0))
        return resp

    p = PAM.pam()
    p.start('login')
    p.set_item(PAM.PAM_USER, username)
    p.set_item(PAM.PAM_CONV, lambda auth, qlist, ud: conv(auth, qlist, None))
    try:
        p.authenticate()
        p.acct_mgmt()
        sys.exit(0)
    except PAM.error:
        sys.exit(1)

if __name__ == '__main__':
    main()
