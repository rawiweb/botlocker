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

read -p "Enter Domain Name for the dashboard [$GUESSED_DOMAIN]: " USER_DOMAIN
DOMAIN=${USER_DOMAIN:-$GUESSED_DOMAIN}

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

# Logic for the IPSet creation
if [ "$BAN_HOURS" -eq 0 ]; then
    IPSET_PARAMS="hash:net --exist"
    echo "Bans are PERMANENT."
else
    BAN_TIMEOUT=$((BAN_HOURS * 3600))
    IPSET_PARAMS="hash:net timeout $BAN_TIMEOUT" --exist
    echo "Bans will expire after $BAN_HOURS hours (approx. $((BAN_HOURS / 24)) days)."
fi

echo -e "\nGeoIP Support"
read -p "Enable GeoIP Country Lookups? (true/false) [true]: " USE_GEOIP
USE_GEOIP=${USE_GEOIP:-true}

mkdir -p /etc/botlocker
mkdir -p /etc/botlocker/conf.d

# 1. Detect current system environment
IPS_TO_WHITELIST="127.0.0.1"
LOCAL_IPS=$(hostname -I)
[ ! -z "$LOCAL_IPS" ] && IPS_TO_WHITELIST="$IPS_TO_WHITELIST $LOCAL_IPS"

DOCKER_NET=$(ip -4 addr show docker0 2>/dev/null | grep -oP '(?<=inet )[\d\.]+' | cut -d. -f1-3)
[ ! -z "$DOCKER_NET" ] && IPS_TO_WHITELIST="$IPS_TO_WHITELIST $DOCKER_NET."

# 2. Write the config file with the detected IPs already inside
cat << EOF > /etc/botlocker/conf.d/admin-access.conf
[MY_IPS]
# --- AUTO-DETECTED SAFE IPS ---
# These IPs were detected during installation and are whitelisted.
$(echo "$IPS_TO_WHITELIST" | tr ' ' '\n' | sort -u)

# --- MANUAL WHITELIST ---
# Add your static Office or Home IPs below (one per line).
EOF

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
ACCESS_LOG_DIR="/var/www/vhosts/system/*/logs /var/log/nginx /var/log/apache2"
MAIL_LOG="/var/log/maillog"
MAIN_LOG="/var/log/botlocker/botlocker.log"
HIT_LIMIT=$HIT_LIMIT
VHOST_LIMIT=5
SUB_COUNT_LIMIT=$SUB_COUNT_LIMIT
CLUSTER_MIN=$CLUSTER_MIN
EXT_EXCLUSIONS=""
IGNORE_STRINGS="richdocuments"
#HONEY_PATHS="" now configures in web-.....conf

# Search Patterns (Mail)
#MAIL_SEARCH="" now configured in conf.d mail-...conf
THRESHOLD=$THRESHOLD

#ssh section
SSH_LOG="/var/log/auth.log"
SSH_THRESHOLD=$SSH_THRESHOLD
SSH_HAMMER_SEARCH="Unable to negotiate|banner exchange|protocol version|SECURITY VIOLATION|Maximum login attempts"

# Report Configuration
DOMAIN="$DOMAIN"
USE_GEOIP="$USE_GEOIP"
REPORT_TMP="/tmp/bot_report.txt"
REPORT_WEB_DEST="/var/www/vhosts/\$DOMAIN/bot_report.txt"
TOP_LIMIT=30
NET_REPORT_TMP="/tmp/botnet_report.txt"
NET_REPORT_WEB_DEST="/var/www/vhosts/\$DOMAIN/botnet_report.txt"
IP_REPORT_WEB="/var/www/vhosts/$DOMAIN/botlocker_current_bans.txt"
UNBAN_REQUEST_FILE="/var/www/vhosts/$DOMAIN/botlocker_unban_request.txt"
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
EOF
chmod 600 /etc/botlocker/conf.d/web-honey-path.conf

# Create the web bad-bots file
# Create the web bad-bots file with detailed instructions
cat << 'EOF' > /etc/botlocker/conf.d/web-bad-bots.conf
[BLACKLIST]
# --- USER AGENT BLACKLIST ---
# Add strings found in the User-Agent header. 
# Matches are case-insensitive. Regex is supported.
MSIE
# Windows NT 5\.1
# Bytespider
# cypex\.ai

[WHITELIST]
# --- FILE EXTENSION WHITELIST ---
# If a request matches these patterns, BotLocker will ignore it.
# This prevents banning users who just happen to load many small assets.
\.aac
\.ico
\.png
\.jpg
\.jpeg
\.gif
\.txt
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
cp botlocker* /usr/local/sbin/
chmod +x /usr/local/sbin/botlocker*

echo -e "Create logrotate ...\n"
cat << 'EOF' > /etc/logrotate.d/botlocker
/var/log/botlocker/botlocker.log {
    size 20M
    rotate 5
    compress
    delaycompress
    missingok
    notifempty
    create 0644 root root
}
EOF
chmod 644 /etc/logrotate.d/botlocker

cat << 'EOF' > /etc/botlocker/common.sh
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

# Self-Healing Firewall Integrity
ensure_firewall_integrity() {
    if [ "$DRY_RUN" = "false" ]; then
        /sbin/ipset create "$IPSET_NAME" "$IPSET_PARAMS" 2>/dev/null
        if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
            /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
        fi
        local count
        count=$(/sbin/ipset list "$IPSET_NAME" 2>/dev/null | grep "Number of entries" | awk '{print $4}')
        echo "${count:-0}"
    fi
}
EOF

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
ipset create "$IPSET_NAME" "$IPSET_PARAMS"
if ! /sbin/iptables -C INPUT -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
    echo "Adding BotLocker rule to IPTables..."
    /sbin/iptables -I INPUT 1 -m set --match-set "$IPSET_NAME" src -j DROP
