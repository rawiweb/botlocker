#!/bin/bash
clear
echo -e "------------------------------"
echo -e "--- BotLocker Setup Wizard ---"
echo -e "------------------------------\n"
echo "Configuration for $(hostname)" 

# --- 0. Check for Existing Configuration ---
CONF_FILE="/etc/botlocker/botlocker.conf"
SKIP_CONFIG=false

if [ -f "$CONF_FILE" ]; then
    echo -e "\a" # System beep to get attention
    echo "--------------------------------------------------------"
    echo "NOTICE: Existing BotLocker configuration found!"
    echo "--------------------------------------------------------"
    read -p "Do you want to [K]eep existing config or [O]verwrite with new defaults? (k/o): " CONF_ACTION
    
    if [[ "$CONF_ACTION" =~ ^([kK])$ ]]; then
        echo "Keeping existing configuration. Skipping config generation..."
        source "$CONF_FILE"
        SKIP_CONFIG=true
    else
        echo "Proceeding with fresh configuration (overwriting)..."
    fi
fi

if [ "$SKIP_CONFIG" = false ]; then
    echo "Generating new configuration..."

# --- 1. Identity Logic ---
FULL_HOST=$(hostname)
DOT_COUNT=$(echo "$FULL_HOST" | tr -cd '.' | wc -c)

if [ "$DOT_COUNT" -ge 2 ]; then
    GUESSED_DOMAIN="${FULL_HOST#*.}"
else
    GUESSED_DOMAIN="$FULL_HOST"
fi

echo "The Domain name is usually the users home directory."
echo "This will create a directory ~/.botlocker/ for the data outside the webroot"
echo "If your homedirectory is elsewhere pls edit /etc/botlocker.conf manually."
read -p "Enter Domain Name for the dashboard [$GUESSED_DOMAIN]: " USER_DOMAIN
DOMAIN=${USER_DOMAIN:-$GUESSED_DOMAIN}

DEFAULT_WEB_DIR="httpdocs"
read -p "Enter webroot directory for the dashboard [$DEFAULT_WEB_DIR]: " INPUT_WEB_DIR
WEB_DIR=${INPUT_WEB_DIR:-$DEFAULT_WEB_DIR}

DEFAULT_WEBD_DIR="webui"
read -p "Enter directory name for the dashboard [$DEFAULT_WEBD_DIR]: " INPUT_WEBD_DIR
WEBD_DIR=${INPUT_WEBD_DIR:-$DEFAULT_WEBD_DIR}

# Construct paths without trailing slashes to prevent //
MAIN_DATA_PATH="/var/www/vhosts/$DOMAIN/.botlocker"

# --- 2. Parameters ---
read -p "Enable Dry Run? (true/false) [true]: " DRY_RUN
DRY_RUN=${DRY_RUN:-true}

echo -e "\nWeb banning parameters"
read -p "Hit Limit for bad web Requests [50]: " HIT_LIMIT
HIT_LIMIT=${HIT_LIMIT:-50}
read -p "Cluster limit /24 block [20]: " SUB_COUNT_LIMIT
SUB_COUNT_LIMIT=${SUB_COUNT_LIMIT:-20}
read -p "Cluster minimum [5]: " CLUSTER_MIN
CLUSTER_MIN=${CLUSTER_MIN:-5}

echo -e "\nMail Banning parameters"
read -p "Mail Bruteforce Threshold [5]: " THRESHOLD
THRESHOLD=${THRESHOLD:-5}

echo -e "SSH Banning parameters"
read -p "SSH/FTP Bruteforce Threshold [3]: " SSH_THRESHOLD
SSH_THRESHOLD=${SSH_THRESHOLD:-3}

# --- 2. Parameters ---
echo -e "\nJail Settings"
read -p "How many hours should an IP be banned? (0 for forever) [720]: " BAN_HOURS
BAN_HOURS=${BAN_HOURS:-720}
#read -p "Enable Package and byte count of blocked IPs [yes/no]: " BAN_PKG
# Fixed the regex to check the variable correctly
#if [[ "$BAN_PKG" =~ ^([yY][eE][sS]|[yY])$ ]]; then
    BAN_PKG="counters"
