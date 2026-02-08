<?php
/**
 * Porreca’s Parlay Palace: Operational Command Center
 * PHP 7.4 + Vanilla JS (JSON-Only Architecture)
 */

$stats_file = __DIR__ . '/data/latest_stats.json';
$slips_file = __DIR__ . '/data/slips.json';

// --- 1. AJAX SYNC CONTROLLER ---
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json');
    $last_update = file_exists($stats_file) ? filemtime($stats_file) : 0;
    $seconds_since = time() - $last_update;

    // Throttle: Only run crawler if data is > 60s old
    if ($seconds_since >= 10) {
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
    <title>Porreca’s Parlay Palace</title>
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
        h1 { color: var(--regal-gold); letter-spacing: 2px; margin: 0; font-weight: bold; }
        
        .dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .slip-card { background: var(--card-bg); border: 1px solid #333; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); position: relative; overflow: hidden; transition: background 0.4s ease; }
        
        /* Finalized & Legend States */
        .legend-bet { border: 2px solid var(--regal-gold) !important; animation: goldPulse 2s infinite; }
        @keyframes goldPulse { 0% { box-shadow: 0 0 5px var(--regal-gold); } 50% { box-shadow: 0 0 20px var(--regal-gold); } 100% { box-shadow: 0 0 5px var(--regal-gold); } }
        
        .slip-final-win { background: linear-gradient(145deg, #1a1a1a, #0b2e18) !important; }
        .slip-final-loss { background: linear-gradient(145deg, #1a1a1a, #3b1414) !important; }

        /* Corner Ribbon System */
        .slip-card::after {
            content: ""; position: absolute; top: 0; right: 0; width: 0; height: 0;
            border-style: solid; border-width: 0 60px 60px 0; border-color: transparent transparent transparent transparent; z-index: 10;
        }
        .slip-final-win::after { border-color: transparent var(--win-green) transparent transparent; }
        .slip-final-loss::after { border-color: transparent var(--loss-red) transparent transparent; }

        .status-ribbon {
            position: absolute; top: 15px; right: -15px; transform: rotate(45deg); width: 70px;
            text-align: center; font-size: 0.65em; font-weight: bold; color: #fff; z-index: 11;
            text-transform: uppercase; letter-spacing: 1px; pointer-events: none;
        }

        .slip-header { border-bottom: 1px solid #222; margin-bottom: 5px; padding-bottom: 5px; font-weight: bold; color: var(--regal-gold); display: flex; justify-content: space-between; }
        
        /* Leg Content */
        .leg { margin-bottom: 10px; padding: 12px; border-radius: 8px; background: #222; border-left: 4px solid #444; position: relative; z-index: 2; }
        .leg.winning { border-left-color: var(--win-green); }
        .leg.losing { border-left-color: var(--loss-red); }
        .player-name { display: block; font-weight: bold; font-size: 1.1em; color: #fff; }
        .metric-label { font-size: 0.75em; color: var(--text-muted); text-transform: uppercase; }
        .stat-line { display: flex; justify-content: space-between; margin-top: 8px; align-items: baseline; }
        .current-stat { font-family: 'Courier New', monospace; font-size: 1.4em; color: var(--regal-gold); font-weight: bold;}

        /* Modal Styles */
        #add-btn { display: none; position: fixed; bottom: 30px; right: 30px; background: var(--regal-gold); color: black; border: none; width: 60px; height: 60px; border-radius: 50%; font-size: 30px; cursor: pointer; z-index: 50; }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 30px; border: 1px solid var(--regal-gold); width: 450px; border-radius: 12px; }
        input, select, button.submit { width: 100%; padding: 12px; margin: 8px 0; background: #2a2a2a; border: 1px solid #444; color: white; border-radius: 6px; box-sizing: border-box; }
        button.submit { background: var(--regal-gold); color: black; font-weight: bold; cursor: pointer; border: none; }
        .teamAlias{
            vertical-align: super;
            font-size: 0.8em; /* Adjust size as needed */
            line-height: normal; /* Prevents affecting the line height of the parent element */
        }
    </style>
</head>
<body>

<div class="ticker-wrap" id="game-ticker"></div>

<div class="container">
    <header>
        <h1>PORRECA’S PARLAY PALACE</h1>
        <div id="sync-status" style="font-size: 0.8em; color: var(--text-muted);">Initializing...</div>
    </header>
    <div class="dashboard" id="main-dashboard"></div>
</div>

<button id="add-btn" onclick="openModal()">+</button>

<div id="slipModal" class="modal">
    <div class="modal-content">
        <h2 style="color: var(--regal-gold); margin-top: 0;">Build Parlay Slip</h2>
        <div id="leg-builder" style="background: #111; padding: 15px; border-radius: 8px; border: 1px dashed #444;">
            <select id="leg_type" onchange="toggleLegInputs()">
                <option value="player">Player Prop</option>
                <option value="ml">Moneyline</option>
                <option value="total">Game Total</option>
            </select>
            <div id="player_input">
                <input type="text" id="p_name" placeholder="Player Name">
                <select id="metric">
                    <option value="pass_yds">Passing Yds</option>
                    <option value="rush_yds">Rushing Yds</option>
                    <option value="rec_yds">Receiving Yds</option>
                    <option value="receptions">Receptions</option>
                </select>
            </div>
            <div id="ml_input" style="display:none;"><select id="ml_team_select"></select></div>
            <div id="total_input" style="display:none;"><select id="total_game_select"></select></div>
            <div id="line_inputs">
                <input type="number" step="0.5" id="target" placeholder="Line / Target">
                <select id="direction">
                    <option value="over">OVER / WIN</option>
                    <option value="under">UNDER</option>
                </select>
            </div>
            <input type="text" id="slip_odds" placeholder="Odds (e.g. +1000)">
            <input type="number" step="0.01" id="slip_wager" placeholder="Wager Amount">
            <button type="button" class="submit" onclick="addLegToStaging()">+ Add Leg</button>
        </div>
        <div id="staged-list" style="margin-top:20px; color: var(--regal-gold); font-size: 0.85em;"></div>
        <button type="button" id="save-slip-btn" class="submit" style="display:none;" onclick="submitFullSlip()">Commit Full Slip</button>
        <button type="button" style="background:none; border:none; color:#666; cursor:pointer; width:100%; margin-top:10px;" onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
    const mySlips = <?php echo json_encode($slips); ?>;
    let stagedLegs = [];

    // --- 1. MODAL & UI LOGIC ---
    function openModal() { document.getElementById('slipModal').style.display = 'block'; }
    function closeModal() { document.getElementById('slipModal').style.display = 'none'; stagedLegs = []; document.getElementById('staged-list').innerHTML = ''; }
    
    function toggleLegInputs() {
        const type = document.getElementById('leg_type').value;
        document.getElementById('player_input').style.display = (type === 'player') ? 'block' : 'none';
        document.getElementById('ml_input').style.display = (type === 'ml') ? 'block' : 'none';
        document.getElementById('total_input').style.display = (type === 'total') ? 'block' : 'none';
        document.getElementById('line_inputs').style.display = (type === 'ml') ? 'none' : 'block';
    }

    function addLegToStaging() {
        const type = document.getElementById('leg_type').value;
        let leg = { direction: document.getElementById('direction').value };
        if (type === 'player') {
            leg.player_name = document.getElementById('p_name').value;
            leg.metric = document.getElementById('metric').value;
            leg.target = parseFloat(document.getElementById('target').value);
        } else if (type === 'ml') {
            leg.player_name = document.getElementById('ml_team_select').value;
            leg.metric = 'moneyline'; leg.target = 0; leg.direction = 'over';
        } else if (type === 'total') {
            leg.player_name = document.getElementById('total_game_select').value;
            leg.metric = 'total_points'; leg.target = parseFloat(document.getElementById('target').value);
        }
        if (leg.player_name) {
            stagedLegs.push(leg);
            document.getElementById('staged-list').innerHTML += `<div>• ${leg.player_name} (${leg.metric})</div>`;
            document.getElementById('save-slip-btn').style.display = 'block';
        }
    }

    async function submitFullSlip() {
        const payload = {
            slip_id: "SGP-" + Math.random().toString(36).substr(2, 5).toUpperCase(),
            odds: document.getElementById('slip_odds').value,
            wager: parseFloat(document.getElementById('slip_wager').value),
            legs: stagedLegs
        };
        const res = await fetch('add_slip.php', { method: 'POST', body: JSON.stringify(payload) });
        if (res.ok) location.reload();
    }

    // --- 2. RENDER & CALCULATION LOGIC ---
    function calculatePayout(wager, odds) {
        if (!wager || !odds) return null;
        const numOdds = parseInt(odds.toString().replace('+', ''));
        let profit = (numOdds > 0) ? wager * (numOdds / 100) : wager * (100 / Math.abs(numOdds));
        return (parseFloat(wager) + profit).toFixed(2);
    }

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

        // --- NEW: SORTING LOGIC ---
        // We create a temporary array that includes a 'isFinal' flag for sorting
        mySlips.map(slip => {
            slip.legs.every(leg => {
                // const stats = liveData[leg.player_name] || {};
            });
        });
        const sortedSlips = mySlips.map(slip => {
            const isFinal = slip.legs.every(leg => {
                const stats = liveData[leg.player_name] || {};
                return (stats.gameStatus === 'Final');
            });
            return { ...slip, isFinal };
        }).sort((a, b) => a.isFinal - b.isFinal); // False (0) comes before True (1)
        console.log(sortedSlips);

        sortedSlips.forEach(slip => {
            let allFinal = slip.isFinal;
            let slipWinning = true;
            let legsHtml = '';

            // Build Legs
            slip.legs.forEach(leg => {
                const stats = liveData[leg.player_name] || {};
                let currentLabel = 0, isWin = false;
                
                if ((stats.gameStatus || 'Upcoming') !== 'Final') allFinal = false;

                if (leg.metric === 'moneyline') {
                    const diff = (stats.score || 0) - (stats.opponent_score || 0);
                    currentLabel = (diff > 0 ? '+' : '') + diff;
                    isWin = (stats.score || 0) > (stats.opponent_score || 0);
                } else {
                    const rawVal = stats[leg.metric] || 0;
                    currentLabel = rawVal;
                    isWin = (leg.direction === 'over') ? (rawVal >= leg.target) : (rawVal <= leg.target);
                }

                if (!isWin) slipWinning = false;

                // --- NEW: NOTE RENDERING ---
                const noteHtml = leg.note ? `<div class="leg-note" style="font-size: 0.7em; color: var(--regal-gold); font-style: italic; margin-bottom: 4px;">${leg.note}</div>` : '';

                // Identify if the period is over for this specific leg
                const isPeriodOver = (leg.note.includes("1st Half") && ["Halftime", "Q3", "Q4", "Final"].includes(stats.gameStatus)) ||
                                    (leg.note.includes("1st Quarter") && ["Q2", "Halftime", "Q3", "Q4", "Final"].includes(stats.gameStatus));

                legsHtml += `
                    <div class="leg ${isWin ? 'winning' : 'losing'}" style="${isPeriodOver ? 'opacity: 0.6; border-style: dashed;' : ''}">
                        <span class="player-name">${leg.player_name}</span>
                        ${noteHtml}
                        ${isPeriodOver ? '<span style="font-size:0.6em; color:var(--regal-gold);">[PERIOD CLOSED]</span>' : ''}
                        <span class="metric-label">${leg.metric.replace('_',' ')}</span>
                        <div class="stat-line">
                            <span style="color: #666; font-size: 0.8em;">Target: ${leg.direction.toUpperCase()} ${leg.target}</span>
                            <span class="current-stat">${currentLabel}</span>
                        </div>
                    </div>`;
            });

            const numOdds = parseInt((slip.odds || "").toString().replace('+', ''));
            const isLegend = numOdds >= 1000;
            const finalClass = (allFinal) ? (slipWinning ? 'slip-final-win' : 'slip-final-loss') : '';
            const ribbonHtml = allFinal ? `<div class="status-ribbon">${slipWinning ? 'Win' : 'Loss'}</div>` : '';
            const payout = slip.payout || calculatePayout(slip.wager, slip.odds);

            let metaHtml = (slip.odds || slip.wager) ? `<div class="slip-meta" style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 0.8em; color: #888; border-bottom: 1px solid #222; padding-bottom: 10px; position: relative; z-index: 2;">
                <span>ODDS: <b style="color:var(--regal-gold)">${slip.odds}</b></span>
                <span>WAGER: <b>$${slip.wager}</b></span>
                <span>PAYOUT: <b style="color:var(--win-green)">$${payout}</b></span>
            </div>` : '';

            const card = document.createElement('div');
            card.className = `slip-card ${finalClass} ${isLegend ? 'legend-bet' : ''}`;
            card.innerHTML = ribbonHtml + `<div class="slip-header" style="position: relative; z-index: 2;"><span>SLIP: ${slip.slip_id}</span></div>` + metaHtml + legsHtml;
            dashboard.appendChild(card);
        });

        // Dropdown Population
        const mlSelect = document.getElementById('ml_team_select');
        const totalSelect = document.getElementById('total_game_select');
        mlSelect.innerHTML = ''; totalSelect.innerHTML = '';
        (liveData.league_slate || []).forEach(game => {
            mlSelect.innerHTML += `<option value="${game.away}">${game.away}</option><option value="${game.home}">${game.home}</option>`;
            totalSelect.innerHTML += `<option value="${game.away}@${game.home} Total">${game.away}@${game.home}</option>`;
        });
    }

    async function smartSync() {
        try {
            const syncRes = await fetch('index.php?action=sync');
            const syncStatus = await syncRes.json();
            const statsRes = await fetch('data/latest_stats.json');
            const liveData = await statsRes.json();
            renderTicker(liveData);
            renderDashboard(liveData);
            document.getElementById('sync-status').innerText = (syncStatus.status === 'updated') ? "Live" : `Cached (${syncStatus.since}s)`;
        } catch (e) { document.getElementById('sync-status').innerText = "Sync Error"; }
    }
    smartSync(); setInterval(smartSync, 5000);
</script>
</body>
</html>