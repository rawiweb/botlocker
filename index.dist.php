<?php
// These will be replaced by the installer
$system_timezone = 'INSERT_TIMEZONE_HERE';
date_default_timezone_set($system_timezone);

// File Paths
$basePath           = 'INSERT_DATA_DIR_HERE'; 
$logPath            = '/var/log/botlocker/botlocker.log'; // Usually root-owned
$summaryFile        = $basePath . '/botnet_report.txt';
$reportFile         = $basePath . '/bot_report.txt';
$unbannFile         = $basePath . '/botlocker_unban_request.txt';
$current_bans_file  = $basePath . '/botlocker_current_bans.txt';
$shameFile          = $basePath . '/botlocker.shame';
$lockFile           = $basePath . '/system_off.lock';
$confDir            = $basePath . '/conf.d';
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
            $timeout_display .= '<br><small>('.$ip_data['packets'].' pkts | '.$ip_data['bytes'].' bytes)</small>';
        }
    } else {
        $age = time() - $log_timestamp;
        $timeout_display = ($age <= 305) ? '<span>Pending...</span>' : '<span>Released</span>';
    }
    $timeout_display = $parts[1]=="RELEASE" ? '<span>Released</span>' : $timeout_display;
    $safeId = 'log_' . md5($log_time_str . $ip); 
