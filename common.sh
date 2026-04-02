#!/bin/bash
# --- botlocker Shared Functions ---
LOCKFILE="$MAIN_DATA_PATH/system_off.lock"
if [ -f "$LOCKFILE" ]; then
    exit 0
fi

COPY_FILE="$MAIN_DATA_PATH/config.copy.now"
RESULT_FILE="$MAIN_DATA_PATH/.last_result"

if [ -f "$COPY_FILE" ]; then
    rm -f "$COPY_FILE"
    cp -rp "$MAIN_DATA_PATH/conf.d/"* "/etc/botlocker/conf.d/"
    if [ -f "/etc/botlocker/conf.d/botlocker.conf" ]; then
        mv "/etc/botlocker/conf.d/botlocker.conf" "/etc/botlocker/botlocker.conf"
    fi
    echo "SUCCESS: Sync and Map complete $(date +%H:%M:%S)" > "$MAIN_DATA_PATH/.last_result"
fi

if [ "$DRY_RUN" = false ]; then
    exec > >(while read -r line; do echo "$(date '+%Y-%m-%d %H:%M:%S') $(basename "$0") $line"; done >> "$ERROR_LOG") 2>&1
else
    exec > >(while read -r line; do echo "$(date '+%Y-%m-%d %H:%M:%S') $(basename "$0") $line"; done) 
fi

#load_section_patterns() {
#   local prefix="$1"   # e.g., "web" or "my-access"
#   local section="$2"  # e.g., "BLACKLIST" or "MY_IPS"
#    local conf_dir="/etc/botlocker/conf.d"
   
#    if [ -d "$conf_dir" ]; then
#        sed -n "/\[$section\]/,/\[/p" "$conf_dir/${prefix}"* 2>/dev/null | \
#        grep -vE '^\[|^#|^$' | \
#        tr '\n' '|' | sed 's/||*/|/g; s/^|//; s/|$//'
#        fi
#}
load_section_patterns() {
    local prefix="$1"   # e.g., "web"
    local section="$2"  # e.g., "BLACKLIST"
    local conf_dir="/etc/botlocker/conf.d"

    if [ -d "$conf_dir" ]; then
        find "$conf_dir" -name "${prefix}*.conf" -exec awk -v target="[$section]" '
            BEGINFILE { ins = 0 }
            { gsub(/[[:space:]\r]+$/, "") }
            $0 == target { ins = 1; next }
            $0 ~ /^\[.*\]$/ && $0 != target { ins = 0; next }
            ins == 1 && $0 !~ /^[[:space:]]*#/ && $0 !~ /^[[:space:]]*$/ {
                print $0
            }
        ' {} + | paste -sd "|" - | sed 's/||*/|/g; s/^|//; s/|$//'
    fi
}
MANUAL_IPS=$(load_section_patterns "admin-access" "MY_IPS")
AUTO_IPS=$(load_section_patterns "admin-auto" "WHITELIST" | sed -E 's/\|[0-9]+//g')
MY_IPS="${MANUAL_IPS}|${AUTO_IPS}"
MY_IPS=$(echo "$MY_IPS" | sed 's/|\|*/|/g; s/^|//; s/|$//')
IP_SEARCH=$(echo "$MY_IPS" | sed 's/\^//g; s/\$//g')
[[ -z "$IP_SEARCH" ]] && IP_SEARCH="SHIELD_EMPTY_NO_MATCH"
#echo "IPSEARCH $IP_SEARCH"

ensure_firewall_integrity() {
    if [ "$DRY_RUN" = "false" ]; then
        if ! /sbin/ipset list -t "$IPSET_NAME" &>/dev/null; then
            /sbin/ipset create "$IPSET_NAME" $IPSET_PARAMS  --exist
            echo "ipset restored"
        fi
        if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
            /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
             echo "$(basename "$0") iptables rule restored"
        fi
    else
        echo "0"
    fi
}
country_lookup() {
    local cc="--"
    local TARGET="$1"
    local CLEAN_IP="${TARGET%%/*}"
    [[ "$CLEAN_IP" == *.1 ]] && CLEAN_IP="${CLEAN_IP%.1}.1"
    # Only attempt lookup if the user enabled it AND the file exists
    if [ "$USE_GEOIP" = "true" ] && [ -f "$MMDB_FILE" ]; then
        cc=$(mmdblookup --file "$MMDB_FILE" --ip "$CLEAN_IP" country iso_code 2>/dev/null | awk -F'"' '/[A-Z]{2}/ {print $2; exit}')
        [[ -z "$cc" ]] && cc="??"
    fi
    echo "$cc"
}

org_lookup() {
    local org="--"
    local TARGET="$1"
    local CLEAN_IP="${TARGET%%/*}"
    [[ "$CLEAN_IP" == *.1 ]] && CLEAN_IP="${CLEAN_IP%.1}.1"
    if [ "$USE_GEOIP" = "true" ] && [ -f "$ASN_FILE" ]; then
        org=$(mmdblookup --file "$ASN_FILE" --ip "$CLEAN_IP" autonomous_system_organization 2>/dev/null | awk -F'"' 'NF > 1 {print $2}')
        [[ -z "$org" ]] && org="Unknown Org"
    elif [ "$USE_GEOIP" != "true" ]; then
        org="No GeoIP support"
    fi
    echo "$org"
}

ban_ip() {
    local ip="$1"
    
   if [[ ! "$ip" =~ $MY_IPS ]] && ! /sbin/ipset test "$IPSET_NAME" "$ip" &>/dev/null; then
        if /sbin/ipset add "$IPSET_NAME" "$ip" -!; then
            ((BANNED_THIS_RUN++))
            CURRENT_TOTAL=$(/sbin/ipset list -t "$IPSET_NAME" | grep -oP 'Number of entries: \K\d+')
            return 0  # Success
        fi
    fi
    return 1  # Already exists or failed
}
 
log_ban_event() {
    local type="$1" ip="$2" reason="$3" detail="$4"
    local cc
    cc=$(country_lookup "$ip")
    echo "$(date '+%Y-%m-%d %H:%M:%S')|$type|$CURRENT_TOTAL|$cc $ip|$reason|$detail" >> "$MAIN_LOG"
}