else
    echo "BotLocker rule already active in IPTables. Skipping to prevent duplicates."
fi
ipset save "$IPSET_NAME" > "/etc/botlocker/ipset.$IPSET_NAME.conf"
echo -e "\033[0;32m[✔] Ipset saved to /etc/botlocker/ipset.$IPSET_NAME.conf\033[0m"

echo -e "Installing persistence service...\n"
cp botlocker-set.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable botlocker-set.service
systemctl start botlocker-set.service

echo -e "Installing cronjobs...\n"
# Add cronjobs to /etc/crontab or a dedicated cron file
cat << 'EOF' > /etc/cron.d/botlocker
# 1. Clear the Unban queue every 5 minutes
*/5 * * * * root /usr/local/sbin/botlocker-unban >> /var/log/botlocker/error.log 2>&1
# 2. Trap bots: Mail every 10, Web and SSH every minute (high priority)
*/10 * * * * root /usr/local/sbin/botlocker-mail >> /var/log/botlocker/error.log 2>&1
*/5 * * * * root /usr/local/sbin/botlocker-web >> /var/log/botlocker/error.log 2>&1
*/10 * * * * root /usr/local/sbin/botlocker-ssh >> /var/log/botlocker/error.log 2>&1
# 3. Reports
0 * * * * root /usr/local/sbin/botlocker-top10-report
0 1 * * * root /usr/local/sbin/botlocker-net-report
EOF

chmod 644 /etc/cron.d/botlocker
chown root:root /etc/cron.d/botlocker

if [ "$USE_GEOIP" = "true" ]; then
echo -e "\nStep: GeoIP Support"
if command -v mmdblookup &> /dev/null; then
    echo -e "\033[0;32m[✔] mmdblookup tool is already installed.\033[0m"
else
    echo "Installing mmdb-bin tool..."
    apt-get update -qq && apt-get install -y mmdb-bin > /dev/null
fi
MMDB_DIR="/usr/share/GeoIP"
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
echo -e "\nStep: Dashboard Security"
read -p "Create Dashboard Username: " DASH_USER

# Password with confirmation
while true; do
    read -s -p "Create Dashboard Password: " DASH_PASS1; echo
    read -s -p "Confirm Dashboard Password: " DASH_PASS2; echo
    [ "$DASH_PASS1" = "$DASH_PASS2" ] && break
    echo -e "\033[0;31mPasswords do not match. Try again.\033[0m"
done

# Find the right PHP binary
if command -v php >/dev/null 2>&1; then
    PHP_BIN=$(command -v php)
else
    PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -r | head -n 1)
fi

if [ -z "$PHP_BIN" ]; then
    echo -e "\033[0;31m[!] Error: PHP not found.\033[0m"
    exit 1
fi

echo "Using PHP: $PHP_BIN"

# 1. Generate the hash and export it so PHP can see it via getenv()
export DASH_USER_ENV="$DASH_USER"
export HASHED_PASS_ENV=$($PHP_BIN -r "echo password_hash('$DASH_PASS1', PASSWORD_DEFAULT);")

# 2. Use PHP to create the secured file (This avoids all Bash $ expansion issues)
if [ -f "botlocker.dist.php" ]; then
    echo "Generating secured dashboard file..."
    
    $PHP_BIN -r '
        $template = file_get_contents("botlocker.dist.php");
        $user = getenv("DASH_USER_ENV");
        $hash = getenv("HASHED_PASS_ENV");
        
        $output = str_replace("INSERT_USERNAME_HERE", $user, $template);
        $output = str_replace("INSERT_HASH_HERE", $hash, $output);
        
        if (file_put_contents("botlocker.php", $output)) {
            exit(0);
        } else {
            exit(1);
        }' 

  if [ $? -eq 0 ]; then
    echo -e "\033[0;32m[✔] Verification Passed: Password and Hash are in sync.\033[0m"
else
    echo -e "\033[0;31m[!] CRITICAL ERROR: Hash verification failed!\033[0m"
    echo "The password you entered does not match the hash in botlocker.php."
    echo "This is likely due to character encoding or a mangled write operation."
    rm -f botlocker.php
    echo "Simply run the installer again to retry."
    exit 1
fi

    if [ $? -eq 0 ]; then
       echo "------------------------------------------------------------------------"
       echo -e "\033[0;32m[✔] Success! A secured version has been created: botlocker.php\033[0m"
       echo "Next Step: Copy botlocker.php to your web directory"
       echo "On plesk servers that would be /var/www/vhosts/$DOMAIN/httpdocs"
       echo "------------------------------------------------------------------------"
    else
        echo -e "\033[0;31m[!] Error: Could not write botlocker.php\033[0m"
    fi
else
    echo -e "\033[0;31m[!] Error: botlocker.dist.php template not found.\033[0m"
fi

echo -e "Initial BotLocker run...\n"
/usr/local/sbin/botlocker-mail && /usr/local/sbin/botlocker-web && /usr/local/sbin/botlocker-ssh && /usr/local/sbin/botlocker-top10-report && /usr/local/sbin/botlocker-net-report && /usr/local/sbin/botlocker-unban
tail -n10 $MAIN_LOG
echo -e "\nDONE. BotLocker is active, initial reports can be found at /var/www/vhosts/$DOMAIN\n"
echo "Done"