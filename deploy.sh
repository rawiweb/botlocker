#!/bin/bash

# Define the target directory
TARGET="/usr/local/sbin"

# List of files to deploy
FILES=(
    "botlocker-mail"
    "botlocker-net-report"
    "botlocker-ssh"
    "botlocker-top10-report"
    "botlocker-unban"
    "botlocker-web"
)

# 1. Check for root privileges
if [[ $EUID -ne 0 ]]; then
   echo "Error: This script must be run as root (sudo)." 
   exit 1
fi

echo "Deploying BotLocker binaries to $TARGET..."

# 2. Copy and set permissions
for FILE in "${FILES[@]}"; do
    if [ -f "$FILE" ]; then
        cp "$FILE" "$TARGET/"
        chmod +x "$TARGET/$FILE"
        echo " [+] Installed: $FILE"
    else
        echo " [!] Warning: $FILE not found in current directory, skipping."
    fi
done

echo "Deployment complete."
