<?php
// These will be replaced by the installer
$authorized_user = 'INSERT_USERNAME_HERE';
$authorized_hash = 'INSERT_HASH_HERE';
$system_timezone = 'INSERT_TIMEZONE_HERE';
date_default_timezone_set($system_timezone);
session_start();
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
$lockFile = dirname(__DIR__).'/system_off.lock';
$shameFile = "../botlocker.shame";

$timeout_display="";
$ip="";
$is_system_off = file_exists($lockFile);
//functions

function datediff($ts){
    if (!$ts || $ts == 0) return "Permanent";
    $raw_seconds = $ts ?? 0;
    $now = new DateTime('@0');
    $expiry = new DateTime("@$raw_seconds");
    $diff = $now->diff($expiry);
    return $diff->format('%ad %hh %im');
}

function formatLogRow($line, $ban_timers) {
    $parts = array_map('trim', explode('|', $line));
    if(count($parts) < 4) return '';
    
    $log_time_str = $parts[0];
    $log_timestamp = strtotime($log_time_str);
    
    // Extract IP safely from "CC 1.2.3.4"
    $ipa = explode(' ', $parts[3]);
    $ip = trim(end($ipa)); 

    $ip_data = $ban_timers[$ip] ?? null;
    $timeout_display = '';
    
    if ($ip_data !== null) {
        $raw_val = $ip_data['timeout'] ?? 0;
        if ($raw_val == 0) {
            $timeout_display = '<span style="color:var(--success);">PERMANENT</span>';
        } else {
            $timeout_display = datediff((int)$raw_val);
        }
        // Add traffic stats
        if (($ip_data['packets'] ?? 0) > 0) {
            $timeout_display .= '<br><small>('.$ip_data['packets'].' pkts)</small>';
        }
    } else {
        $age = time() - $log_timestamp;
        $timeout_display = ($age <= 305) ? '<span>Pending...</span>' : '<span>Released</span>';
    }

    $safeId = 'log_' . md5($log_time_str . $ip); 

    return sprintf(
        '<tr id="%s" class="log-row" data-type="%s">
            <td>%s</td>
            <td data-time="%s">%s</td>
            <td class="%s"><strong>%s</strong></td>
            <td>%s</td>
            <td class="ip-info" onclick="copyToSearch(\'%s\')">
                <span class="iptab">%s</span>
                <span data-ip="%s" class="rdns-pending">...</span>
            </td>
            <td>%s</td>
            <td>%s</td>
        </tr>',
        $safeId, $parts[1], $parts[0], $log_timestamp, $timeout_display, 
        strtolower($parts[1]), $parts[1], $parts[2], $ip, $parts[3], $ip, 
        ($parts[4] ?? ''), urldecode($parts[5] ?? '')
    );
}

if (isset($_POST['disabled'])) {
    if ($_POST['disabled'] == '1') {
        // Create the file to "Turn Off" the system
        touch($lockFile);
        echo json_encode(["status" => "disabled"]);
    } else {
        // Remove the file to "Turn On" the system
        if (file_exists($lockFile)) unlink($lockFile);
        echo json_encode(["status" => "enabled"]);
    }
    exit;
}

//arrays for processing

$ban_timers = [];
if (file_exists($current_bans_file)) {
    $ban_lines = file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($ban_lines as $line) {
        $p = explode(' ', trim($line));
        if (empty($p[0])) continue;
        $ip_key = $p[0];
        $meta = ['timeout' => 0, 'packets' => 0, 'bytes' => 0];
        for ($i = 1; $i < count($p); $i += 2) {
            if (isset($p[$i+1]) && array_key_exists($p[$i], $meta)) {
                $meta[$p[$i]] = $p[$i+1];
            }
        }
        $ban_timers[$ip_key] = $meta;
    }
}
                $cmd = "cat " . escapeshellarg($logPath). " | tail -n 1000 | tac";
                 exec($cmd, $displayItems);
                 $uniqueTypes = [];
                  foreach ($displayItems as $line): 
                    $parts = explode('|', $line);
                        if (isset($parts[1])) {
                            $type = trim($parts[1]);
                            if (!in_array($type, $uniqueTypes)) {
                                $uniqueTypes[] = $type;
                            }
                        }
                        
                  endforeach; 
                  sort($uniqueTypes);              

