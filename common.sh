#!/bin/bash
# --- botlocker Shared Functions ---

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
            # Only create if it's actually missing
            /sbin/ipset create "$IPSET_NAME" $IPSET_PARAMS
        fi
        if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
            /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
        fi
        local count
        count=$(/sbin/ipset list "$IPSET_NAME" 2>/dev/null | grep "Number of entries" | awk '{print $4}')
        echo "${count:-0}"
    else
        echo "0"
    fi
}
country_lookup() {
    local ip="$1"
    local cc="--"
    if [ "$USE_GEOIP" = "true" ] && [ -f "/usr/share/GeoIP/GeoLite2-Country.mmdb" ]; then
        cc=$(mmdblookup --file /usr/share/GeoIP/GeoLite2-Country.mmdb --ip "$ip" country iso_code 2>/dev/null | grep -oE '"[A-Z]{2}"' | tr -d '"')
        [[ -z "$cc" ]] && cc="??"
    fi
    echo "$cc"
}

ban_ip() {
    local ip="$1"
    if ! /sbin/ipset test "$IPSET_NAME" "$ip" &>/dev/null; then
        if /sbin/ipset add "$IPSET_NAME" "$ip" -!; then
            ((BANNED_THIS_RUN++))
            ((CURRENT_TOTAL++))
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