#else
#    BAN_PKG=""
#fi

# Logic for the IPSet creation
if [ "$BAN_HOURS" -eq 0 ]; then
    # Restored your --exist and variable name
    IPSET_PARAMS="hash:net $BAN_PKG"
    echo "Bans are PERMANENT."
else
    BAN_TIMEOUT=$((BAN_HOURS * 3600))
    # Restored your --exist and variable name
    IPSET_PARAMS="hash:net timeout $BAN_TIMEOUT $BAN_PKG"
    echo "Bans will expire after $BAN_HOURS hours (approx. $((BAN_HOURS / 24)) days)."
fi

echo -e "\nGeoIP Support"
read -p "Enable GeoIP Country Lookups? (true/false) [true]: " USE_GEOIP
USE_GEOIP=${USE_GEOIP:-true}

if [ "$USE_GEOIP" = "true" ]; then
echo -e "\nStep: GeoIP Support"
if command -v mmdblookup &> /dev/null; then
    echo -e "\033[0;32m[✔] mmdblookup tool is already installed.\033[0m"
else
    echo "Installing mmdb-bin tool..."
    apt-get update -qq && apt-get install -y mmdb-bin > /dev/null
fi
# First, check where MaxMind usually hides its fresh goods
if [ -d "/var/lib/GeoIP" ] && [ -s "/var/lib/GeoIP/GeoLite2-Country.mmdb" ]; then
    MMDB_DIR="/var/lib/GeoIP"
    echo -e "\033[0;32m[✔] Found fresh MaxMind database in $MMDB_DIR\033[0m"
else
    MMDB_DIR="/usr/share/GeoIP"
fi
ASN_FILE="$MMDB_DIR/GeoLite2-ASN.mmdb"
if [ ! -s "$ASN_FILE" ] && [ "$MMDB_DIR" == "/var/lib/GeoIP" ]; then
    echo -e "\033[0;33m[!] Note: GeoLite2-ASN.mmdb not found in /var/lib/GeoIP.\033[0m"
    echo "    Organization names will not be shown."
    ASN_FILE=""
fi
MMDB_FILE="$MMDB_DIR/GeoLite2-Country.mmdb"
[ ! -d "$MMDB_DIR" ] && mkdir -p "$MMDB_DIR"
if [ ! -s "$MMDB_FILE" ]; then
    echo "GeoIP database missing or empty. Downloading DB-IP Lite..."
    YM=$(date +%Y-%m)
    URL="https://download.db-ip.com/free/dbip-country-lite-${YM}.mmdb.gz"
    if wget -qO /tmp/geoip_setup.gz "$URL"; then
        gunzip -c /tmp/geoip_setup.gz > "$MMDB_FILE"
        rm /tmp/geoip_setup.gz
        echo -e "\033[0;32m[✔] Database installed.\033[0m"
        echo "NOTE: This database is probably outdated. To have it updated you need a free maxmind account"
        echo "1. Go to maxmind.com create a free account and an api Key"
        echo "2. Download the GeoIP.conf and put that to /etc/GeoIP.conf"
        echo "3. Update MMDB_FILE and ASN_FILE in /etc/botlocker.conf from /usr/share/GeoIP to /var/lib/GeoIP"
    else
        echo -e "\033[0;31m[!] Download failed. CC reports will show '??'.\033[0m"
    fi
else
    # It exists and is not empty - just report its age
    DB_AGE=$(stat -c %y "$MMDB_FILE" | cut -d' ' -f1)
    echo -e "\033[0;32m[✔] Existing database found (Last updated: $DB_AGE).\033[0m"
fi
echo -e "\033[0;32m[✔] GeoIP Support Installed.\033[0m"
else
    echo -e "\033[0;33m[!] GeoIP Support skipped by user.\033[0m"
fi

mkdir -p /etc/botlocker
mkdir -p /etc/botlocker/conf.d

