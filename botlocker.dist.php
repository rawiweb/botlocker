<?php
// These will be replaced by the installer
$authorized_user = 'INSERT_USERNAME_HERE';
$authorized_hash = 'INSERT_HASH_HERE';
    
session_start();

function datediff($ts){
    if (!$ts || $ts == 0) return "Permanent";
    $raw_seconds = $ts ?? 0;
    $now = new DateTime('@0');
    $expiry = new DateTime("@$raw_seconds");
    $diff = $now->diff($expiry);
    return $diff->format('%ad %hh %im');
}

if (!isset($_SESSION['logged_in'])) {
    if (isset($_SERVER['PHP_AUTH_USER']) && 
        $_SERVER['PHP_AUTH_USER'] === $authorized_user && 
        password_verify($_SERVER['PHP_AUTH_PW'], $authorized_hash)) {
        $_SESSION['logged_in'] = true;
    } else {
        header('WWW-Authenticate: Basic realm="BotLocker Dashboard"');
        header('HTTP/1.0 401 Unauthorized');
        die("Restricted Access.");
    }
}

// File Paths
$logPath            = '/var/log/botlocker/botlocker.log';
$summaryFile        = '../botnet_report.txt';
$reportFile         = '../bot_report.txt';
$unbannFile         = '../botlocker_unban_request.txt';
$current_bans_file  = '../botlocker_current_bans.txt';

/**
 * AJAX ACTIONS
 */

