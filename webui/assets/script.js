//confeditor
function openFile(evt, fileName) {
    // 1. Get all tab content panes
    const tabcontents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < tabcontents.length; i++) {
        // Remove active class AND force display to none
        tabcontents[i].classList.remove("active");
        tabcontents[i].style.display = "none";
    }

    // 2. Deactivate all tab links
    const tablinks = document.getElementsByClassName("tab-link");
    for (let i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }

    // 3. Show the target window
    const target = document.getElementById(fileName);
    target.style.display = "flex"; // Use flex so the textarea expands
    target.classList.add("active");
    
    // 4. Highlight the button
    evt.currentTarget.classList.add("active");
}
function showConfig() {
    document.getElementById('terminal-container').style.display = 'flex';
}

// 2. Updated Save function to only send "Dirty" files
function saveAndExit(event) {
    const savebutton = event ? event.currentTarget : document.querySelector('.save-btn');
    const editors = document.querySelectorAll('.gnome-terminal-input');
    const payload = {};
    let hasChanges = false;

    if (savebutton) savebutton.innerText = '[ Saving ... ]';

    editors.forEach(editor => {
        if (editor.value !== editor.dataset.original) {
            payload[editor.getAttribute('data-filename')] = editor.value;
            hasChanges = true;
        }
    });

    if (!hasChanges) {
        if (savebutton) savebutton.innerText = '[ No changes ]';
        return;
    }

    // SINGLE FETCH: PHP does the validation and saving in one go
    fetch(`${window.location.pathname}?save_configs=1`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // SUCCESS: One flash of green, then reload
            if (savebutton) savebutton.innerText = '[ ✅ SUCCESS ]';
            setTimeout(() => location.reload(), 800);
        } else {
            // FAILURE: Immediate Rollback option
            const msg = "❌ ERROR: " + data.message + "\n\nPress OK to REVERT to original settings.";
            if (confirm(msg)) {
                revertToOriginal(); // Local UI revert
                if (savebutton) savebutton.innerText = '[ REVERTED ]';
            } else {
                if (savebutton) savebutton.innerText = '[ FIXED ERROR MANUALLY ]';
            }
        }
    })
    .catch(err => {
        alert("Network Error: " + err);
        if (savebutton) savebutton.innerText = '[ Save and close ]';
    });
}
function revertToOriginal() {
   const editors = document.querySelectorAll('.gnome-terminal-input');
    editors.forEach(editor => {
        editor.value = editor.dataset.original; // Reset the text locally
    });
    document.querySelectorAll('.tab-link').forEach(tab => {
        tab.classList.remove('dirty');
    });
    alert("Reverted editors to original state (Server was not changed).");
  }
