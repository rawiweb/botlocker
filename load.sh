#!/bin/bash


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
test=$(load_section_patterns "web-bad" "BLACKLIST")
echo "BL $test"

test=$(load_section_patterns "admin-ac" "MY_IPS")
echo "WHIP $test"
test=$(load_section_patterns "admin-au" "MY_IPS")
echo "AU IP $test"