// 1. Unban / Permban Request
if (isset($_POST['action'])){
if ( $_POST['action'] == 'Unban' ||  $_POST['action'] == 'Permban') {
   $ip = trim($_POST['ip']); // Remove whitespace/newlines
   $prefix =  $_POST['action'] == 'Unban' ? "ubn " : "prm ";
if (filter_var($ip, FILTER_VALIDATE_IP) || preg_match('/^[0-9.]+\/[0-9]+$/', $ip)) {
    file_put_contents($unbannFile, $prefix.$ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode(["status" => "success"]);
} else {
    http_response_code(400); // Tell JS this was a bad request
    echo json_encode(["status" => "error", "message" => "Invalid IP format ".$ip]);
}
    exit;
}
}
// 2. Search Request
if (isset($_GET['action']) && $_GET['action'] == 'search' && isset($_GET['ip'])) {
    $search_term = $_GET['ip'];
    $current_bans = file_exists($current_bans_file) ? file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    $matches = array_filter($current_bans, function($line) use ($search_term) {
        return (strpos($line, $search_term) !== false);
    });
    $matches = array_slice($matches, 0, 10);

    $results = [];
    foreach ($matches as $full_line) {
        $line_parts = explode(' ', trim($full_line));
        $clean_ip = $line_parts[0]; // This is just "139.59.208.246"
        
        $reason = "Unknown activity";
        if (file_exists($logPath)) {
            $escaped_ip = escapeshellarg($clean_ip);
            $last_log = shell_exec("grep $escaped_ip $logPath | tail -n 1");
            
            if ($last_log) {
                $parts = explode('|', $last_log);
                $reason = ($parts[4] ?? "Unknown") . " (" . (trim($parts[5] ?? "No details")) . ")";
            }
        }
        
        // 3. Return 'ip' as the full line for the UI, but we used clean_ip for the logic
        $results[] = ['ip' => $clean_ip, 'timeout' => (datediff($line_parts[2])),'reason' => $reason
];
    }
    echo json_encode(['status' => !empty($results) ? 'found' : 'clear', 'data' => $results, 'count' => count($results)]);
    exit;
}

// 3. rDNS Lookup
if (isset($_GET['action']) && $_GET['action'] == 'lookup' && isset($_GET['ip'])) {
    $ip = $_GET['ip'] ?? '';
    session_write_close();
    echo (filter_var($ip, FILTER_VALIDATE_IP)) ? htmlspecialchars(gethostbyaddr($ip)) : "Invalid IP";
    exit;
}

// Prepare Main Log Data
$logLines = file_exists($logPath) ? array_reverse(file($logPath)) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Botlocker Node Stats</title>
    <style>
        :root { --primary: #3498db; --danger: #e74c3c; --success: #2ecc71; --warning: #f1c40f; --bg: #1a1a1a; --card: #333; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); color: #eee; padding: 20px; line-height: 1.4; }
        h1, h3 { margin-bottom: 10px; }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .system-time { font-family: monospace; color: var(--success); background: #000; padding: 8px 12px; border-radius: 4px; }
        
        .stat-card { background: var(--card); padding:15px; border-radius: 5px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .total { color: var(--success); font-weight: bold; font-size: 1.2em; }
        
        #ipsearch { background: #000; color: var(--success); border: 1px solid #444; padding: 10px; width: 250px; border-radius: 4px; }
        #results { margin-top: 10px; }

        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 5px; overflow: hidden; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #444; }
        th { background: #444; cursor: pointer; transition: 0.2s; }
        th:hover { background: #555; }
        
        .web { color: var(--primary); }
        .mail { color: #e67e22; }
        .ssh { color: var(--success); font-weight: bold; }
        
        .iptab { display:block; font-family: monospace; }
        .rdns-pending { font-weight: 300; font-size: 0.85em; color: #888; }
        
        .report-table-wrapper { overflow-x: auto; }
        .report-table td:last-child, #log-container td:last-child { max-width: 500px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: help; }
        .report-table td:last-child:hover,#log-container td:last-child:hover { white-space: normal; background: #2c3e50; position: relative; z-index: 10; word-break: break-all; border: 1px solid var(--danger); }

        /* Sync Status Box */
        #sync-status { position: fixed; top: 20px; right: 20px; background: var(--card); padding: 15px; border-radius: 8px; display: none; box-shadow: 0 0 20px rgba(0,0,0,0.5); border: 1px solid #444; min-width: 200px; z-index: 100; }
        .sync-msg { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #aaa; display: block; }
        #sync-ip { color: var(--success); font-size: 0.9em; margin: 5px 0; font-weight: bold; }
        .sync-timer { color: #eee; font-weight: bold; }

        .neutralized:after { content:' 🚫'; }
        .th-sort-asc::after { content: " ▲"; }
        .th-sort-desc::after { content: " ▼"; }
        
        /* Progress Bars */
        .bar-container { background:#222; height:8px; border-radius:4px; margin-top: 4px; }
        .bar-fill { height:100%; background: var(--success); box-shadow: 0 0 10px var(--success); border-radius:4px; }
        
        /* Scroll Container Logic */
    .scroll-container {  background: var(--card);         border-radius: 5px;         height: 500px; /* Adjust height as needed */
        overflow-y: auto;  border: 1px solid #444;        position: relative;
    }
    table { width: 100%; border-collapse: collapse; }
    thead th { position: sticky; top: 0;  background: #444;  z-index: 20;    box-shadow: 0 2px 2px rgba(0,0,0,0.5);
    }

    th, td { text-align: left; padding: 12px; border-bottom: 1px solid #444; }
    
    /* Filter Bar Styling */
    .filter-bar {  margin-bottom: 10px; display: flex; gap: 10px;  background: #222;   padding: 10px;    border-radius: 5px; 
    }
    .filter-bar select, .filter-bar input { background: #333; color: #fff; border: 1px solid #555; padding: 5px; border-radius: 3px;
    }
    .row-hidden { display: none; }
    </style>
</head>
<body>

<div id="sync-status">
    <span class="sync-msg">Pending Permbans/Unbans</span>
    <div id="sync-ip"></div>
    <span class="sync-msg">Next Sync in</span>
    <span id="countdown" class="sync-timer">--:--</span>
</div>

<div class="stat-header">
    <h1>🛡️ Botlocker Node Stats</h1>
    <div class="system-time">[SYSTEM TIME]: <?= date("Y-m-d H:i:s T") ?></div>
</div>

<div style="display: flex; gap: 20px; align-items: flex-start;">
    <div class="stat-card" style="flex: 1;">
        Search Jail: <input type="text" id="ipsearch" placeholder="Type IP Address...">
        <div id="results"></div>
    </div>
    <div class="stat-card" style="min-width: 200px;">
        Current Jail Size: <br>
        <span class="total">
            <?php 
            if (file_exists($current_bans_file)) {
                echo count(file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            } else echo "0";
            ?>
        </span>
    </div>
</div>

<div class="stat-card">
    <div style="display:flex; justify-content:space-between; align-items:center">
        <h3>🕒 Recent Activity</h3>
        <div class="filter-bar">
            <select id="filterType" onchange="applyFilters()">
                <option value="">All Types</option>
                <option value="WEB">WEB</option>
                <option value="MAIL">MAIL</option>
                <option value="SSH">SSH</option>
            </select>
            <input type="text" id="filterReason" placeholder="Filter Reason..." onkeyup="applyFilters()">
        </div>
    </div>
    
    <div class="scroll-container">
        <table id="log-container">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Release in</th>
                    <th>Type</th>
                    <th>Count</th>
                    <th>Identity</th>
                    <th>Reason</th>
                    <th>Evidence</th>
                    
                </tr>
            </thead>
            <tbody>
    
            <?php
            $ban_timers = [];
            if (file_exists($current_bans_file)) {
                $ban_lines = file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($ban_lines as $line) {
                    $parts = explode(' ', trim($line));
                    if (count($parts) >= 3) {
                        // Index by IP, store the raw seconds
                        $ban_timers[$parts[0]] = $parts[2];
                    }
                }
            }
            
            
	     $displayItems = array_slice($logLines, 0, 1000); 
    


foreach ($displayItems as $line): 
    $parts = array_map('trim', explode('|', $line));
    if(count($parts) < 3) continue;

    $log_time_str = trim($parts[0]); 
    $log_timestamp = strtotime($log_time_str); 
    
    $ipa = explode(' ', $parts[3]);
    $ip = $ipa[1]; 
    $raw_timeout = isset($ban_timers[$ip]) ? $ban_timers[$ip] : null;

    if ($raw_timeout !== null) {
        // STATE 1: Banned
        $timeout_display = datediff($raw_timeout);
    } else {
        // STATE 2 & 3: Not in ban file
        if (!$log_timestamp) {
            // Safety check: if string didn't parse, don't show "Pending"
            $timeout_display = '<span style="color:red;">Format Err</span>';
        } else {
            $age = time() - $log_timestamp;
            if ($age <= 0 ) { 
                $timeout_display = '<span style="color:gray;">Pending...</span>';
            } else {
                $timeout_display = '<span style="color:gray;">Released</span>';
            }
        }
    }
            ?>
                <tr class="log-row" data-type="<?= $parts[1] ?>" data-reason="<?= strtolower($parts[4] ?? '') ?>"  data-evidence="<?= strtolower($parts[5] ?? '') ?>">
                    <td style="color:#888;"><?= $parts[0] ?></td>
                    <td><?= $timeout_display ?></td>
                    <td class="<?= strtolower($parts[1]) ?>"><strong><?= $parts[1] ?></strong></td>
                    <td><?= $parts[2] ?></td>
                    <td>
                        <span class="iptab"><?= $parts[3] ?></span>
                        <span data-ip="<?= $ip ?>" class="rdns-pending">...</span>
                    </td>
                    <td><?= $parts[4] ?? '' ?></td>
                    <td style="font-size:0.85em; color:#bbb;"><?= urldecode($parts[5]) ?? '' ?></td>
                    
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="stat-card">
    <h3>🔥 Top 10 Global Attackers</h3>
    <div class="report-table-wrapper scroll-container">
        <table class="report-table" id="top-10-container">
            <thead>
                <tr>
                    <th>Hits</th>
                    <th>CC</th>
                    <th>IP Address</th>
                    <th>Subnet</th>
                    <th>Last Target</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (is_readable($reportFile)) {
                    $lines = file($reportFile);
                    foreach (array_slice($lines, 3) as $line) {
                        $cols = array_map('trim', explode('|', $line));
                        if (count($cols) < 4 || $cols[0] == "Hits") continue;
                        $ip = $cols[2];
                ?>
                    <tr>
                        <td style="color: var(--danger); font-weight: bold;"><?= $cols[0] ?> <small>(<?= $cols[4] ?> sites)</small></td>
                        <td><a href="https://ipinfo.io/<?= $ip ?>" target="_blank" style="color: var(--primary);"><?= $cols[1] ?></a></td>
                        <td><a href="https://ipinfo.io/<?= $ip ?>" target="_blank" style="color: var(--primary);"><?= $ip ?></a></td>
                        <td style="color: #888;"><?= $cols[3] ?></td>
                        <td style="color: var(--danger);"><?= $cols[5] ?></td>
                    </tr>
                <?php 
                    }
                } else echo "<tr><td colspan='5'>Report not available yet.</td></tr>";
                ?>
            </tbody>
        </table>
    </div>
</div>

<div class="stat-card">
    <h3>📊 Daily Defense Summary</h3>
    <div style="background: #000; padding: 15px; border-left: 4px solid var(--primary); font-family: monospace;">
    <?php
    if (file_exists($summaryFile)) {
        $rawContent = file_get_contents($summaryFile);
        $parts = explode('------------------------------------------', $rawContent);
        $top10Section = $parts[1] ?? ''; 

        preg_match_all('/^\s+(\d+)\s([A-Z]{2})$/m', $top10Section, $matches);
        
        if (!empty($matches[0])) {
            $counts = $matches[1]; $labels = $matches[2]; $maxCount = max($counts);

            foreach ($counts as $i => $hit) {
                $cc = $labels[$i];
                $percent = ($hit / $maxCount) * 100;
                echo "<div style='margin-bottom:8px;'>
                        <div style='display:flex; justify-content:space-between; font-size:0.9em; margin-bottom:3px;'>
                            <span><strong>$cc</strong></span>
                            <span style='color:var(--success);'>$hit hits</span>
                        </div>
                        <div class='bar-container'>
                            <div class='bar-fill' style='width:{$percent}%'></div>
                        </div>
                      </div>";
            } 
            echo "<pre style='color:#777; margin-top:15px; font-size:0.8em;'>" . ($parts[2] ?? '') . "</pre>"; 
        }
    }
    ?>
    </div>
</div>
<script>
// Table Sorting Logic
function sortTableByColumn(table, column, asc = true) {
    const dirModifier = asc ? 1 : -1;
    const tBody = table.tBodies[0];
    const rows = Array.from(tBody.querySelectorAll("tr"));

    const sortedRows = rows.sort((a, b) => {
        const aText = a.querySelector(`td:nth-child(${column + 1})`).textContent.trim();
        const bText = b.querySelector(`td:nth-child(${column + 1})`).textContent.trim();
        const aNo = parseFloat(aText), bNo = parseFloat(bText);
        return (!isNaN(aNo) && !isNaN(bNo)) ? (aNo - bNo) * dirModifier : aText.localeCompare(bText) * dirModifier;
    });

    while (tBody.firstChild) tBody.removeChild(tBody.firstChild);
    tBody.append(...sortedRows);

    table.querySelectorAll("th").forEach(th => th.classList.remove("th-sort-asc", "th-sort-desc"));
    table.querySelector(`th:nth-child(${column + 1})`).classList.toggle("th-sort-asc", asc);
    table.querySelector(`th:nth-child(${column + 1})`).classList.toggle("th-sort-desc", !asc);
}

document.querySelectorAll("table th").forEach(headerCell => {
    headerCell.addEventListener("click", () => {
        const table = headerCell.closest('table');
        const index = Array.from(headerCell.parentElement.children).indexOf(headerCell);
        const isAsc = headerCell.classList.contains("th-sort-asc");
        sortTableByColumn(table, index, !isAsc);
    });
});

// Filter Logic
function applyFilters() {
    const typeVal = document.getElementById('filterType').value;
    const reasonVal = document.getElementById('filterReason').value.toLowerCase();
    const rows = document.querySelectorAll('.log-row');

    rows.forEach(row => {
        const typeMatch = typeVal === "" || row.getAttribute('data-type') === typeVal;
        const reasonMatch = row.getAttribute('data-reason').includes(reasonVal);
        const evidenceMatch = row.getAttribute('data-evidence').includes(reasonVal);
        
        if (typeMatch && reasonMatch || typeMatch && evidenceMatch) {
            row.classList.remove('row-hidden');
        } else {
            row.classList.add('row-hidden');
        }
    });
}
// Unban & Countdown Logic
let countdownActive = false;
let pendingIPs = [];

function triggerUnbanCountdown(ip) {
    if (!pendingIPs.includes(ip)) pendingIPs.push(ip);
    
    const container = document.getElementById('sync-status');
    const timerElement = document.getElementById('countdown');
    const ipElement = document.getElementById('sync-ip');

    ipElement.innerText = pendingIPs.length <= 3 ? pendingIPs.join(', ') : pendingIPs.slice(0, 2).join(', ') + " (+" + (pendingIPs.length - 2) + " more)";
    container.style.display = 'block';

    if (countdownActive) return;
    countdownActive = true;

    const target = new Date();
    target.setMinutes((Math.floor(target.getMinutes() / 5) + 1) * 5, 0, 0);

    const interval = setInterval(() => {
        const diffSec = Math.floor((target.getTime() - new Date().getTime()) / 1000);
        if (diffSec <= 0) {
            clearInterval(interval);
            ipElement.innerText = "SYNCING...";
            setTimeout(() => window.location.reload(true), 4000);
            return;
        }
        timerElement.innerText = `${Math.floor(diffSec / 60).toString().padStart(2, '0')}:${(diffSec % 60).toString().padStart(2, '0')}`;
    }, 1000);
}

function unbanIP(ip, btn) {
    if (!confirm(`Queue ${btn.innerText} for ${ip}?`)) return;
    
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=${btn.innerText}&ip=${encodeURIComponent(ip)}` // Added encoding for safety
    })
    .then(response => {
        // Check if PHP actually returned a success code (e.g., 200 OK)
        if (!response.ok) {
            throw new Error('Server rejected the IP format.');
        }
        return response.text(); // or response.json() if your PHP returns JSON
    })
    .then(() => {
        // Only run UI updates if the request was successful
        triggerUnbanCountdown(ip);
        btn.disabled = true;
        btn.innerText = "Queued";
        btn.closest('li').style.opacity = "0.5";
    })
    .catch(err => {
        alert("Failed to queue: " + err.message);
        console.error(err);
    });
}

// Global DOM Ready
document.addEventListener("DOMContentLoaded", function() {
    // Search IP functionality
    document.getElementById('ipsearch').addEventListener('input', function(e) {
        let val = e.target.value.trim();
        let resDiv = document.getElementById('results');
        if (val.length < 3) { resDiv.innerHTML = ""; return; }

        fetch(`${window.location.pathname}?action=search&ip=${val}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'found') {
                let html = `<p style="color:orange;">Found ${data.count} bans:</p><ul style="list-style:none; padding:0;">`;
                data.data.forEach(item => {
                    html += `<li style="margin-bottom: 8px; background: #222; padding: 10px; border-radius: 4px; border-left: 4px solid var(--danger);">
                        <code style="color: var(--success);">${item.ip}</code> <small>[${item.reason}]</small><small>[${item.timeout}]</small>
                        <button onclick="unbanIP('${item.ip}', this)" style="float:right; background:var(--danger); color:#fff; border:none; padding:4px 8px; cursor:pointer;">Unban</button>
                        <button onclick="unbanIP('${item.ip}', this)" style="float:right; margin-right:5px; background:var(--danger); color:#fff; border:none; padding:4px 8px; cursor:pointer;">Permban</button>
                        <div style="clear:both;"></div></li>`;
                });
                resDiv.innerHTML = html + "</ul>";
            } else resDiv.innerHTML = "<p style='color:#777;'>No matching bans.</p>";
        });
    });

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const cell = entry.target;
            const ip = cell.getAttribute('data-ip');
            observer.unobserve(cell);
            fetch(`${window.location.pathname}?action=lookup&ip=${ip}`)
                .then(res => res.text())
                .then(host => {
                    cell.innerHTML = (host.trim() === ip) ? 'no-rdns' : host;
                    cell.classList.add('resolved');
                });
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.rdns-pending').forEach(span => observer.observe(span));

   const activeBans = Array.from(document.querySelectorAll('.rdns-pending'))
                        .map(el => el.getAttribute('data-ip'));
document.querySelectorAll('#top-10-container a').forEach(a => {
    const ip = a.innerText.trim();
    if (activeBans.includes(ip)) {
        a.classList.add('neutralized');
    }
});
}); // end Dom
</script>
</body>
</html>
