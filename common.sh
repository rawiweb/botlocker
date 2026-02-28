#!/bin/bash
# --- botlocker Shared Functions ---

# Load patterns based on a file prefix from the central conf.d
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
