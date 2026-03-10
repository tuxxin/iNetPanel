#!/bin/bash
# ==============================================================================
# firewall_manage.sh — CLI management for firewalld + fail2ban
#
# Usage: firewall_manage.sh <subcommand> [args]
#   status              Show firewalld zones/ports + fail2ban summary
#   flush               Flush all fail2ban bans
#   ban <ip>            Ban IP across all fail2ban jails
#   unban <ip>          Unban IP across all fail2ban jails
# ==============================================================================

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# Disable colors when not in a terminal
if [ ! -t 1 ]; then
    BOLD=''; GREEN=''; YELLOW=''; RED=''; NC=''
fi

SUBCMD="$1"; shift

case "$SUBCMD" in

    status)
        echo -e "${BOLD}--- Firewall Status ---${NC}"
        echo ""

        # Firewalld
        if command -v firewall-cmd &>/dev/null; then
            FW_STATE=$(firewall-cmd --state 2>/dev/null)
            if [ "$FW_STATE" = "running" ]; then
                echo -e "  Firewalld:  ${GREEN}Running${NC}"
            else
                echo -e "  Firewalld:  ${RED}Not running${NC}"
            fi
            echo -e "  Default zone: $(firewall-cmd --get-default-zone 2>/dev/null)"
            echo ""

            echo -e "  ${BOLD}Active zones:${NC}"
            firewall-cmd --get-active-zones 2>/dev/null | while IFS= read -r line; do
                echo "    $line"
            done
            echo ""

            echo -e "  ${BOLD}Open ports (default zone):${NC}"
            PORTS=$(firewall-cmd --list-ports 2>/dev/null)
            [ -n "$PORTS" ] && echo "    $PORTS" || echo "    (none)"

            # Check for vpn zone
            if firewall-cmd --permanent --get-zones 2>/dev/null | grep -qw vpn; then
                echo ""
                echo -e "  ${BOLD}VPN zone ports:${NC}"
                VPN_PORTS=$(firewall-cmd --zone=vpn --list-ports 2>/dev/null)
                [ -n "$VPN_PORTS" ] && echo "    $VPN_PORTS" || echo "    (none)"
                VPN_SRC=$(firewall-cmd --zone=vpn --list-sources 2>/dev/null)
                [ -n "$VPN_SRC" ] && echo "    Sources: $VPN_SRC"
            fi
        else
            echo -e "  Firewalld:  ${RED}Not installed${NC}"
        fi

        echo ""

        # Fail2Ban
        if command -v fail2ban-client &>/dev/null; then
            F2B_STATUS=$(systemctl is-active fail2ban 2>/dev/null)
            if [ "$F2B_STATUS" = "active" ]; then
                echo -e "  Fail2Ban:   ${GREEN}Running${NC}"
            else
                echo -e "  Fail2Ban:   ${RED}Not running${NC}"
            fi
            echo ""
            echo -e "  ${BOLD}Jails:${NC}"
            JAILS=$(fail2ban-client status 2>/dev/null | grep 'Jail list' | sed 's/.*:\s*//' | tr ',' '\n' | sed 's/^[[:space:]]*//')
            for jail in $JAILS; do
                BANNED=$(fail2ban-client status "$jail" 2>/dev/null | grep 'Currently banned' | awk '{print $NF}')
                TOTAL=$(fail2ban-client status "$jail" 2>/dev/null | grep 'Total banned' | awk '{print $NF}')
                echo -e "    ${jail}: ${BOLD}${BANNED}${NC} banned (${TOTAL} total)"
            done
        else
            echo -e "  Fail2Ban:   ${RED}Not installed${NC}"
        fi
        echo ""
        ;;

    flush)
        echo -e "${BOLD}Flushing all fail2ban bans...${NC}"
        if ! command -v fail2ban-client &>/dev/null; then
            echo -e "${RED}fail2ban-client not found.${NC}"
            exit 1
        fi
        JAILS=$(fail2ban-client status 2>/dev/null | grep 'Jail list' | sed 's/.*:\s*//' | tr ',' '\n' | sed 's/^[[:space:]]*//')
        TOTAL=0
        for jail in $JAILS; do
            BANNED_IPS=$(fail2ban-client status "$jail" 2>/dev/null | grep 'Banned IP list' | sed 's/.*:\s*//')
            for ip in $BANNED_IPS; do
                fail2ban-client set "$jail" unbanip "$ip" 2>/dev/null
                echo -e "  Unbanned ${ip} from ${jail}"
                TOTAL=$((TOTAL + 1))
            done
        done
        echo -e "${GREEN}Flushed ${TOTAL} ban(s) across all jails.${NC}"
        ;;

    ban)
        IP="$1"
        if [ -z "$IP" ]; then
            echo -e "${RED}Usage: firewall_manage.sh ban <ip>${NC}"
            exit 1
        fi
        echo -e "${BOLD}Banning ${IP} across all jails...${NC}"
        JAILS=$(fail2ban-client status 2>/dev/null | grep 'Jail list' | sed 's/.*:\s*//' | tr ',' '\n' | sed 's/^[[:space:]]*//')
        for jail in $JAILS; do
            fail2ban-client set "$jail" banip "$IP" 2>/dev/null
            echo -e "  ${GREEN}Banned ${IP} in ${jail}${NC}"
        done
        ;;

    unban)
        IP="$1"
        if [ -z "$IP" ]; then
            echo -e "${RED}Usage: firewall_manage.sh unban <ip>${NC}"
            exit 1
        fi
        echo -e "${BOLD}Unbanning ${IP} across all jails...${NC}"
        JAILS=$(fail2ban-client status 2>/dev/null | grep 'Jail list' | sed 's/.*:\s*//' | tr ',' '\n' | sed 's/^[[:space:]]*//')
        for jail in $JAILS; do
            fail2ban-client set "$jail" unbanip "$IP" 2>/dev/null
            echo -e "  ${GREEN}Unbanned ${IP} from ${jail}${NC}"
        done
        ;;

    *)
        echo "Usage: firewall_manage.sh <status|flush|ban|unban> [args]"
        exit 1
        ;;
esac
