#!/bin/bash
# --- botlocker Shared Functions ---
LOCKFILE="/var/www/vhosts/$DOMAIN/system_off.lock"
if [ -f "$LOCKFILE" ]; then
    exit 0
fi
exec > >(while read line; do echo "$(date '+%Y-%m-%d %H:%M:%S') $(basename "$0") $line"; done >> "$ERROR_LOG") 2>&1

load_section_patterns() {
    local prefix="$1"   # e.g., "web" or "my-access"
    local section="$2"  # e.g., "BLACKLIST" or "MY_IPS"
    local conf_dir="/etc/botlocker/conf.d"
    
    if [ -d "$conf_dir" ]; then
        sed -n "/\[$section\]/,/\[/p" "$conf_dir/${prefix}"* 2>/dev/null | \
        grep -vE '^\[|^#|^$' | \
        tr '\n' '|' | sed 's/||*/|/g; s/^|//; s/|$//'
        fi
}
ensure_firewall_integrity() {
    if [ "$DRY_RUN" = "false" ]; then
        if ! /sbin/ipset list "$IPSET_NAME" &>/dev/null; then
            /sbin/ipset create "$IPSET_NAME" $IPSET_PARAMS
            echo "ipset restored"
        fi
        if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
            /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
             echo "$(basename "$0") iptables rule restored"
        fi
    fi
}
country_lookup() {
    local cc="--"
    local lookup_ip=$(echo "$ip" | sed 's/\.[0-9]*$/.1/')
    if [ "$USE_GEOIP" = "true" ] && [ -f "/usr/share/GeoIP/GeoLite2-Country.mmdb" ]; then
        cc=$(mmdblookup --file /usr/share/GeoIP/GeoLite2-Country.mmdb --ip "$lookup_ip" country iso_code 2>/dev/null | grep -oE '"[A-Z]{2}"' | tr -d '"')
        [[ -z "$cc" ]] && cc="??"
    fi
    echo "$cc"
}

ban_ip() {
    local ip="$1"
    if ! /sbin/ipset test "$IPSET_NAME" "$ip" &>/dev/null; then
        if /sbin/ipset add "$IPSET_NAME" "$ip" -!; then
            ((BANNED_THIS_RUN++))
            CURRENT_TOTAL=$(/sbin/ipset list "$IPSET_NAME" | grep -oP 'Number of entries: \K\d+')
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