# 1. Detect environment
IPS_TO_WHITELIST="127.0.0.1"
LOCAL_IPS=$(hostname -I)
[ ! -z "$LOCAL_IPS" ] && IPS_TO_WHITELIST="$IPS_TO_WHITELIST $LOCAL_IPS"
DOCKER_NET=$(ip -4 addr show docker0 2>/dev/null | grep -oP '(?<=inet )[\d\.]+' | cut -d. -f1-3)
FINAL_LIST=""
for ip in $IPS_TO_WHITELIST; do
    escaped=$(echo "$ip" | sed 's/\./\\./g')
    FINAL_LIST="$FINAL_LIST$escaped\n"
done
if [ ! -z "$DOCKER_NET" ]; then
    escaped_docker=$(echo "$DOCKER_NET" | sed 's/\./\\./g')
    FINAL_LIST="$FINAL_LIST$escaped_docker\\.\n"
fi
echo -e "$FINAL_LIST" | sort -u > /etc/botlocker/conf.d/admin-access.conf.tmp
cat << EOF > /etc/botlocker/conf.d/admin-access.conf
[MY_IPS]
# --- AUTO-DETECTED SAFE IPS ---
$(cat /etc/botlocker/conf.d/admin-access.conf.tmp)
EOF
rm /etc/botlocker/conf.d/admin-access.conf.tmp
chmod 600 /etc/botlocker/conf.d/admin-access.conf

# --- 3. Configuration File Generation ---
CONF_FILE="/etc/botlocker/botlocker.conf" 
cat << EOF > "$CONF_FILE"
# --- BotLocker Central Configuration ---
APP_NAME="BotLocker"
SERVER_NAME=\$(hostname)

#set to false if you want to ban otherwise log only
DRY_RUN=$DRY_RUN

#web log stuff
ACCESS_LOG_DIRS="/var/www/vhosts/system /var/log/nginx /var/log/apache2"
MAIL_LOG="/var/log/maillog"
MAIN_LOG="/var/log/botlocker/botlocker.log"
ERROR_LOG="/var/log/botlocker/error.log"
HIT_LIMIT=$HIT_LIMIT
VHOST_LIMIT=5
SUB_COUNT_LIMIT=$SUB_COUNT_LIMIT
CLUSTER_MIN=$CLUSTER_MIN

#mail section
THRESHOLD=$THRESHOLD

#ssh section
SSH_LOG="/var/log/auth.log"
SSH_THRESHOLD=$SSH_THRESHOLD
SSH_HAMMER_SEARCH=""

# Report Configuration
DOMAIN="$DOMAIN"
USE_GEOIP="$USE_GEOIP"
MMDB_FILE="$MMDB_FILE"
ASN_FILE="$ASN_FILE"
REPORT_TMP="/tmp/bot_report.txt"
WEB_DIR="$WEB_DIR"
MAIN_DATA_PATH="$MAIN_DATA_PATH"
REPORT_WEB_DEST="$MAIN_DATA_PATH/bot_report.txt"
TOP_LIMIT=30
NET_REPORT_TMP="/tmp/botnet_report.txt"
NET_REPORT_WEB_DEST="$MAIN_DATA_PATH/botnet_report.txt"
IP_REPORT_WEB="$MAIN_DATA_PATH/current_bans.txt"
UNBAN_REQUEST_FILE="$MAIN_DATA_PATH/unban_request.txt"
SHAME_PATH="$MAIN_DATA_PATH/hallof.shame"
# --- CORE IDENTITY ---
# WARNING: Do not modify IPSET_NAME if this file is already in /etc
# and the install script has been executed. Renaming this requires 
# a manual ipset rename in the kernel.
IPSET_PARAMS="$IPSET_PARAMS"
IPSET_NAME="botlocker_trap"
SAVE_FILE="/etc/botlocker/ipset.\$IPSET_NAME.conf"
EOF

cat << 'EOF' > /etc/botlocker/conf.d/ssh-errors.conf
[BLACKLIST]
# Standard noise (The "Slow Burn")
authenticating user
invalid user
Failed password
Connection closed
Identification string
Incorrect password
Login failed
EOF
chmod 600 /etc/botlocker/conf.d/ssh-errors.conf

