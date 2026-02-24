# 🛡️ botlocker v1.0

**botlocker** is a modular, log-based Intrusion Prevention System (IPS) designed for **Plesk Obsidian** environments (Ubuntu 24.04). It leverages `ipset` to provide high-performance, $O(1)$ lookup banning, effectively neutralizing brute-force bots and malicious `/24` subnet clusters via keyword detection.



## 🌟 Why botlocker?

* **Eliminate Botnet Noise:** Built specifically to stop distributed botnets that harass servers where Fail2ban often fails due to its "ban and release" cycle.
* **Plesk Native:** Designed to navigate the unique log structures and multi-tenant paths of Plesk (`/var/www/vhosts/system/*/logs`).
* **High Performance:** Unlike standard firewall rules that slow down your network as the list grows, `ipset` uses hash sets to maintain instant speeds even with tens of thousands of active bans.
* **Subnet Intelligence:** Detects distributed attacks from the same provider/range and wipes out the entire `/24` block automatically.
* **Zero-Friction Setup:** Includes an interactive Wizard that handles configuration, GeoIP databases, systemd persistence, and dashboard security.

---

## 🚀 Features
* **Multi-Vector Protection:** Monitors Web (Nginx/Apache), Mail (Postfix/Dovecot), and SSH logs.
* **Modular Config:** Drop configuration snippets into `conf.d/` for easy management.
* **Low Overhead:** Uses `ipset` (Linux Kernel) instead of standard IPTables chains for near-zero CPU impact.
* **Self-Healing:** Automatically repairs firewall rules if they are accidentally deleted.
* **Plesk Ready:** Native support for Plesk log structures and PHP environments.

---

## 🛠 Installation & Setup
1.  **Clone the repository:**
    ```bash
    git clone [https://github.com/rawiweb/botlocker.git](https://github.com/rawiweb/botlocker.git)
    ```

2.  **Run the Deployment Wizard:**
    The wizard will check for dependencies, configure thresholds, and secure your dashboard.
    ```bash
    cd botlocker
    chmod +x installer.sh
    ./installer.sh
    ```

---

## 📁 Modular Architecture

BotLocker uses a **Config-D** approach. You can tune specific threat vectors via Blacklist/Whitelist in `/etc/botlocker/conf.d/` without touching the core scripts:

| Module | Purpose | Source Log |
| :--- | :--- | :--- |
| **`web-honey-path`** | Instant bans for accessing sensitive paths (e.g., `.env`, `backup.sql`). | Nginx / Apache |
| **`mail-bad-users`** | Traps bots attempting to login as generic aliases (e.g., `admin`, `root`). | Maillog |
| **`ssh-errors`** | Detects protocol negotiation failures and "slow-burn" SSH noise. | Auth.log |
| **`web-bad-bots`** | Filters by malicious User-Agent strings (e.g., `Bytespider`, `zgrab`). | Nginx / Apache |

---

## 📊 Dashboard & Monitoring

The installer generates a secured `botlocker.php` file based on your credentials. 

* **Placement:** Move this file to a password-protected directory within your webroot.
* **Unbanning:** Manage the active ban list and the unban queue directly from the interface.
* **Intelligence:** Real-time GeoIP mapping and ISP identification of attackers.

---

## ⚙️ Administration & Maintenance

* **Dry Run Mode:** Toggle `DRY_RUN=true` in `/etc/botlocker/botlocker.conf` to test patterns without dropping traffic.
* **Persistence:** Managed via `botlocker-set.service`. Your ban list is saved to disk and restored automatically on reboot.
* **Manual Management:**
    * View bans: `ipset list botlocker_trap`
    * Flush all: `ipset flush botlocker_trap`
* **Logs:** Monitor the engine activity at `/var/log/botlocker/botlocker.log`.

---

## ⚖️ License

Licensed under the **GNU Affero General Public License v3 (AGPL-3.0)**.

> **Note on AGPL:** This license ensures that if you modify **botlocker** and run it as a service (even if you don't "distribute" the binary), you must make your modified source code available to your users.

---

## 🚀 Roadmap / TODO

- [ ] **Error.log Integration:** the botlocker as is of now looks for failures in the usual *access*log files only.
- [X] **Automated Unban after XX:** one can unban via Dashboard or direct via ipset del no automation yet.
- [ ] **Service ports blocking:** vreating or managing different service ports bans.
- [ ] **IPv6 support:** currently only ip4 is scanned and handeled (not much going on ip6 currently).
- [ ] **Platform Independence:** Expand beyond Ubuntu 24 to support Debian, CentOS, and AlmaLinux natively.
- [ ] **NFTables Support:** Add a backend module for `nftables` (the successor to iptables).
- [ ] **Telegram/Slack Alerts:** Optional notifications when a major subnet (/24) is locked down.
- [ ] **Auto-update:** A safe mechanism to pull new threat signatures (bad user agents/honey paths) from the master repo.