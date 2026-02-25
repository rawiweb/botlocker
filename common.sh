#!/bin/bash
# --- botlocker Shared Functions ---

# Load patterns based on a file prefix from the central conf.d
load_prefix_patterns() {
    local prefix="$1"
    local conf_dir="/etc/botlocker/conf.d"
    
    if [ -d "$conf_dir" ]; then
        # -h: hide filenames, -v: skip comments/empty lines
        # tr/sed: convert list to pipe-delimited regex
        grep -hE -v '^\s*(#|$)' "$conf_dir/${prefix}"* 2>/dev/null | \
        tr '\n' '|' | sed 's/||*/|/g; s/^|//; s/|$//'
    fi
}

ensure_firewall_integrity() {
    # Only run if not in dry run
    if [ "$DRY_RUN" = "false" ]; then
        # Check if ipset exists, create if missing
        /sbin/ipset create "$IPSET_NAME" "$IPSET_PARAMS"
        
        # Check if iptables rule exists, insert if missing
        if ! /sbin/iptables-save | grep -q "match-set $IPSET_NAME src"; then
            /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
        fi
        
        # Return the current count of banned IPs
        /sbin/ipset list "$IPSET_NAME" | grep "Number of entries" | awk '{print $4}'
    else
        echo "0"
    fi
}