$web_safe_log = htmlspecialchars(mb_convert_encoding(urldecode($parts[5]), 'UTF-8', 'UTF-8'));
return sprintf(
    '<tr id="%1$s" class="log-row" data-type="%2$s">
        <td>%3$s</td>
        <td data-time="%4$s">%5$s</td>
        <td class="%6$s"><strong>%7$s</strong></td>
        <td>%8$s</td>
        <td class="ip-info" data-ip="%9$s">
            <span class="iptab">%10$s</span>
            <span class="rdns-pending">...</span>
        </td>
        <td>%11$s</td>
        <td>%12$s</td>
    </tr>',
    $safeId,                      // 1
    $parts[1],                    // 2
    $parts[0],                    // 3
    $log_timestamp,               // 4
    $timeout_display,             // 5
    strtolower($parts[1]),        // 6
    $parts[1],                    // 7
    $parts[2],                    // 8
    $ip,                          // 9 (data-ip)
    $parts[3],                    // 10 (iptab display)
    ($parts[4] ?? ''),            // 11 (Country/Info)
    urldecode($web_safe_log ?? '')    // 12 (Path/Request)
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
if (isset($_GET["shame"])){
    $shame = file($shameFile,  FILE_IGNORE_NEW_LINES);
   foreach($shame as $line) echo $line. PHP_EOL;
   exit;
}
if (isset($_GET["check_dry_run"])) {
    $resultFile = $basePath . '/.last_result';
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        unlink($resultFile); // Clear it for next time
        echo json_encode(['ready' => true, 'result' => $content]);
    } else {
        echo json_encode(['ready' => false]);
    }
    exit;
}
if (isset($_GET["save_configs"])) {
    $input = json_decode(file_get_contents('php://input'), true);
    $errors = [];
    $savedFiles = [];

    if (!$input || !is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'No changes received.']);
        exit;
    }

    try {
    $validData = [];
    
    // --- PHASE 1: VALIDATION ---
       foreach ($input as $filename => $content) {
    $safe_name = basename($filename);
    $tmpFile = tempnam(sys_get_temp_dir(), 'botlock_');
    file_put_contents($tmpFile, $content);

    if ($safe_name === "botlocker.conf") {
        // --- 1. BASH STRUCTURAL CHECK ---
        $cmd = "bash -n " . escapeshellarg($tmpFile) . " 2>&1";
        exec($cmd, $syntaxOutput, $returnVar);
        
        if ($returnVar !== 0) {
            $errors[] = "[$safe_name] Structural Error: " . implode(" ", $syntaxOutput);
        }
        unlink($tmpFile); // Cleanup immediately after check
    } 
    else {
        // --- 2. REGEX PATTERN CHECK (for conf.d files) ---
        unlink($tmpFile); // We don't need the file for the line-by-line check
        
        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            $line = trim($line);
            
            // Skip comments, empty lines, and [sections]
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '[') === 0) continue;

            // Use grep -E for "Extended" Regex (which supports your parentheses)
            // We use -E because that's what your scanner uses
            $cmd = "echo 'test' | grep -EiE " . escapeshellarg($line) . " > /dev/null 2>&1";
            exec($cmd, $output, $return_var);

            if ($return_var === 2) {
                $errors[] = "[$safe_name] Line " . ($index + 1) . ": Invalid Regex -> $line";
            }

            // Check for unescaped trailing backslashes
            if (substr($line, -1) === '\\' && substr($line, -2) !== '\\\\') {
                $errors[] = "[$safe_name] Line " . ($index + 1) . ": Trailing backslash error.";
            }
        }
    }

    $validData[$safe_name] = $content;
}

        // --- PHASE 2: ATOMIC WRITE ---
        if (empty($errors)) {
            foreach ($validData as $safe_name => $content) {
                $target = $confDir . '/' . $safe_name;
                if (file_put_contents($target, $content) === false) {
                    throw new Exception("Permission denied writing to $safe_name");
                }
                $savedFiles[] = $safe_name;
            }

            // Only trigger the background worker if EVERYTHING saved
            touch($basePath . '/' . "config.copy.now");
            echo json_encode([
                'success' => true, 
                'message' => "Successfully updated: " . implode(', ', $savedFiles)
            ]);
        } else {
            // Return all errors at once, no files were changed on disk
            echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    if ($_POST['action'] == 'Release' || $_POST['action'] == 'Permban' || stristr($_POST['action'], 'Block')) {
        
        $action = $_POST['action'];
        $prefix = ($action == 'Release') ? "ubn " : "prm ";
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
    
    $host = shell_exec("host -W 2 $ip | awk '/pointer/ {print $5}' | sed 's/\.$//'");
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
    if($jdata = json_encode([
        'html' => $rows_html,
        'count' => $count,
        'jailSize' => count($ban_timers),
        'status' => 'success'
    ], JSON_INVALID_UTF8_SUBSTITUTE)){
        echo $jdata;
    }
    else echo json_last_error_msg();
    exit;
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Botlocker Node Stats</title>
    <link rel="stylesheet" href="./assets/style.css">
</head>
<body>

<?php
//CONFEDITOR
$files = array_diff(scandir($confDir), array('..', '.')); // Get all .conf files
$content="";
?>
<div id="terminal-container">
    <div class="gnome-wrapper">
        <div class="tabs-sidebar">
            <?php 
            $first = true;
            foreach ($files as $file): 
                if (strpos($file, '.conf') === false) continue;
                $id = str_replace('.', '_', $file); 
            ?>
                <button class="tab-link <?= $first ? 'active' : '' ?>" 
                        onclick="openFile(event, '<?= $id ?>')">
                    <?= $file ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
        <div class="editor-main">
            <?php 
            $first = true;
            foreach ($files as $file): 
                if (strpos($file, '.conf') === false) continue;
                $id = str_replace('.', '_', $file); 
            ?>
                <div id="<?= $id ?>" class="tab-content <?= $first ? 'active' : '' ?>" 
                     style="display: <?= $first ? 'flex' : 'none' ?>;">
                    <div class="terminal-header">
                        <span class="dots">● ● ●</span>
                        <span class="title">botlocker@server: ~/<?= $file ?></span>
                    </div>
                    <textarea class="terminal-input gnome-terminal-input" 
                              data-filename="<?= $file ?>" 
                              data-original="<?php echo htmlspecialchars($content); ?>"
                              spellcheck="false"><?= htmlspecialchars(file_get_contents($confDir .'/'. $file)) ?></textarea>
                </div>
            <?php $first = false; endforeach; ?>
            <div class="terminal-footer">
                <button class="exit-btn" onclick="document.getElementById('terminal-container').style.display='none'">Cancel</button>
                <button class="btn-save" onclick="saveAndExit()">[ Save Config and Exit ]</button>
            </div>
        </div>
    </div>
</div>
<?php // end Confeditor ?>
    <div class="menu stat-card">
    <a id="shame" style="cursor:pointer">[ hall of shame ]</a>
    <a onclick="showConfig()" style="cursor:pointer;">[ Edit Botlocker Config ]</a>
    </div>
<div id="sync-status">
    <span class="sync-msg">Pending Releases</span>
    <div id="sync-ip"></div>
    <span class="sync-msg">Next Sync in</span>
    <span id="countdown" class="sync-timer">--:--</span>
</div>
    
    <div id="package-filter" class="window"><h3>package-filter</h3>
       <pre style="white-space: pre-wrap;"></pre>
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
             <span>Search local Jail:</span> <input type="text" id="ipsearch" placeholder="Type or click IP Address...">
          <span id="jail-size">Results:
                <?php 
                if (file_exists($current_bans_file)) {
                    echo count(file($current_bans_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                } else echo "0";
                ?>
    </span>
         </div>
         <div id="results"></div>
         <div class="filter-bar">
            <button id="bulkReleaseBtn" onclick="bulkUnbanDisplayed()" style="display:none;">Bulk Release </button>
            <span>Search / Filter logs</span> <span id="log-count"></span>
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
                    echo $reportFile;
                    if (file_exists($reportFile)) {
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
<script src="./assets/script.js?v=1.0.5"></script>  
</body>
</html>
