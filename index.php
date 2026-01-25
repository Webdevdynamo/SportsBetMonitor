<?php
/**
 * Gridiron Giga-Brains: Operational Command Center
 * PHP 7.4 + Vanilla JS (JSON-Only Architecture)
 */

$stats_file = __DIR__ . '/data/latest_stats.json';
$slips_file = __DIR__ . '/data/slips.json';

// --- 1. AJAX SYNC CONTROLLER ---
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json');
    $last_update = file_exists($stats_file) ? filemtime($stats_file) : 0;
    $seconds_since = time() - $last_update;

    // Throttle: Only run heavy crawler if data is > 60s old
    if ($seconds_since >= 60) {
        @include('cron_fetch.php'); 
        echo json_encode(['status' => 'updated', 'since' => 0]);
    } else {
        echo json_encode(['status' => 'fresh', 'since' => $seconds_since]);
    }
    exit;
}

// --- 2. INITIAL LOAD ---
$slips = file_exists($slips_file) ? json_decode(file_get_contents($slips_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giga-Brains | Live Tracker</title>
    <style>
        :root {
            --regal-gold: #c5a059;
            --deep-black: #0d0d0d;
            --card-bg: #1a1a1a;
            --win-green: #2ecc71;
            --loss-red: #e74c3c;
            --text-muted: #666;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--deep-black); color: #eee; margin: 0; padding: 0; }
        
        /* Ticker Styles */
        .ticker-wrap { 
            background: #111; padding: 10px; display: flex; gap: 15px; overflow-x: auto; 
            border-bottom: 1px solid var(--regal-gold); scrollbar-width: none;
        }
        .ticker-wrap::-webkit-scrollbar { display: none; }
        .score-box { 
            background: #222; min-width: 160px; padding: 10px; border-radius: 8px; border: 1px solid #333; 
            flex-shrink: 0; font-size: 0.85em; 
        }
        .score-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .winning-team { color: var(--win-green); font-weight: bold; }
        .game-meta { font-size: 0.7em; color: var(--regal-gold); text-transform: uppercase; border-top: 1px solid #333; margin-top: 5px; }

        /* Dashboard Styles */
        .container { padding: 20px; }
        header { border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: baseline; }
        h1 { color: var(--regal-gold); letter-spacing: 2px; margin: 0; }
        
        .dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .slip-card { background: var(--card-bg); border: 1px solid #333; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        .slip-header { border-bottom: 1px solid #333; margin-bottom: 15px; padding-bottom: 10px; font-weight: bold; color: var(--regal-gold); }
        
        .leg { margin-bottom: 10px; padding: 12px; border-radius: 8px; background: #222; border-left: 4px solid #444; }
        .leg.winning { border-left-color: var(--win-green); background: rgba(46, 204, 113, 0.05); }
        .leg.losing { border-left-color: var(--loss-red); background: rgba(231, 76, 60, 0.05); }
        .stat-line { display: flex; justify-content: space-between; margin-top: 5px; }
        .current-stat { font-family: monospace; font-size: 1.2em; color: var(--regal-gold); }

        /* Modal Styles */
        #add-btn { position: fixed; bottom: 30px; right: 30px; background: var(--regal-gold); color: black; border: none; width: 60px; height: 60px; border-radius: 50%; font-size: 30px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.6); }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 30px; border: 1px solid var(--regal-gold); width: 450px; border-radius: 12px; }
        input, select, button.submit { width: 100%; padding: 12px; margin: 8px 0; background: #2a2a2a; border: 1px solid #444; color: white; border-radius: 6px; box-sizing: border-box; }
        button.submit { background: var(--regal-gold); color: black; font-weight: bold; cursor: pointer; border: none; }
    </style>
</head>
<body>

<div class="ticker-wrap" id="game-ticker"></div>

<div class="container">
    <header>
        <h1>GRIDIRON GIGA-BRAINS</h1>
        <div id="sync-status" style="font-size: 0.8em; color: var(--text-muted);">Initializing...</div>
    </header>

    <div class="dashboard" id="main-dashboard"></div>
</div>

<button id="add-btn" onclick="openModal()">+</button>

<div id="slipModal" class="modal">
    <div class="modal-content">
        <h2 style="color: var(--regal-gold); margin: 0 0 20px 0;">Build Parlay Slip</h2>
        <div id="leg-builder" style="border: 1px dashed #444; padding: 15px; border-radius: 8px;">
            <input type="text" id="p_name" placeholder="Player or Team Name">
            <select id="metric">
                <option value="pass_yds">Passing Yds</option>
                <option value="rush_yds">Rushing Yds</option>
                <option value="rec_yds">Receiving Yds</option>
                <option value="receptions">Receptions</option>
                <option value="moneyline">Moneyline (Win/Loss)</option>
                <option value="total_points">Game Total Points</option>
            </select>
            <input type="number" step="0.5" id="target" placeholder="Target Line">
            <select id="direction">
                <option value="over">OVER / WIN</option>
                <option value="under">UNDER / LOSS</option>
            </select>
            <button type="button" class="submit" style="background:#333; color:white;" onclick="addLegToStaging()">Add Leg</button>
        </div>
        <div id="staged-list" style="margin: 15px 0; max-height: 120px; overflow-y: auto;"></div>
        <button type="button" id="save-slip-btn" class="submit" style="display:none;" onclick="submitFullSlip()">Commit Full Slip</button>
        <button type="button" style="background:none; border:none; color:#666; cursor:pointer; width:100%; margin-top:10px;" onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
    const mySlips = <?php echo json_encode($slips); ?>;
    let stagedLegs = [];

    // --- 1. MODAL CONTROLS ---
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
        document.getElementById('staged-list').innerHTML += `<div style="font-size:0.8em; margin-bottom:5px;">• ${leg.player_name}: ${leg.direction} ${leg.target} ${leg.metric}</div>`;
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

    // --- 2. LIVE DASHBOARD RENDERING ---
    function renderTicker(liveData) {
        const ticker = document.getElementById('game-ticker');
        ticker.innerHTML = '';
        (liveData.league_slate || []).forEach(game => {
            const box = document.createElement('div');
            box.className = 'score-box';
            box.innerHTML = `
                <div class="score-row"><span class="${game.away_s > game.home_s ? 'winning-team' : ''}">${game.away}</span><span>${game.away_s}</span></div>
                <div class="score-row"><span class="${game.home_s > game.away_s ? 'winning-team' : ''}">${game.home}</span><span>${game.home_s}</span></div>
                <div class="game-meta">${game.status} - ${game.clock}</div>`;
            ticker.appendChild(box);
        });
    }

    function renderDashboard(liveData) {
        const dashboard = document.getElementById('main-dashboard');
        dashboard.innerHTML = '';
        mySlips.forEach(slip => {
            const card = document.createElement('div');
            card.className = 'slip-card';
            let html = `<div class="slip-header">SLIP ID: ${slip.slip_id}</div>`;
            slip.legs.forEach(leg => {
                const stats = liveData[leg.player_name] || {};
                let current = 0, isWin = false, displayStat = 0;
                
                if (leg.metric === 'moneyline') {
                    const score = stats.score || 0;
                    const oppScore = stats.opponent_score || 0;
                    
                    // Calculate the Delta (e.g., 7 - 10 = -3)
                    displayStat = score - oppScore;
                    isWin = score > oppScore;
                    
                    // Format for display (optional: add '+' for positive numbers)
                    current = (displayStat > 0 ? '+' : '') + displayStat;
                } else {
                    current = stats[leg.metric] || 0;
                    isWin = (leg.direction === 'over') ? (current >= leg.target) : (current <= leg.target);
                }

                html += `
                    <div class="leg ${isWin ? 'winning' : 'losing'}">
                        <span class="player-name">${leg.player_name}</span>
                        <span class="metric-label">${leg.metric.replace('_',' ')}</span>
                        <div class="stat-line">
                            <span>Target: ${leg.direction.toUpperCase()} ${leg.target}</span>
                            <span class="current-stat">${current}</span>
                        </div>
                    </div>`;
            });
            card.innerHTML = html;
            dashboard.appendChild(card);
        });
    }

    // --- 3. SMART SYNC ENGINE ---
    async function smartSync() {
        try {
            const syncRes = await fetch('index.php?action=sync');
            const syncStatus = await syncRes.json();
            const statsRes = await fetch('data/latest_stats.json');
            const liveData = await statsRes.json();
            
            renderDashboard(liveData);
            renderTicker(liveData);
            document.getElementById('sync-status').innerText = (syncStatus.status === 'updated') ? "Status: Live Updated" : `Status: Cached (${syncStatus.since}s)`;
        } catch (e) { document.getElementById('sync-status').innerText = "Status: Sync Error"; }
    }

    smartSync();
    setInterval(smartSync, 5000); // Attempt sync check every 5s
</script>
</body>
</html>