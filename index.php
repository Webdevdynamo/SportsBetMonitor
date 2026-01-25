<?php
/**
 * Gridiron Giga-Brains: Bet Tracker Dashboard
 * PHP 7.4 + Vanilla JS
 */

// Load your slips from your flat JSON file
$slips_file = __DIR__ . '/data/slips.json';
$slips = file_exists($slips_file) ? json_decode(file_get_contents($slips_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gridiron Giga-Brains | Tracker</title>
    <style>
        :root {
            --regal-gold: #c5a059;
            --deep-black: #1a1a1a;
            --win-green: #2ecc71;
            --loss-red: #e74c3c;
        }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--deep-black); color: white; margin: 20px; }
        .header { border-bottom: 2px solid var(--regal-gold); padding-bottom: 10px; margin-bottom: 30px; }
        .slip-container { display: flex; flex-wrap: wrap; gap: 20px; }
        .slip-card { background: #2a2a2a; border: 1px solid #444; border-radius: 8px; padding: 15px; width: 300px; }
        .leg { padding: 10px; margin-top: 10px; border-radius: 4px; border-left: 5px solid #555; transition: all 0.3s; }
        .leg.winning { border-left-color: var(--win-green); background: rgba(46, 204, 113, 0.1); }
        .leg.losing { border-left-color: var(--loss-red); background: rgba(231, 76, 60, 0.1); }
        .stat-value { font-weight: bold; float: right; }
        .last-updated { font-size: 0.8em; color: #888; margin-top: 10px; text-align: right; }
    </style>
</head>
<body>

<div class="header">
    <h1>Gridiron Giga-Brains Tracker</h1>
    <p>Operational Status: <span id="sync-status">Syncing...</span></p>
</div>

<div class="slip-container" id="dashboard">
    </div>

<script>
    // Pass initial PHP data to JS
    const mySlips = <?php echo json_encode($slips); ?>;
    
    async function updateStats() {
        try {
            const response = await fetch('data/latest_stats.json');
            if (!response.ok) throw new Error('Stats file not found');
            const liveData = await response.json();
            
            renderDashboard(liveData);
            document.getElementById('sync-status').innerText = "Live (Updated: " + new Date().toLocaleTimeString() + ")";
        } catch (err) {
            document.getElementById('sync-status').innerText = "Sync Failed: Stats file missing.";
        }
    }

    function renderDashboard(liveData) {
        const container = document.getElementById('dashboard');
        container.innerHTML = ''; // Clear for refresh

        mySlips.forEach(slip => {
            const card = document.createElement('div');
            card.className = 'slip-card';
            card.innerHTML = `<h3>Slip #${slip.slip_id}</h3>`;

            slip.legs.forEach(leg => {
                const livePlayer = liveData[leg.player_name] || { [leg.metric]: 0 };
                const currentVal = livePlayer[leg.metric];
                
                // Logic: Compare live stat to target
                const isWinning = (leg.direction === 'over') ? (currentVal >= leg.target) : (currentVal <= leg.target);
                const statusClass = isWinning ? 'winning' : 'losing';

                card.innerHTML += `
                    <div class="leg ${statusClass}">
                        <div>${leg.player_name} (${leg.metric})</div>
                        <div>Target: ${leg.direction} ${leg.target} <span class="stat-value">${currentVal}</span></div>
                    </div>
                `;
            });

            container.appendChild(card);
        });
    }

    // Initial load and set interval (30 seconds)
    updateStats();
    setInterval(updateStats, 30000);
</script>

</body>
</html>