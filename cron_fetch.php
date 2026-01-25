<?php
/**
 * cron_fetch.php - Combined Player & Game Score Transformer
 */
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';

// Define your two distinct endpoints
$player_stats_url = "https://api.msn.com/sports/statistics?apikey=kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ&version=1.0&cm=en-us&activityId=69767807-010b-46de-bcc7-a4b60cc50105&it=web&user=m-20DC418084A963F00D64576B85AD62D9&scn=ANON&ids=SportRadar_Football_NFL_2025_Game_5848514c-3977-4aa3-9db0-94ed5d0ebb34&type=Game&scope=Playergame&sport=Football&leagueid=Football_NFL";

$game_scores_url = "https://api.msn.com/sports/livegames?apikey=kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ&version=1.0&cm=en-us&user=m-20DC418084A963F00D64576B85AD62D9&lat=47.7712&long=-122.3828&activityId=69767807-010b-46de-bcc7-a4b60cc50105&it=web&scn=ANON&ids=5848514c39774aa39db094ed5d0ebb34&scope=Full";

function fetchMsn($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    return (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) ? json_decode($res, true) : null;
}

$playerData = fetchMsn($player_stats_url);
$gameData = fetchMsn($game_scores_url);
$flatStats = [];

// --- PART 1: PLAYER STATS (Existing Logic) ---
if (isset($playerData['value'][0]['statistics'])) {
    foreach ($playerData['value'][0]['statistics'] as $game) {
        foreach ($game['teamPlayerStatistics'] as $team) {
            foreach ($team['playerStatistics'] as $p) {
                $name = $p['player']['name']['rawName'] ?? null;
                if ($name) {
                    $flatStats[$name] = [
                        'pass_yds' => $p['passingStatistics']['yards'] ?? 0,
                        'rush_yds' => $p['rushingStatistics']['yards'] ?? 0,
                        'rec_yds'  => $p['receivingStatistics']['yards'] ?? 0,
                        'receptions' => $p['receivingStatistics']['receptions'] ?? 0,
                        'total_tds' => ($p['passingStatistics']['touchdowns'] ?? 0) + ($p['rushingStatistics']['touchdowns'] ?? 0) + ($p['receivingStatistics']['touchdowns'] ?? 0)
                    ];
                }
            }
        }
    }
}

// --- PART 2: GAME SCORING (New Logic) ---
if (isset($gameData['value'][0]['games'])) {
    foreach ($gameData['value'][0]['games'] as $g) {
        $gameId = $g['id'] ?? 'unknown';
        $p1 = $g['participants'][0]['result']['score'] ?? 0;
        $p2 = $g['participants'][1]['result']['score'] ?? 0;
        $total = (int)$p1 + (int)$p2;

        // We use a special naming convention for game totals so JS can find them
        $flatStats["Game Total"] = [
            'total_points' => $total,
            'game_id'      => $gameId,
            'clock'        => $g['gameState']['gameClock'] ?? null,
            'status'       => $g['gameState']['gameStatus'] ?? 'Unknown'
        ];
    }
}

// Atomic Write
file_put_contents($cache_file . '.tmp', json_encode($flatStats));
rename($cache_file . '.tmp', $cache_file);