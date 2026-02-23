#!/bin/bash
# --- botlocker Surgical Uninstaller ---

# 1. Source the "Anchor"
CONF="/etc/botlocker/botlocker.conf"
if [ -f "$CONF" ]; then
    source "$CONF"
else
    # Fallbacks if config is already gone
    IPSET_NAME="botlocker_trap"
fi

echo "Uninstalling botlocker..."

# 2. Stop and Remove Service
echo "Removing Systemd Service..."
systemctl stop botlocker-set.service 2>/dev/null
systemctl disable botlocker-set.service 2>/dev/null
rm -f /etc/systemd/system/botlocker-set.service
systemctl daemon-reload

# 3. Clean the Kernel
echo "Cleaning Firewall Rules..."
iptables -D INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null

if ipset list "$IPSET_NAME" >/dev/null 2>&1; then
    ipset flush "$IPSET_NAME"
    ipset destroy "$IPSET_NAME"
    echo "[OK] IPSet '$IPSET_NAME' destroyed."
fi

# 4. Handle GeoIP Database Removal
# Only ask if the directory actually exists
if [ -d "/usr/share/GeoIP" ]; then
    echo ""
    read -p "Do you want to remove the GeoIP Database files? (y/n) [n]: " RM_GEO
    if [[ "$RM_GEO" =~ ^([yY][eE][sS]|[yY])$ ]]; then
        echo "Removing GeoIP files..."
        rm -rf /usr/share/GeoIP
        apt-get remove -y mmdb-bin
    else
        echo "Keeping GeoIP files for other applications."
    fi
fi

# 5. Remove Files & Reports
echo "Removing binary and configuration files..."
rm -f /etc/cron.d/botlocker
rm -f /usr/local/sbin/botlocker-*
rm -f /etc/logrotate.d/botlocker
rm -f "/etc/botlocker/ipset.$IPSET_NAME.conf"
rm -rf /var/log/botlocker

# Specifically nuke the web reports defined in config
[ -n "$NET_REPORT_WEB_DEST" ] && rm -f "$NET_REPORT_WEB_DEST"
[ -n "$TOP10_REPORT_WEB_DEST" ] && rm -f "$TOP10_REPORT_WEB_DEST"

# Remove from crontab
crontab -l 2>/dev/null | grep -v "botlocker" | crontab -

# LAST STEP: Remove the config
if [ -d "/etc/botlocker" ]; then
    echo "Removing /etc/botlocker/ and modular patterns..."
    rm -rf /etc/botlocker
fi

echo "--------------------------------------------------------"
echo "Cleanup complete. System is back to stock."
echo "NOTE: If you moved 'botlocker-ready.php' to a web root,"
echo "      you must delete that file manually."
echo "--------------------------------------------------------"