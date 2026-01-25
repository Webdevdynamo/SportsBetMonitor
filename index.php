<?php
/**
 * Gridiron Giga-Brains: Operational Bet Tracker
 * PHP 7.4 + Vanilla JS (No DB Edition)
 */

$stats_file = __DIR__ . '/data/latest_stats.json';
$slips_file = __DIR__ . '/data/slips.json';

// --- AJAX SYNC HANDLER ---
// This handles the background execution request from the JS frontend
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json');
    $last_update = file_exists($stats_file) ? filemtime($stats_file) : 0;
    $seconds_since = time() - $last_update;

    // Throttle: Only run the heavy cron_fetch logic once per minute
    if ($seconds_since >= 60) {
        require_once('cron_fetch.php'); 
        echo json_encode(['status' => 'updated', 'since' => 0]);
    } else {
        echo json_encode(['status' => 'fresh', 'since' => $seconds_since]);
    }
    exit;
}

// --- INITIAL DATA LOAD ---
$slips = file_exists($slips_file) ? json_decode(file_get_contents($slips_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gridiron Giga-Brains | Bet Tracker</title>
    <style>
        :root {
            --regal-gold: #c5a059;
            --deep-black: #121212;
            --card-bg: #1e1e1e;
            --win-green: #2ecc71;
            --loss-red: #e74c3c;
            --text-muted: #888;
        }
        body { 
            font-family: 'Segoe UI', Helvetica, sans-serif; 
            background: var(--deep-black); 
            color: #eee; 
            margin: 0; padding: 20px; 
        }
        header { 
            border-bottom: 1px solid var(--regal-gold); 
            margin-bottom: 30px; 
            display: flex; justify-content: space-between; align-items: baseline;
        }
        h1 { color: var(--regal-gold); text-transform: uppercase; letter-spacing: 2px; margin: 0; }
        #sync-status { font-size: 0.85em; color: var(--text-muted); }

        .dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .slip-card { 
            background: var(--card-bg); 
            border: 1px solid #333; 
            border-radius: 12px; 
            padding: 20px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }
        .slip-header { border-bottom: 1px solid #333; margin-bottom: 15px; padding-bottom: 10px; font-weight: bold; }
        
        .leg { 
            margin-bottom: 12px; 
            padding: 12px; 
            border-radius: 8px; 
            background: #252525;
            border-left: 4px solid #444;
        }
        .leg.winning { border-left-color: var(--win-green); background: rgba(46, 204, 113, 0.05); }
        .leg.losing { border-left-color: var(--loss-red); background: rgba(231, 76, 60, 0.05); }

        .player-name { font-weight: 600; font-size: 1.1em; }
        .metric-label { font-size: 0.85em; color: var(--text-muted); display: block; }
        .stat-line { display: flex; justify-content: space-between; margin-top: 5px; }
        .current-stat { font-family: monospace; font-size: 1.2em; color: var(--regal-gold); }
    </style>
</head>
<body>

<header>
    <h1>Gridiron Giga-Brains</h1>
    <div id="sync-status">Initializing...</div>
</header>

<div class="dashboard" id="main-dashboard">
    </div>


<script>
    const mySlips = <?php echo json_encode($slips); ?>;

    /**
     * Core Sync Logic:
     * Pings the server every 5 seconds. Server decides if it's time to fetch MSN.
     */
    async function smartSync() {
        try {
            // 1. Tell PHP to check the 1-minute update throttle
            const syncResponse = await fetch('index.php?action=sync');
            const status = await syncResponse.json();

            // 2. Fetch the actual flattened data (whether just updated or cached)
            const dataResponse = await fetch('data/latest_stats.json');
            const liveData = await dataResponse.json();

            updateUI(liveData, status);
        } catch (e) {
            document.getElementById('sync-status').innerText = "Network Error: Syncing paused.";
        }
    }

    function updateUI(liveData, status) {
        const dashboard = document.getElementById('main-dashboard');
        dashboard.innerHTML = ''; // Efficient enough for 10-20 slips

        mySlips.forEach(slip => {
            const card = document.createElement('div');
            card.className = 'slip-card';
            card.innerHTML = `<div class="slip-header">SLIP ID: ${slip.slip_id}</div>`;

            slip.legs.forEach(leg => {
                // Access live data with O(1) lookup
                const playerStats = liveData[leg.player_name] || {};
                const currentVal = playerStats[leg.metric] || 0;

                // Simple comparison logic
                const isWinning = (leg.direction === 'over') ? (currentVal >= leg.target) : (currentVal <= leg.target);
                const stateClass = isWinning ? 'winning' : 'losing';

                card.innerHTML += `
                    <div class="leg ${stateClass}">
                        <span class="metric-label">${leg.metric.replace('_', ' ').toUpperCase()}</span>
                        <span class="player-name">${leg.player_name}</span>
                        <div class="stat-line">
                            <span>Target: ${leg.direction} ${leg.target}</span>
                            <span class="current-stat">${currentVal}</span>
                        </div>
                    </div>
                `;
            });
            dashboard.appendChild(card);
        });

        // Update footer info
        const statusText = (status.status === 'updated') ? "Data Refreshed" : `Cache Age: ${status.since}s`;
        document.getElementById('sync-status').innerText = statusText;
    }

    // Start the engine: Attempt update every 5 seconds
    smartSync();
    setInterval(smartSync, 5000);
</script>

</body>
</html>