<?php
/**
 * Gridiron Giga-Brains: Comprehensive Operational Dashboard
 * PHP 7.4 + Vanilla JS (No DB Edition)
 */

$stats_file = __DIR__ . '/data/latest_stats.json';
$slips_file = __DIR__ . '/data/slips.json';

// --- 1. SMART SYNC HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json');
    $last_update = file_exists($stats_file) ? filemtime($stats_file) : 0;
    $seconds_since = time() - $last_update;

    // Throttle: Only run cron_fetch if data is > 60 seconds old
    if ($seconds_since >= 60) {
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
    <title>Giga-Brains | Bet Tracker</title>
    <style>
        :root {
            --regal-gold: #c5a059;
            --deep-black: #111111;
            --card-bg: #1e1e1e;
            --win-green: #2ecc71;
            --loss-red: #e74c3c;
            --text-muted: #888;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--deep-black); color: #eee; margin: 0; padding: 20px; }
        header { border-bottom: 1px solid var(--regal-gold); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: baseline; }
        h1 { color: var(--regal-gold); text-transform: uppercase; letter-spacing: 2px; margin: 0; }
        #sync-status { font-size: 0.85em; color: var(--text-muted); }

        .dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
        .slip-card { background: var(--card-bg); border: 1px solid #333; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        .slip-header { border-bottom: 1px solid #333; margin-bottom: 15px; padding-bottom: 10px; font-weight: bold; color: var(--regal-gold); display: flex; justify-content: space-between; }
        
        .leg { margin-bottom: 12px; padding: 12px; border-radius: 8px; background: #252525; border-left: 4px solid #444; }
        .leg.winning { border-left-color: var(--win-green); background: rgba(46, 204, 113, 0.05); }
        .leg.losing { border-left-color: var(--loss-red); background: rgba(231, 76, 60, 0.05); }

        .player-name { font-weight: 600; font-size: 1.1em; }
        .metric-label { font-size: 0.75em; color: var(--text-muted); text-transform: uppercase; display: block; }
        .stat-line { display: flex; justify-content: space-between; align-items: center; margin-top: 5px; }
        .current-stat { font-family: 'Courier New', monospace; font-size: 1.3em; color: var(--regal-gold); }

        /* Modal & Builder UI */
        #add-btn { position: fixed; bottom: 30px; right: 30px; background: var(--regal-gold); color: black; border: none; width: 60px; height: 60px; border-radius: 50%; font-size: 30px; cursor: pointer; z-index: 10; }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 30px; border: 1px solid var(--regal-gold); width: 450px; border-radius: 12px; }
        input, select, button.submit { width: 100%; padding: 10px; margin: 8px 0; background: #2a2a2a; border: 1px solid #444; color: white; border-radius: 6px; box-sizing: border-box; }
        button.submit { background: var(--regal-gold); color: black; font-weight: bold; cursor: pointer; border: none; }
    </style>
</head>
<body>

<header>
    <h1>Gridiron Giga-Brains</h1>
    <div id="sync-status">Initializing Tracker...</div>
</header>

<div class="dashboard" id="main-dashboard"></div>

<button id="add-btn" onclick="openModal()">+</button>

<div id="slipModal" class="modal">
    <div class="modal-content">
        <h2 style="color: var(--regal-gold); margin-top: 0;">Build Parlay Slip</h2>
        
        <div id="leg-builder" style="border: 1px dashed #444; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <input type="text" id="p_name" placeholder="Player or Team Name">
            <select id="metric">
                <option value="pass_yds">Passing Yds</option>
                <option value="rush_yds">Rushing Yds</option>
                <option value="rec_yds">Receiving Yds</option>
                <option value="receptions">Receptions</option>
                <option value="moneyline">Moneyline (Win/Loss)</option>
                <option value="total_points">Game Total Score</option>
            </select>
            <input type="number" step="0.5" id="target" placeholder="Line / Target">
            <select id="direction">
                <option value="over">OVER / WIN</option>
                <option value="under">UNDER</option>
            </select>
            <button type="button" class="submit" style="background:#444; color:white;" onclick="addLegToStaging()">Add Leg to Parlay</button>
        </div>

        <div id="staged-list" style="max-height: 150px; overflow-y: auto; font-size: 0.85em; color: var(--regal-gold); margin-bottom: 10px;"></div>

        <button type="button" id="save-slip-btn" class="submit" style="display:none;" onclick="submitFullSlip()">Commit Full Slip</button>
        <button type="button" style="background:none; border:none; color:#666; cursor:pointer; width:100%;" onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
    const mySlips = <?php echo json_encode($slips); ?>;
    let stagedLegs = [];

    // --- MODAL & STAGING ---
    function openModal() { document.getElementById('slipModal').style.display = 'block'; }
    function closeModal() { document.getElementById('slipModal').style.display = 'none'; stagedLegs = []; document.getElementById('staged-list').innerHTML = ''; }
    
    function addLegToStaging() {
        const leg = {
            player_name: document.getElementById('p_name').value,
            metric: document.getElementById('metric').value,
            target: parseFloat(document.getElementById('target').value) || 0,
            direction: document.getElementById('direction').value
        };
        if (!leg.player_name) return;
        stagedLegs.push(leg);
        document.getElementById('staged-list').innerHTML += `<div>• ${leg.player_name}: ${leg.metric} ${leg.direction} ${leg.target}</div>`;
        document.getElementById('save-slip-btn').style.display = 'block';
        document.getElementById('p_name').value = ''; document.getElementById('target').value = '';
    }

    async function submitFullSlip() {
        const res = await fetch('add_slip.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ legs: stagedLegs })
        });
        if (res.ok) location.reload();
    }

    // --- SYNC & RENDERING ---
    async function smartSync() {
        try {
            const syncRes = await fetch('index.php?action=sync');
            const syncStatus = await syncRes.json();
            const statsRes = await fetch('data/latest_stats.json');
            const liveData = await statsRes.json();
            renderDashboard(liveData, syncStatus);
        } catch (e) { document.getElementById('sync-status').innerText = "Sync Offline"; }
    }

    function renderDashboard(liveData, sync) {
        const dashboard = document.getElementById('main-dashboard');
        dashboard.innerHTML = '';

        mySlips.forEach(slip => {
            const card = document.createElement('div');
            card.className = 'slip-card';
            let html = `<div class="slip-header"><span>ID: ${slip.slip_id}</span></div>`;

            slip.legs.forEach(leg => {
                const stats = liveData[leg.player_name] || {};
                let current = 0, isWin = false;

                if (leg.metric === 'moneyline') {
                    current = stats.score || 0;
                    isWin = current > (stats.opponent_score || 0);
                } else {
                    current = stats[leg.metric] || 0;
                    isWin = (leg.direction === 'over') ? (current >= leg.target) : (current <= leg.target);
                }

                html += `
                    <div class="leg ${isWin ? 'winning' : 'losing'}">
                        <span class="metric-label">${leg.metric.replace('_',' ')}</span>
                        <span class="player-name">${leg.player_name}</span>
                        <div class="stat-line">
                            <span>Tgt: ${leg.direction} ${leg.target}</span>
                            <span class="current-stat">${current}</span>
                        </div>
                    </div>`;
            });
            card.innerHTML = html;
            dashboard.appendChild(card);
        });
        document.getElementById('sync-status').innerText = sync.status === 'updated' ? "Live: Just Updated" : `Last Sync: ${sync.since}s ago`;
    }

    smartSync();
    setInterval(smartSync, 5000); // Poll every 5 seconds, server throttles fetch to 60s
</script>
</body>
</html>