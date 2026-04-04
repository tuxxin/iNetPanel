#!/bin/bash
# ──────────────────────────────────────────────────────────────
# Domain Availability Checker — uses RDAP (free, no API key)
#
# Usage:
#   ./check_domains.sh domain.com                # single domain
#   ./check_domains.sh domain1.com domain2.com   # multiple domains
#   ./check_domains.sh -f domains.txt            # from file (one per line)
#   ./check_domains.sh -g "inetp"                # generate & check short variants
# ──────────────────────────────────────────────────────────────

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

RDAP_URL="https://rdap.org/domain"
AVAILABLE_LOG="/tmp/domains_available.txt"
> "$AVAILABLE_LOG"

check_domain() {
    local domain="$1"
    local http_code

    http_code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 10 "$RDAP_URL/$domain")

    case "$http_code" in
        404)
            printf "  ${GREEN}✓ AVAILABLE${NC}  %s\n" "$domain"
            echo "$domain" >> "$AVAILABLE_LOG"
            ;;
        200)
            printf "  ${RED}✗ taken${NC}      %s\n" "$domain"
            ;;
        429)
            printf "  ${YELLOW}⏳ rate-limited${NC} %s (retrying in 5s...)\n" "$domain"
            sleep 5
            check_domain "$domain"
            ;;
        *)
            printf "  ${YELLOW}? unknown${NC}    %s  (HTTP %s)\n" "$domain" "$http_code"
            ;;
    esac
}

generate_variants() {
    local keyword="$1"
    local tlds=("com" "net" "io" "dev" "app" "sh" "cc" "co" "me" "org" "host" "cloud" "run" "pro" "site" "tech")
    local domains=()

    for tld in "${tlds[@]}"; do
        domains+=("${keyword}.${tld}")
    done

    local prefixes=("go" "my" "get" "try" "use")
    local suffixes=("hq" "io" "up" "go" "app" "hub" "box" "now" "run")

    for prefix in "${prefixes[@]}"; do
        domains+=("${prefix}${keyword}.com")
        domains+=("${prefix}${keyword}.net")
        domains+=("${prefix}${keyword}.io")
    done

    for suffix in "${suffixes[@]}"; do
        domains+=("${keyword}${suffix}.com")
        domains+=("${keyword}${suffix}.net")
    done

    printf '%s\n' "${domains[@]}" | sort -u
}

# ── Main ──────────────────────────────────────────────────────

if [[ $# -eq 0 ]]; then
    echo "Usage: $0 [-f file] [-g keyword] [domain ...]"
    echo "  -f file      Read domains from file (one per line)"
    echo "  -g keyword   Generate short domain variants and check them"
    echo "  domain       One or more domains to check"
    exit 1
fi

domains=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        -f)
            shift
            if [[ ! -f "$1" ]]; then
                echo "File not found: $1"
                exit 1
            fi
            while IFS= read -r line; do
                line=$(echo "$line" | xargs)
                [[ -n "$line" && ! "$line" =~ ^# ]] && domains+=("$line")
            done < "$1"
            shift
            ;;
        -g)
            shift
            keyword="$1"
            echo -e "\n${BOLD}Generating variants for '${keyword}'...${NC}\n"
            while IFS= read -r d; do
                domains+=("$d")
            done < <(generate_variants "$keyword")
            shift
            ;;
        *)
            domains+=("$1")
            shift
            ;;
    esac
done

echo -e "\n${BOLD}Checking ${#domains[@]} domains...${NC}\n"

count=0
for domain in "${domains[@]}"; do
    check_domain "$domain"
    count=$((count + 1))
    if (( count % 8 == 0 )); then
        sleep 2
    else
        sleep 1
    fi
done

available=$(wc -l < "$AVAILABLE_LOG")
echo -e "\n${BOLD}Results: ${GREEN}${available} available${NC} ${BOLD}/ ${#domains[@]} checked${NC}"

if [[ "$available" -gt 0 ]]; then
    echo -e "\n${CYAN}Available domains saved to: ${AVAILABLE_LOG}${NC}"
    echo -e "${BOLD}──────────────────────────${NC}"
    cat "$AVAILABLE_LOG"
    echo -e "${BOLD}──────────────────────────${NC}"
fi