cat << 'EOF' > /etc/botlocker/conf.d/web-honey-path.conf
[BLACKLIST]
#URL Blacklist against webtesting bots uncomment modify or add some strings/exppressions
#/internal/api
#/credentials
#\.env
#backup\.sql 
#(bak|bac|backup|old|site-.*)\.(zip|tar|gz|rar|7z)
[WHILETLIST]
EOF
chmod 600 /etc/botlocker/conf.d/web-honey-path.conf

# Create the web bad-bots file
# Create the web bad-bots file with detailed instructions
cat << 'EOF' > /etc/botlocker/conf.d/web-bad-bots.conf
[BLACKLIST]
# --- USER AGENT BLACKLIST ---
# Add strings found in the User-Agent header. 
# Matches are case-insensitive. Regex is supported.
# MSIE
# Windows NT [4-6]\¸[0-2]
# Bytespider
# cypex\.ai

[WHITELIST]
# --- FILE EXTENSION WHITELIST ---
# If a request matches these patterns, BotLocker will ignore it.
# This prevents banning users who just happen to load many small assets.
\.aac
favicon.ico
\.png
\.jpg
\.jpeg
\.gif
robots.txt
\.css
\.js
\.mjs
EOF
chmod 600 /etc/botlocker/conf.d/web-bad-bots.conf

cat << 'EOF' > /etc/botlocker/conf.d/mail-bad-users.conf
[BLACKLIST]
# --- INSTANT BAN USERNAMES ---
# Any login attempt using these specific usernames (e.g., from maillog)
# will result in an immediate IP jail without waiting for the threshold.
# admin
# administrator
# root
# support
EOF
chmod 600 /etc/botlocker/conf.d/mail-bad-users.conf

echo -e "Configuration saved to $CONF_FILE\n"

# --- 4. System Deployment ---
echo -e "Copying files...\n"
mkdir -p /var/log/botlocker
cp botlocker-* /usr/local/sbin/
chmod +x /usr/local/sbin/botlocker*
cp common.sh /etc/botlocker/
mkdir -p /var/log/botlocker
mkdir -p "$MAIN_DATA_PATH"
cp -r /etc/botlocker/conf.d "$MAIN_DATA_PATH/"

echo -e "Create logrotate ...\n"
cat << 'EOF' > /etc/logrotate.d/botlocker
/var/log/botlocker/botlocker.log {
    size 5M
    rotate 5
    compress
    delaycompress
    missingok
    notifempty
    create 0644 root root
}
EOF
chmod 644 /etc/logrotate.d/botlocker

cat << 'EOF' > /etc/botlocker/conf.d/permanent-bans.conf
# --- PERMANENT IP BLACKLIST ---
# IPs listed here will have their timers reset to "Forever" 
# every time the botlocker-unban or maintenance script runs.
# 1.2.3.4
# 8.8.4.4
EOF

echo "Configuration saved to $CONF_FILE"
else
    echo "Using existing parameters from $CONF_FILE"
fi

if [[ -f "$CONF_FILE" ]]; then
    source "$CONF_FILE"
else
    echo "Error: Config file was not created. Exiting."
    exit 1
fi

echo -e "Setting up ipset and iptables...\n"
if ! /sbin/ipset list "$IPSET_NAME" &>/dev/null; then
     /sbin/ipset create "$IPSET_NAME" $IPSET_PARAMS --exist
     echo "ipset created."
else
     echo "ipset already present."
fi
if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
    echo "Adding BotLocker rule to IPTables..."
    /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
else
    echo "BotLocker rule already active in IPTables. Skipping to prevent duplicates."
fi
ipset save "$IPSET_NAME" > "/etc/botlocker/ipset.$IPSET_NAME.conf"
echo -e "\033[0;32m[✔] Ipset saved to /etc/botlocker/ipset.$IPSET_NAME.conf\033[0m"

