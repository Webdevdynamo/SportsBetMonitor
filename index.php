<?php
/**
 * Gridiron Giga-Brains: Comprehensive Bet Tracker
 * Features: Smart Sync, Live Dashboard, JSON-Only, Add-Slip Modal
 * PHP 7.4 + Vanilla JS
 */

$stats_file = __DIR__ . '/data/latest_stats.json';
$slips_file = __DIR__ . '/data/slips.json';

// --- 1. AJAX SMART SYNC HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json');
    $last_update = file_exists($stats_file) ? filemtime($stats_file) : 0;
    $seconds_since = time() - $last_update;

    // Only run cron_fetch if the data is > 60 seconds old
    if ($seconds_since >= 60) {
        // Suppress errors to keep JSON output clean
        @include('cron_fetch.php'); 
        echo json_encode(['status' => 'updated', 'since' => 0]);
    } else {
        echo json_encode(['status' => 'fresh', 'since' => $seconds_since]);
    }
    exit;
}

// --- 2. INITIAL DATA LOAD ---
$slips = file_exists($slips_file) ? json_decode(file_get_contents($slips_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Degen NFL Bets | Dashboard</title>
    <style>
        :root {
            --regal-gold: #c5a059;
            --deep-black: #121212;
            --card-bg: #1e1e1e;
            --win-green: #2ecc71;
            --loss-red: #e74c3c;
            --text-muted: #888;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--deep-black); color: #eee; margin: 0; padding: 20px; }
        header { border-bottom: 1px solid var(--regal-gold); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: baseline; }
        h1 { color: var(--regal-gold); text-transform: uppercase; letter-spacing: 2px; margin: 0; }
        #sync-status { font-size: 0.85em; color: var(--text-muted); }

        /* Dashboard Layout */
        .dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .slip-card { background: var(--card-bg); border: 1px solid #333; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        .slip-header { border-bottom: 1px solid #333; margin-bottom: 15px; padding-bottom: 10px; font-weight: bold; color: var(--regal-gold); }
        
        .leg { margin-bottom: 12px; padding: 12px; border-radius: 8px; background: #252525; border-left: 4px solid #444; transition: all 0.4s ease; }
        .leg.winning { border-left-color: var(--win-green); background: rgba(46, 204, 113, 0.05); }
        .leg.losing { border-left-color: var(--loss-red); background: rgba(231, 76, 60, 0.05); }

        .player-name { font-weight: 600; font-size: 1.1em; display: block; }
        .metric-label { font-size: 0.75em; color: var(--text-muted); text-transform: uppercase; }
        .stat-line { display: flex; justify-content: space-between; align-items: center; margin-top: 5px; }
        .current-stat { font-family: 'Courier New', monospace; font-size: 1.3em; color: var(--regal-gold); font-weight: bold; }

        /* Add Slip UI */
        #add-btn { position: fixed; bottom: 30px; right: 30px; background: var(--regal-gold); color: black; border: none; width: 60px; height: 60px; border-radius: 50%; font-size: 30px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.6); z-index: 10; }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); }
        .modal-content { background: var(--card-bg); margin: 10% auto; padding: 30px; border: 1px solid var(--regal-gold); width: 400px; border-radius: 12px; }
        input, select, button.submit { width: 100%; padding: 12px; margin: 10px 0; background: #2a2a2a; border: 1px solid #444; color: white; border-radius: 6px; box-sizing: border-box; }
        button.submit { background: var(--regal-gold); color: black; font-weight: bold; cursor: pointer; border: none; margin-top: 20px; }
    </style>
</head>
<body>

<header>
    <h1>Degen NFL Bets</h1>
    <div id="sync-status">Syncing stats...</div>
</header>

<div class="dashboard" id="main-dashboard">
    </div>

<button id="add-btn" title="Add New Betting Slip" onclick="openModal()">+</button>

<div id="slipModal" class="modal">
    <div class="modal-content" style="width: 500px;">
        <h2 style="color: var(--regal-gold); margin-top: 0;">Build Parlay Slip</h2>
        
        <div id="leg-builder" style="background: #111; padding: 15px; border-radius: 8px; border: 1px dashed #444;">
            <input type="text" id="p_name" placeholder="Player Name">
            <div style="display: flex; gap: 10px;">
                <select id="metric" style="flex: 2;">
                    <option value="pass_yds">Passing Yds</option>
                    <option value="rush_yds">Rushing Yds</option>
                    <option value="rec_yds">Receiving Yds</option>
                    <option value="receptions">Receptions</option>
                    <option value="total_tds">Total TDs</option>
                </select>
                <input type="number" step="0.5" id="target" placeholder="Line" style="flex: 1;">
                <select id="direction" style="flex: 1;">
                    <option value="over">Over</option>
                    <option value="under">Under</option>
                </select>
            </div>
            <button type="button" class="submit" style="background: #444; color: white;" onclick="addLegToStaging()">+ Add Leg to Slip</button>
        </div>

        <div id="staged-legs-container" style="margin-top: 20px;">
            <p class="metric-label">Staged Legs:</p>
            <ul id="staged-list" style="list-style: none; padding: 0;"></ul>
        </div>

        <button type="button" id="save-slip-btn" class="submit" style="display:none;" onclick="submitFullSlip()">Save Full Slip</button>
        <button type="button" style="background:none; border:none; color:#666; cursor:pointer; width:100%; margin-top:10px;" onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
    let stagedLegs = [];

    function addLegToStaging() {
        const leg = {
            player_name: document.getElementById('p_name').value,
            metric: document.getElementById('metric').value,
            target: parseFloat(document.getElementById('target').value),
            direction: document.getElementById('direction').value
        };

        if (!leg.player_name || isNaN(leg.target)) return;

        stagedLegs.push(leg);
        renderStagedLegs();
        
        // Clear inputs for next leg
        document.getElementById('p_name').value = '';
        document.getElementById('target').value = '';
        document.getElementById('p_name').focus();
    }

    function renderStagedLegs() {
        const list = document.getElementById('staged-list');
        list.innerHTML = '';
        stagedLegs.forEach((leg, index) => {
            list.innerHTML += `<li style="font-size: 0.9em; margin-bottom: 5px; color: var(--regal-gold);">
                ${leg.player_name}: ${leg.direction} ${leg.target} ${leg.metric}
            </li>`;
        });
        document.getElementById('save-slip-btn').style.display = stagedLegs.length > 0 ? 'block' : 'none';
    }

    async function submitFullSlip() {
        const payload = { legs: stagedLegs };
        const res = await fetch('add_slip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.ok) {
            stagedLegs = [];
            location.reload();
        }
    }

    // Include your existing closeModal(), openModal(), smartSync(), and renderDashboard() functions...
    const mySlips = <?php echo json_encode($slips); ?>;

    // --- MODAL CONTROLS ---
    function openModal() { document.getElementById('slipModal').style.display = 'block'; }
    function closeModal() { document.getElementById('slipModal').style.display = 'none'; }
    window.onclick = function(event) { if (event.target == document.getElementById('slipModal')) closeModal(); }

    // --- DATA SUBMISSION ---
    async function submitSlip() {
        const payload = {
            legs: [{
                player_name: document.getElementById('p_name').value,
                metric: document.getElementById('metric').value,
                target: parseFloat(document.getElementById('target').value),
                direction: document.getElementById('direction').value
            }]
        };

        try {
            const res = await fetch('add_slip.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await res.json();
            if (result.status === 'success') {
                location.reload(); // Refresh to load new data from slips.json
            } else {
                alert("Error: " + result.message);
            }
        } catch (e) {
            alert("Submission failed. Check server permissions.");
        }
    }

    // --- SMART SYNC ENGINE ---
    async function smartSync() {
        try {
            // Check if update is needed (throttled at 60s on server)
            const syncRes = await fetch('index.php?action=sync');
            const syncStatus = await syncRes.json();

            // Fetch current stats
            const statsRes = await fetch('data/latest_stats.json');
            const liveData = await statsRes.json();

            renderDashboard(liveData, syncStatus);
        } catch (err) {
            document.getElementById('sync-status').innerText = "Sync Error: Check Data Files";
        }
    }

    function renderDashboard(liveData, syncStatus) {
        const dashboard = document.getElementById('main-dashboard');
        dashboard.innerHTML = '';

        if (mySlips.length === 0) {
            dashboard.innerHTML = '<p style="color:#666;">No active slips. Click the + to add one.</p>';
        }

        mySlips.forEach(slip => {
            const card = document.createElement('div');
            card.className = 'slip-card';
            card.innerHTML = `<div class="slip-header">SLIP ID: ${slip.slip_id}</div>`;

            slip.legs.forEach(leg => {
                const stats = liveData[leg.player_name] || {};
                const current = stats[leg.metric] || 0;

                // Win/Loss Calculation
                const isWin = (leg.direction === 'over') ? (current >= leg.target) : (current <= leg.target);
                const legStatus = isWin ? 'winning' : 'losing';

                card.innerHTML += `
                    <div class="leg ${legStatus}">
                        <span class="metric-label">${leg.metric.replace('_', ' ')}</span>
                        <span class="player-name">${leg.player_name}</span>
                        <div class="stat-line">
                            <span>Target: ${leg.direction.toUpperCase()} ${leg.target}</span>
                            <span class="current-stat">${current}</span>
                        </div>
                    </div>
                `;
            });
            dashboard.appendChild(card);
        });

        // Update visual status
        const statusMsg = syncStatus.status === 'updated' ? "Data Refreshed" : `Last Update: ${syncStatus.since}s ago`;
        document.getElementById('sync-status').innerText = statusMsg;
    }

    // Initial load and 5-second poll
    smartSync();
    setInterval(smartSync, 5000);
</script>

</body>
</html>