// * AJAX ACTIONS
 
// 1. Unban / Permban Request
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'Unban' || $_POST['action'] == 'Permban' || stristr($_POST['action'], 'Block')) {
        
        $action = $_POST['action'];
        $prefix = ($action == 'Unban') ? "ubn " : "prm ";
        $ips = $_POST['ip'];

        // Convert single IP string to an array so the logic is unified
        if (!is_array($ips)) {
            $ips = [$ips];
        }

        $processed = 0;
        $output = "";

        foreach ($ips as $raw_ip) {
            $ip = trim($raw_ip);
            // Validate IPv4, IPv6, or CIDR notation
            if (filter_var($ip, FILTER_VALIDATE_IP) || preg_match('/^[0-9a-fA-F:.]+\/[0-9]+$/', $ip)) {
                $output .= $prefix . $ip . PHP_EOL;
                $processed++;
            }
        }

        if ($processed > 0) {
            file_put_contents($unbannFile, $output, FILE_APPEND | LOCK_EX);
            echo json_encode(["status" => "success", "count" => $processed]);
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "No valid IPs found"]);
        }
        exit;
    }
}

#Search request
if (isset($_GET['action']) && $_GET['action'] == 'search' && isset($_GET['ip'])) {
    $search_term = strtolower(trim($_GET['ip']));
    $results = [];

    // $ip_data is now our nested array [timeout => ..., packets => ...]
    foreach ($ban_timers as $ip_in_set => $ip_data) {
        
        if (strpos(strtolower($ip_in_set), $search_term) !== false) {
            
            $reason = "Unknown activity";
            if (file_exists($logPath)) {
                $escaped_ip = escapeshellarg($ip_in_set);
                $last_log = shell_exec("grep -F $escaped_ip " . escapeshellarg($logPath) . " | tail -n 1");
                
                if ($last_log) {
                    $p = explode('|', $last_log);
                    $reason = ($p[4] ?? "Unknown") . " (" . (trim($p[5] ?? "No details")) . ")";
                }
            }

            // --- FIX FOR NESTED ARRAY & TIMEOUT ---
            $raw_timeout = $ip_data['timeout'] ?? null;
            
            if ($raw_timeout === null || $raw_timeout === "0" || $raw_timeout === 0) {
                $display_time = "Permanent";
            } else {
                // Convert remaining seconds to future timestamp
                $display_time = datediff((int)$raw_timeout);
            }
            // --------------------------------------

            $results[] = [
                'ip'      => $ip_in_set,
                'timeout' => $display_time,
                'reason'  => $reason,
                'packets' => $ip_data['packets'] ?? 0,
                'bytes'   => $ip_data['bytes'] ?? 0
            ];

            //if (count($results) >= 10) break;
        }
    }
    echo json_encode(['status' => !empty($results) ? 'found' : 'clear', 'count' => count($results),'data' => $results]);
    exit;
}