echo -e "Installing persistence service...\n"
cp botlocker.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable botlocker.service
systemctl start botlocker.service

echo -e "Installing cronjobs...\n"
# Add cronjobs to /etc/crontab or a dedicated cron file
cat << 'EOF' > /etc/cron.d/botlocker
# 1. Clear the Unban queue every 5 minutes
*/5 * * * * root /usr/local/sbin/botlocker-unban
# 2. Trap bots: Mail every 10, Web and SSH every minute (high priority)
*/5 * * * * root /usr/local/sbin/botlocker-mail
*/1 * * * * root /usr/local/sbin/botlocker-web
*/5 * * * * root /usr/local/sbin/botlocker-ssh
# 3. Reports
0 * * * * root /usr/local/sbin/botlocker-top10-report
0 1 * * * root /usr/local/sbin/botlocker-net-report
EOF

chmod 644 /etc/cron.d/botlocker
chown root:root /etc/cron.d/botlocker

echo "Setting up the timezone"
SYS_TZ=$(cat /etc/timezone 2>/dev/null || readlink /etc/localtime | sed 's#^.*/zoneinfo/##')
if [ -z "$SYS_TZ" ]; then SYS_TZ="UTC"; fi
sed -i "s|INSERT_TIMEZONE_HERE|$SYS_TZ|g" ./index.dist.php
echo "Timezone set to $SYS_TZ"
BasePath="/var/www/vhosts/$DOMAIN/.botlocker"
sed -i "s|INSERT_DATA_DIR_HERE|$BasePath|g" ./index.dist.php

cp ./index.dist.php ./webui/index.php
FINAL_DEST="/var/www/vhosts/$DOMAIN/$WEB_DIR/$WEBD_DIR"
mkdir -p "$FINAL_DEST"
cp -r ./webui/* "$FINAL_DEST/"

echo -e "\nStep: Dashboard Security"
read -p "Create Dashboard Username: " DASH_USER
while true; do
    read -s -p "Create Dashboard Password: " DASH_PASS1; echo
    read -s -p "Confirm Dashboard Password: " DASH_PASS2; echo
    [ "$DASH_PASS1" = "$DASH_PASS2" ] && break
    echo -e "\033[0;31mPasswords do not match. Try again.\033[0m"
done

echo "Protecting directory $WEB_DIR via Plesk..."
if command -v plesk >/dev/null 2>&1; then
    IS_PLESK=true
    echo "[i] Plesk detected. Using native directory protection."
else
    IS_PLESK=false
    echo "[i] Plesk not detected. Falling back to .htaccess protection."
fi

if [ "$IS_PLESK" = false ]; then
    # Fixed variable names (added underscore) and removed redundant slashes
    HT_PATH="/var/www/vhosts/$DOMAIN/$WEB_DIR/.htpasswd"
    
    # Ensure PHP_BIN is defined or use system default
    PHP_CMD=$(command -v php)
    HT_ENTRY=$($PHP_CMD -r "echo '$DASH_USER:' . crypt('$DASH_PASS1', base64_encode('$DASH_PASS1'));")
    
    echo "$HT_ENTRY" > "$HT_PATH"
    
    cat <<EOF > "/var/www/vhosts/$DOMAIN/$WEB_DIR/.htaccess"
AuthType Basic
AuthName "Admin Area"
AuthUserFile "$HT_PATH"
Require valid-user
EOF
    chmod 644 "$HT_PATH" "/var/www/vhosts/$DOMAIN/$WEB_DIR/.htaccess"
fi
echo -e "Initial BotLocker run...\n"
/usr/local/sbin/botlocker-mail && /usr/local/sbin/botlocker-web && /usr/local/sbin/botlocker-ssh && /usr/local/sbin/botlocker-top10-report && /usr/local/sbin/botlocker-net-report && /usr/local/sbin/botlocker-unban
tail -n10 $MAIN_LOG
echo -e "\nDONE. BotLocker is active, initial reports can be found at /var/www/vhosts/$DOMAIN/.botlocker\n"
echo "Done"