//end conf edid

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
    
    // Check for data-time attribute
    const aTime = a.getAttribute('data-time');
    const bTime = b.getAttribute('data-time');
    if (aTime !== null && bTime !== null) {
            return (parseFloat(aTime) - parseFloat(bTime)) * dirModifier;
     }
    // 1. Try numeric sort
        const aNo = parseFloat(aText), bNo = parseFloat(bText);
    if (!isNaN(aNo) && !isNaN(bNo) && aNo.toString() === aText) { 
        return (aNo - bNo) * dirModifier;
    }
    // 2. Try date sort (ISO 8601 like your 2026-03-10)
    const aDate = Date.parse(aText);
    const bDate = Date.parse(bText);
    if (!isNaN(aDate) && !isNaN(bDate)) {
        return (aDate - bDate) * dirModifier;
    }
    // 3. Fallback to string sort
    return aText.localeCompare(bText) * dirModifier;
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
    if (reasonVal.length >= 2) { // Show if reason is typed (e.g., "Mozilla" or a "CC")
        bulkBtn.style.display = 'inline-block';
        bulkBtn.innerText = `Release All: ${reasonVal}`;
    } else {
        bulkBtn.style.display = 'none';
    }
    const counterDisplay = document.getElementById('log-count');
    if (counterDisplay) {
        pref='';
        if(data.count>=1000) pref='1000 of ';
        counterDisplay.innerText = `${pref}${data.count} logs`;
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
        const ipSpan = row.querySelector('.ip-info');
        if (!ipSpan) return;

        // Extract IP (Cleaning "NL 20.103.102.154" -> "20.103.102.154")
        const rawText = ipSpan.dataset.ip;
        const ipMatch = rawText.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/);
        const ip = ipMatch ? ipMatch[0] : null;

        // 2. Determine "Status" by looking at the row text
        // If the row contains "Released", we skip it.
        const rowText = row.textContent;
        const isReleased = rowText.includes('Released');

        if (ip && !isReleased) {
            ips.push(ip);
            triggerUnbanCountdown(ip);
        }
    });

    if (ips.length === 0) {
        alert("No active bans found in this filtered view.");
        return;
    }

    if (!confirm(`Release ${ips.length} IPs matching your current filter?`)) return;

    const formData = new FormData();
    formData.append('action', 'Release');
    ips.forEach(val => formData.append('ip[]', val));

    try {
        const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const json = await res.json();
        if (json.status === 'success') {
           // alert(`${json.count} IPs queued for release.`);
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
                const ip = cell.closest('[data-ip]').getAttribute('data-ip');
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

        // 1. Calculate the offset to hit the next :10 mark
        let now = new Date();
        let currentSec = now.getSeconds();

        // 2. Update the GLOBAL variable (no 'let' here!)
        refreshSeconds = (60 - currentSec) + 10;

        // 3. Keep it within a 1-minute-ish window
        if (refreshSeconds > 70) {
            refreshSeconds -= 60;
        }
        
        // 4. Safety check: if it's exactly :10, don't set to 0
        if (refreshSeconds <= 0) refreshSeconds = 60;
    }

    // Update the display
    let m = Math.floor(refreshSeconds / 60).toString().padStart(2, '0');
    let s = (refreshSeconds % 60).toString().padStart(2, '0');
    timerDisplay.innerText = `Auto-refresh in: ${m}:${s}`;
}, 1000);
}
function refreshDashboard() {
    const typeVal = document.getElementById('filterType')?.value || 'all';
    const reasonVal = document.getElementById('filterReason')?.value || 'all';
    
    // Safety check: don't auto-refresh if the user is actively filtering
    if (typeVal !== 'all' || reasonVal !== 'all') return;

    fetch(window.location.pathname + '?action=filter_log&limit=100')
        .then(res => res.json())
        .then(data => {
            const logHtml = data.html;
            if (!logHtml) return;

            const targetTbody = document.getElementById('log-table-body');
            const tempTbody = document.createElement('tbody');
            tempTbody.innerHTML = logHtml;
            
            const fetchedRows = Array.from(tempTbody.querySelectorAll('.log-row'));
            let rowsToAdd = [];

            // 1. Process all fetched rows
            fetchedRows.forEach(fetchedRow => {
                const rowId = fetchedRow.id;
                const existingRow = document.getElementById(rowId);

                if (existingRow) {
                    // Step 1: Sync the specific status cell (usually index 1)
                    const statusCell = existingRow.cells[1];
                    const newContent = fetchedRow.cells[1].innerHTML;

                    if (statusCell && statusCell.innerHTML !== newContent) {
                        statusCell.innerHTML = newContent;
                    }
                } else {
                    // Step 2: Queue for insertion
                    rowsToAdd.push(fetchedRow); 
                }
            });

            // 2. Prepend New Rows (if any)
            if (rowsToAdd.length > 0) {
                // Reverse so the newest (top of fetch) ends up at the top of the table
                rowsToAdd.reverse().forEach(row => {
                    row.classList.add('new-row-animate');
                    
                    // Insert at the top
                    targetTbody.insertBefore(row, targetTbody.firstChild);
                    
                    // Maintain list size: remove the last row to keep it at the limit
                    if (targetTbody.children.length > 100) {
                        targetTbody.removeChild(targetTbody.lastElementChild);
                    }

                    // Re-bind rDNS observer for the new row
                    row.querySelectorAll('.rdns-pending').forEach(span => {
                        if (typeof observer !== 'undefined') observer.observe(span);
                    });

                    setTimeout(() => row.classList.remove('new-row-animate'), 2000);
                });
                
                // Trigger any post-processing UI logic
                if (typeof markNeutralized === 'function') markNeutralized();
            }

            // 3. Update Global UI
            if (data.jailSize && document.getElementById('jail-size')) {
                document.getElementById('jail-size').innerText = `local jail ${data.jailSize}`;
            }

            tempTbody.innerHTML = ''; // Memory cleanup
        })
        .catch(err => console.error("Refresh Error:", err));
}
// Extract your Neutralized logic so it can be called anytime
function markNeutralized() {
    const activeBans = Array.from(document.querySelectorAll('.ip-info'))
                            .map(el => el.getAttribute('data-ip'));
    document.querySelectorAll('#top-10-container a').forEach(a => {
        const ip = a.innerText.trim();
        if (activeBans.includes(ip)) {
            a.classList.add('neutralized');
        }
    });
    document.querySelectorAll('td.audit').forEach(td => {
    const row = td.parentElement;
           row.classList.add('high-intensity');   
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
                        <code>${item.ip}</code><br>
                        <small>Expires: ${item.timeout}</small>
                        <small>Blocked: ${item.packets} packets, ${item.bytes} bytes</small>
                    </div>
                    <div style="white-space: nowrap;">
                        <button onclick="unbanIP('${item.ip}', this)" style="background:var(--safe); color:#fff; border:none; padding:4px 8px; cursor:pointer; margin-bottom: 2px; display: block; width: 100%;">Release</button>
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
//refresh Hall of shame
function loadShameData(){
    document.getElementById('package-filter').style = 'display:block'; 
    document.getElementById('results').style = 'display:none';
    const resElm = document.querySelector('#package-filter pre');
    fetch(`${window.location.pathname}?shame`)
      .then(res => {
        if (res.ok) return res.text() })
      .then(data => {
               resElm.innerText = data; 
      })
}
// Global DOM Ready
document.addEventListener("DOMContentLoaded", () =>  {

//conf editor
// 1. Capture the initial state when the editor opens
document.querySelectorAll('.gnome-terminal-input').forEach(textarea => {
    textarea.dataset.original = textarea.value;

    textarea.addEventListener('input', function() {
        const id = this.closest('.tab-content').id;
        const tabLink = document.querySelector(`.tab-link[onclick*="${id}"]`);
        const fileName = this.getAttribute('data-filename');

        if (this.value !== this.dataset.original) {
            // It's dirty!
            tabLink.classList.add('dirty');
            tabLink.innerHTML = `${fileName} <span style="color: #ffaa00;">●</span>`;
        } else {
            // It's back to original
            tabLink.classList.remove('dirty');
            tabLink.innerHTML = fileName;
        }
    });
});

    
    startRefreshTimer();
    initRDNSObserver();
    document.querySelectorAll('.rdns-pending').forEach(span => observer.observe(span));
    document.querySelectorAll("table th").forEach(headerCell => {
    headerCell.addEventListener("click", () => {
        const table = headerCell.closest('table');
        const index = Array.from(headerCell.parentElement.children).indexOf(headerCell);
        const isAsc = headerCell.classList.contains("th-sort-asc");
        sortTableByColumn(table, index, !isAsc);
    });
});
 document.getElementById('shame').addEventListener('click',()=>{loadShameData()})
 
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

    document.addEventListener('click', (e) => {
        if(e.target && e.target.tagName === 'INPUT'){
            e.target.value = ''; 
   
            if(e.target.id === 'ipsearch'){
                handleIpSearch(e.target.value);
                document.getElementById('results').style = 'display:none';
            }
         }else if(e.target.className === 'ip-info'){
             copyToSearch(e.target.dataset.ip)
         }
    });
    document.addEventListener('change', (e) => {
        if(e.target && e.target.tagName === 'INPUT'){
            refreshDashboard();
        }
    });
markNeutralized() 

}); // end Dom