// 3. rDNS Lookup
if (isset($_GET['action']) && $_GET['action'] == 'lookup' && isset($_GET['ip'])) {
    $ip = $_GET['ip'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo "Invalid IP";
        exit;
    }
    session_write_close();
    
    $host = shell_exec("host -W 2 $ip 8.8.8.8 | awk '/pointer/ {print $5}' | sed 's/\.$//'");
    echo (!empty($host)) ? htmlspecialchars(trim($host)) : "no-rdns";
    //echo (filter_var($ip, FILTER_VALIDATE_IP)) ? htmlspecialchars(gethostbyaddr($ip)) : "Invalid IP";
    exit;
}
// 4. Filtered Log Request (AJAX)
if (isset($_GET['action']) && $_GET['action'] == 'filter_log') {
    $type = $_GET['type'] ?? '';
    $reason = $_GET['reason'] ?? '';
    $limit = $_GET['limit'] ?? 1000;
    $rows_html = "";
    $count = 0;
    $cmd = "cat " . escapeshellarg($logPath);
    if (!empty($type)) {
        $cmd .= " | grep '|" . escapeshellarg($type) . "|'";
    }
    if (!empty($reason)) {
        $cmd .= " | grep -i " . escapeshellarg($reason);
    }
    
    $cmd .= " | tac"; //| tail -n 1000 
    exec($cmd, $filteredLines);
    $count = count($filteredLines);
    for ($i = 0; $i < $count && $i < $limit; $i++) {
    $rows_html .= formatLogRow($filteredLines[$i], $ban_timers);
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $rows_html,
        'count' => $count,
        'jailSize' => count($ban_timers),
        'status' => 'success'
    ]);
    exit;
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Botlocker Node Stats</title>
    <style>
        :root { --primary: #3498db; --danger: #e74c3c; --success: #2ecc71; --warning: #f1c40f; --safe:green; --bg: #1a1a1a; --card: #333; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); color: #eee; padding: 20px; line-height: 1.4; }
        h1, h3 { margin-bottom: 10px; }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .stat-card { background: var(--card); padding:15px; border-radius: 5px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .stat-card h3 {display:inline-block}
        #ipsearch { background: #000; color: var(--success); border: 1px solid #444; padding: 5px; width: 250px; border-radius: 4px; }
        #results { margin-top: 50px; position: absolute;z-index: 40; background: var(--card);border-radius: 5px;box-shadow: 0 4px 6px rgba(0,0,0,0.3);padding:1em;display:none}
        #results ul{margin: 0;padding: 0 5px 10px;}
        #results li{background: #222; padding: 10px;border-radius: 4px;border-left: 4px solid var(--danger);display: flex;margin-bottom:2px;width:500px}
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 5px; overflow: hidden; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #444;max-width: 300px }
        th { background: #444; cursor: pointer; transition: 0.2s; }
        th:hover { background: #555; }
        
        .web { color: var(--primary); }
        .mail { color: #e67e22; }
        .ssh { color: var(--success); }
        
        .iptab { display:block;}
        .rdns-pending { font-weight: 300;}
        .ip-info:hover{background-color:#2c3e50; cursor:pointer}
        .report-table-wrapper { overflow-x: auto; }
        .report-table td:last-child, #log-container td:last-child { max-width: 500px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: help; }
        .report-table td:last-child:hover,#log-container td:last-child:hover { white-space: normal; background: #2c3e50; position: relative; z-index: 10; word-break: break-all;}

        /* Sync Status Box */
        #sync-status { position: fixed; top: 20px; right: 20px; background: var(--card); padding: 15px; border-radius: 8px; display: none; box-shadow: 0 0 20px rgba(0,0,0,0.5); border: 1px solid #444; min-width: 200px; z-index: 100; }
        .sync-msg { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #aaa; display: block; }
        #sync-ip { color: var(--success); font-size: 0.9em; margin: 5px 0;  }
        .sync-timer { color: #eee;}

        .neutralized:after { content:' 🚫'; }
        .th-sort-asc::after { content: " ▲"; }
        .th-sort-desc::after { content: " ▼"; }
        
        /* Progress Bars */
        .bar-container { background:#222; height:8px; border-radius:4px; margin-top: 4px; }
        .bar-fill { height:100%; background: var(--success); box-shadow: 0 0 10px var(--success); border-radius:4px; }
        
        /* Scroll Container Logic */
    .scroll-container {  background: var(--card);border-radius: 5px;height: 500px;overflow-y: auto;  border: 1px solid #444;        position: relative;}
    table { width: 100%; border-collapse: collapse; }
    thead th { position: sticky; top: 0;  background: #444;  z-index: 20;    box-shadow: 0 2px 2px rgba(0,0,0,0.5);}
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #444; }
    .log-row{font-size: .8em}
    /* Filter Bar Styling */
    .filter-bar {  margin-bottom: 10px; display:flex; gap: 10px; float:inline-end; background: #222;   padding: 10px;    border-radius: 5px; }
    .filter-jail {  margin-bottom: 10px; display: flex; gap: 10px; float:inline-start; background: #222;   padding: 10px;    border-radius: 5px; }
    .filter-bar select, .filter-bar input { background: #333; color: #fff; border: 1px solid #555; padding: 5px; border-radius: 3px;}
    .row-hidden { display: none; }
    .search-item-info {display: inline-block;width: 100%;vertical-align: middle;
    word-break: break-word; /* Traditional wrap */overflow-wrap: anywhere;color: #bbb;font-size: 0.85em;line-height: 1.2;}
    .search-item-info code {font-size: 1.5em;margin-right: 8px;}
    #bulkReleaseBtn {background-color: rgb(217, 83, 79);color: white;border: medium;padding: 5px 10px;border-radius: 4px;cursor: pointer;}
        /* Add a subtle pulse to extremely high hit counts */
    .high-intensity {color: var(--danger);text-shadow: 0 0 5px rgba(231, 76, 60, 0.5);animation: pulse 2s infinite;}
    #package-filter{margin-top: 0px;position: absolute;z-index: 40; background: var(--card);border-radius: 5px;box-shadow: 0 4px 6px rgba(0,0,0,0.3);padding:1em;display:none}
    #package-filter pre{font-family: monospace; color: var(--success);background-color: var(--bg);padding:10px}
    #package-filter h3:before{content: '✕';cursor:pointer;margin: 5px 10px 0 0;color: gray;}
    @keyframes pulse {0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }}
    @keyframes slideInGlow {0% {background-color: #fffde7;transform: translateX(-5px);opacity: 0;}
    100% {background-color: transparent;transform: translateX(0);opacity: 1;}}
    .new-row-animate {animation: slideInGlow 0.6s ease-out;}
    #switch{color:grey;float:right;text-align: right}
    #switch > * {margin-top:2px}
    .switch { position: relative; display: inline-block; width: 50px; height: 25px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: gray; transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 4px; bottom: 3px; background-color: lightgray; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #2196F3; }
    input:checked + .slider:before { transform: translateX(25px); }
    </style>
</head>
<body>

<div id="sync-status">
    <span class="sync-msg">Pending Releases</span>
    <div id="sync-ip"></div>
    <span class="sync-msg">Next Sync in</span>
    <span id="countdown" class="sync-timer">--:--</span>
</div>
    <a style="cursor:pointer" onclick="document.getElementById('package-filter').style = 'display:block'; document.getElementById('results').style = 'display:none'">hall of shame</a>
    <div id="package-filter"><h3>package-filter</h3>
   <pre style="white-space: pre-wrap;"><?php $shame = file($shameFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
   foreach($shame as $line) echo $line. PHP_EOL;
   ?>
    </pre>
    </div>
    
<div id="switch">
    <div class="system-time"><?= date("Y-m-d H:i:s") ?></div>
<label class="switch">
  <input type="checkbox" id="cronToggle" onclick="toggleSystem()"<?php echo $is_system_off ? '' : 'checked'; ?>>
  <span class="slider round"></span>
</label>
<span id="statusLabel"><?php echo $is_system_off ? "System paused" : "System active"; ?></span>
   <div id="refresh-timer">Auto-refresh in: 01:00</div>
</div>
    
<div class="stat-header">
    <h1>🛡️ Botlocker Node Stats <small style="font-size: 0.4em; color: #666;">(Dev v1.1)</small></h1>
    <div style="text-align: right;">
    </div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center">
     <div class="stat-card" style="flex: 1;">
         <div class="filter-jail">
          Search Jail: <input type="text" id="ipsearch" placeholder="Type or click IP Address...">
         </div>
         <div id="results"></div>
         <div class="filter-bar">
            <button id="bulkReleaseBtn" onclick="bulkUnbanDisplayed()" style="display:none;">Bulk Release </button>
            <span id="log-count" style="margin-top: 3px;"></span>
            <input type="text" id="filterType" list="typeList" placeholder="Filter Type..." class="filter-input">
               <datalist id="typeList">
                    <?php foreach($uniqueTypes as $type): ?>
                         <option value="<?php echo htmlspecialchars($type); ?>">
                     <?php endforeach; ?>
               </datalist>
             <input type="text" id="filterReason" placeholder="Date, count, ip, reason evidence ...">
             <button onclick="clearFilters()">X</button>
          </div>
    </div>
</div>
<div id="ajax-refresh-container">
  <div class="stat-card">
    <h3>🕒 Recent Activity</h3>
    <span id="jail-size" stlye="display:inline-block">Current Jail Size:
                <?php 
                if (file_exists($current_bans_file)) {
                    echo count(file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                } else echo "0";
                ?>
    </span>
    <div class="scroll-container">
      <table id="log-container">
         <thead>
           <tr>
              <th>Timestamp</th><th>Release in</th><th>Type</th><th>Count</th><th>Identity</th><th>Reason</th><th>Evidence</th>
           </tr>
         </thead>
         <tbody id="log-table-body">
           <?php
              foreach ($displayItems as $line): 
                echo formatLogRow($line, $ban_timers);
              endforeach; 
            ?>
         </tbody>
      </table>
    </div>
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
                            <td style="color: var(--danger); "><?= $cols[0] ?> <small>(<?= $cols[4] ?> sites)</small></td>
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
function copyToSearch(ip) {
    const searchInput = document.getElementById('ipsearch');
    const subnet = ip.split('.').slice(0, 3).join('.') + '.';
    searchInput.value = subnet;
    handleIpSearch(subnet);
    searchInput.scrollIntoView({ behavior: 'smooth' });
    
}
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
// Filter Logic
let filterTimeout;
function applyFilters() {
 const url = new URL(window.location);
 const typeVal = document.getElementById('filterType').value;
        const reasonVal = document.getElementById('filterReason').value;
        const tbody = document.querySelector('#log-container tbody');

url.searchParams.set('type', typeVal);
url.searchParams.set('reason', reasonVal);
//window.history.pushState({}, '', url);
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        
        tbody.style.opacity = "0.5";
        fetch(`${window.location.pathname}?action=filter_log&type=${typeVal}&reason=${reasonVal}`)
            .then(res => res.json())
            .then(data => {
    tbody.innerHTML = data.html;
    tbody.style.opacity = "1";
    
    // Toggle the Bulk Release Button
    const bulkBtn = document.getElementById('bulkReleaseBtn');
    if (reasonVal.length > 2) { // Show if reason is typed (e.g., "Mozilla")
        bulkBtn.style.display = 'inline-block';
        bulkBtn.innerText = `Release All: ${reasonVal}`;
    } else {
        bulkBtn.style.display = 'none';
    }
    const counterDisplay = document.getElementById('log-count');
    if (counterDisplay) {
        pref='';
        if(data.count>=1000) pref='1000 of ';
        counterDisplay.innerText = `${pref}${data.count} results`;
    }

    document.querySelectorAll('.rdns-pending').forEach(span => observer.observe(span));
    markNeutralized();
});
    }, 300); // Wait 300ms after the last keypress
}
function clearFilters(){
  const type = document.getElementById('filterType');
  const reason = document.getElementById('filterReason');
  type.value = '';
  reason.value = '';
  const event = new Event('input', { bubbles: true });
  type.dispatchEvent(event);
  reason.dispatchEvent(event);
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
        body: `action=${encodeURIComponent(btn.innerText)}&ip=${encodeURIComponent(ip)}`
    })
    .then(response => {
        if (!response.ok) throw new Error('Server rejected the IP format.');
        return response.text();
    })
    .then(() => {
        triggerUnbanCountdown(ip);
        btn.disabled = true;
        btn.innerText = "Queued";
        
        // THE FIX: Check if btn.closest('li') exists before touching .style
        const listItem = btn.closest('li');
        if (listItem) {
            listItem.style.opacity = "0.5";
        } else {
            // If it's the Master Button (not in an <li>), dim the parent div instead
            const parentDiv = btn.closest('div');
            if (parentDiv) parentDiv.style.opacity = "0.5";
        }
    })
    .catch(err => {
        alert("Failed to queue: " + err.message);
        console.error(err);
    });
}
async function bulkUnbanDisplayed() {
    const rows = document.querySelectorAll('#log-container tbody tr.log-row');
    const ips = [];

    rows.forEach(row => {
        // 1. Find the IP inside the .iptab span
        const ipSpan = row.querySelector('.iptab');
        if (!ipSpan) return;

        // Extract IP (Cleaning "NL 20.103.102.154" -> "20.103.102.154")
        const rawText = ipSpan.textContent.trim();
        const ipMatch = rawText.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/);
        const ip = ipMatch ? ipMatch[0] : null;

        // 2. Determine "Status" by looking at the row text
        // If the row contains "Released", we skip it.
        const rowText = row.textContent;
        const isReleased = rowText.includes('Released');

        if (ip && !isReleased) {
            ips.push(ip);
        }
    });

    if (ips.length === 0) {
        alert("No active bans found in this filtered view.");
        return;
    }

    if (!confirm(`Release ${ips.length} IPs matching your current filter?`)) return;

    const formData = new FormData();
    formData.append('action', 'Unban');
    ips.forEach(val => formData.append('ip[]', val));

    try {
        const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const json = await res.json();
        if (json.status === 'success') {
            alert(`${json.count} IPs queued for release.`);
            applyFilters(); 
        }
    } catch (e) {
        console.error("Bulk release failed", e);
    }
}
// 1. Declare variables at the top of the script
let refreshSeconds = 60; // 1 minutes
let observer; // Declare it here so it's accessible everywhere
const savedCache = localStorage.getItem('botlocker_rdns');
const rdnsCache = new Map(savedCache ? JSON.parse(savedCache) : []);
function saveToDisk() {
    localStorage.setItem('botlocker_rdns', JSON.stringify(Array.from(rdnsCache.entries())));
}
function initRDNSObserver() {
    observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const cell = entry.target;
                const ip = cell.getAttribute('data-ip');
                observer.unobserve(cell);
               
                if (rdnsCache.has(ip)) {
                    cell.innerHTML = 'C '+rdnsCache.get(ip);
                    
                    cell.classList.add('resolved');
                    return;
                }

                // Create a timeout promise
                const timeout = new Promise((_, reject) => 
                    setTimeout(() => reject(new Error('Timeout')), 3000)
                );
                Promise.race([
                    fetch(`${window.location.pathname}?action=lookup&ip=${ip}`).then(res => res.text()),
                    timeout
                ])
                .then(host => {
                    cell.innerHTML = (host.trim() === ip || host.trim() === "") ? 'no-rdns' : host;
            rdnsCache.set(ip, host);
                    cell.classList.add('resolved');
                    saveToDisk();
                })
                .catch(err => {
                    cell.innerHTML = '<span style="color: #666;">timeout</span>';
                    cell.classList.add('resolved');
                });
            }
        });
    }, { threshold: 0.1 });
}
function startRefreshTimer() {
    const timerDisplay = document.getElementById('refresh-timer');
    
    // Safety check: only run if the timer display exists
    if (!timerDisplay) return;

    setInterval(() => {
        refreshSeconds--;
        
        if (refreshSeconds <= 0) {
            refreshDashboard(); // Trigger the AJAX
            refreshSeconds = 60; // Reset to 1 mins
        }

        // Update the countdown display
        let m = Math.floor(refreshSeconds / 60).toString().padStart(2, '0');
        let s = (refreshSeconds % 60).toString().padStart(2, '0');
        timerDisplay.innerText = `Auto-refresh in: ${m}:${s}`;
    }, 1000);
}
function refreshDashboard() {
      const typeVal = document.getElementById('filterType')?.value || 'all';
    const reasonVal = document.getElementById('filterReason')?.value || 'all';
   if(typeVal!=='all' || reasonVal!== 'all') return;
       
    // 1. Fetch from your specific filter action
    fetch(window.location.pathname + '?action=filter_log&limit=100')
        .then(res => res.json())
        .then(data => {
                const logHtml = data.html;
                if (!logHtml) return;
                // 1. Parse the incoming HTML
                const tempTbody = document.createElement('tbody');
                tempTbody.innerHTML = logHtml;
                const newRows = Array.from(tempTbody.querySelectorAll('.log-row'));
                const targetTbody = document.getElementById('log-table-body');
                const currentTopId = targetTbody.firstElementChild ? targetTbody.firstElementChild.id : null;
                let rowsToAdd = [];
                let reachedOldData = false;

                // 3. One loop to rule them all
                newRows.forEach(fetchedRow => {
                    const rowId = fetchedRow.id;

                    // A. Direct Update: If row exists on screen, sync the status cell
                    const existingRow = document.getElementById(rowId);
                    if (existingRow) {
                        const statusCell = existingRow.cells[1];
                        if (statusCell && statusCell.textContent.includes("Pending")) {
                            statusCell.innerHTML = fetchedRow.cells[1].innerHTML;
                        }
                    }

                    // B. New Row Logic: Check if we've hit the "Handshake"
                    if (!reachedOldData && rowId === currentTopId) {
                        reachedOldData = true;
                    }

                    // C. If we haven't reached old data AND the row isn't already there, it's new
                    if (!reachedOldData && !existingRow) {
                        rowsToAdd.push(fetchedRow);
                        console.log('found new row');
                    }
                });

                // 4. Prepend New Rows
                if (rowsToAdd.length > 0) {
                    console.log(`Adding ${rowsToAdd.length} new entries.`);
                    rowsToAdd.reverse().forEach(row => {
                        row.classList.add('new-row-animate');
                        targetTbody.insertBefore(row, targetTbody.firstChild);
                        targetTbody.removeChild(targetTbody.lastElementChild);
                        // Re-bind rDNS observer
                        row.querySelectorAll('.rdns-pending').forEach(span => observer.observe(span));
                        setTimeout(() => row.classList.remove('new-row-animate'), 2000);
                    });
                    markNeutralized();
                }
                // 5. Global UI & Cleanup
                if (data.jailSize) {
                    const jailLabel = document.getElementById('jail-size');
                    if (jailLabel) jailLabel.innerText = `local jail ${data.jailSize}`;
                }
                tempTbody.innerHTML = ''; // Memory cleanup
            })
        .catch(err => console.error("JSON Fetch Error:", err));
}
// Extract your Neutralized logic so it can be called anytime
function markNeutralized() {
    const activeBans = Array.from(document.querySelectorAll('.rdns-pending'))
                            .map(el => el.getAttribute('data-ip'));
    document.querySelectorAll('#top-10-container a').forEach(a => {
        const ip = a.innerText.trim();
        if (activeBans.includes(ip)) {
            a.classList.add('neutralized');
        }
    });
}
// 1. The Global Search Engine
function handleIpSearch(val) {
    const resDiv = document.getElementById('results');
    if (!resDiv) return;
    if (val.length < 3 || val === '') { resDiv.innerHTML = ""; return; }
   
    resDiv.style = 'display:block';
    fetch(`${window.location.pathname}?action=search&ip=${encodeURIComponent(val)}`)
    .then(res => res.json())
    .then(data => {
        if (data.status === 'found') {
            let searchVal = val.trim();
            let parts = searchVal.split('.');
            let subnetRange = "";
                
            // Fix: We need at least 3 parts to build a valid /24
            if (parts.length === 4) {
                // Take exactly the first 3 segments and append .0/24
                subnetRange = parts.slice(0, 3).join('.') + ".0/24";
            }

            let subNetBtn = '';
            // Only show the Master Button if we have a valid range AND enough hits

            if(data.count >= 5 && subnetRange !== "" && subnetRange !== "Invalid Range"){
              
                subNetBtn = `
                <div style="background: rgba(255, 165, 0, 0.1); border: 1px solid var(--warning); padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: orange; font-weight: bold;">Cluster Found: ${data.count} IPs in ${subnetRange}</span>
                        <button onclick="unbanIP('${subnetRange}', this)" 
                                style="background: var(--warning); color: #000; border: none; padding: 8px 12px; cursor: pointer; font-weight: bold; border-radius: 3px;">
                            Block Entire /24 Subnet
                        </button>
                    </div>
                </div>`;
            }

            let html = `${subNetBtn}<ul style="list-style:none; padding:0;">`;
            
            data.data.forEach(item => {
                const safeReason = item.reason.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                html += `
                <li>
                    <div class="search-item-info">
                        <code style="font-size:.8em; color: var(--success);">${item.ip}</code><br>
                        <small style="color: #666; display: block; margin-top: 4px;">Expires: ${item.timeout}</small><br>
                        <small style="color: #666; display: block; margin-top: 4px;">Blocked: ${item.packets} packets, ${item.bytes} bytes</small>
                    </div>
                    <div style="white-space: nowrap;">
                        <button onclick="unbanIP('${item.ip}', this)" style="background:var(--safe); color:#fff; border:none; padding:4px 8px; cursor:pointer; margin-bottom: 2px; display: block; width: 100%;">Unban</button>
                        <button onclick="unbanIP('${item.ip}', this)" style="background:var(--danger); color:#fff; border:none; padding:4px 8px; cursor:pointer; display: block; width: 100%;">Permban</button>
                    </div>
                </li>`;
            });
            resDiv.innerHTML = html + "</ul>";
        } else {
            resDiv.innerHTML = "<p style='color:#777;'>No results in ipset. (yet)</p>";
        }
    });
    
}
// 2. The Trigger function (used by the Table Clicks)
function toggleSystem() {
    const isChecked = document.getElementById('cronToggle').checked;
    const statusLabel = document.getElementById('statusLabel');
    
    // Disable UI temporarily to prevent double-clicks
    document.getElementById('cronToggle').disabled = true;

    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'disabled=' + (isChecked ? '0' : '1')
    })
    .then(response => response.json())
    .then(data => {
        statusLabel.innerText = isChecked ? "System Active" : "System Paused";
        document.getElementById('cronToggle').disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        alert("Failed to update system state.");
    });
}
// Global DOM Ready
document.addEventListener("DOMContentLoaded", () =>  {
    startRefreshTimer();
    initRDNSObserver();
    
    document.querySelectorAll("table th").forEach(headerCell => {
    headerCell.addEventListener("click", () => {
        const table = headerCell.closest('table');
        const index = Array.from(headerCell.parentElement.children).indexOf(headerCell);
        const isAsc = headerCell.classList.contains("th-sort-asc");
        sortTableByColumn(table, index, !isAsc);
    });
});
    // Search IP functionality
 
    const filterType = document.getElementById('filterType');
    const closeBar = document.querySelector('#package-filter h3')
    closeBar.addEventListener('click',() => {closeBar.parentElement.style.display = 'none'});
       // Listener B: Handles manual typing (Delegation)
    document.addEventListener('input', (e) => {
        if (e.target && e.target.id === 'ipsearch') {
            handleIpSearch(e.target.value.trim());
        }
        if(e.target && e.target.id === 'filterReason'){
            applyFilters();
        }
        if (e.target && e.target.id === 'filterType') {
        applyFilters(); 
    }
    });
    document.addEventListener('focus', (e) => {
        if(e.target && e.target.id === 'filterType'){
            e.target.value = ''; 
            e.target.dispatchEvent(new Event('input'));
        }
            
    });
    document.addEventListener('click', (e) => {
        if(e.target && e.target.tagName === 'INPUT'){
            e.target.value = ''; 
   
            if(e.target.id === 'ipsearch'){
                handleIpSearch(e.target.value);
                document.getElementById('results').style = 'display:none';
            }
         }
    });
    document.addEventListener('change', (e) => {
        if(e.target && e.target.tagName === 'INPUT'){
            refreshDashboard();
        }
    });

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
