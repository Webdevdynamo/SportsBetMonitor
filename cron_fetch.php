<?php
/**
 * Cron Task: MSN API Transformer (JSON-only Edition)
 * PHP 7.4 + Operational Optimization
 */

// Configuration - Use absolute paths for Cron reliability
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';
$api_url = "https://api.msn.com/sports/statistics?apikey=kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ&version=1.0&cm=en-us&activityId=69767807-010b-46de-bcc7-a4b60cc50105&it=web&user=m-20DC418084A963F00D64576B85AD62D9&scn=ANON&ids=SportRadar_Football_NFL_2025_Game_5848514c-3977-4aa3-9db0-94ed5d0ebb34&type=Game&scope=Playergame&sport=Football&leagueid=Football_NFL";

// Ensure data directory exists
if (!is_dir($stats_dir)) {
    mkdir($stats_dir, 0755, true);
}

/**
 * Perform cURL with precise timeouts to prevent Cron hangups
 */
function fetchMsnData($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // 20s max execution
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GridironGigaBrains-Tracker/1.0');
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($status === 200) ? $response : false;
}

$raw_json = fetchMsnData($api_url);

if (!$raw_json) {
    die("MSN API unreachable or returned non-200 status.");
}

$data = json_decode($raw_json, true);
$flatStats = [];

// MSN/SportRadar specific nesting traversal
if (isset($data['value'][0]['statistics'])) {
    foreach ($data['value'][0]['statistics'] as $game) {
        if (!isset($game['teamPlayerStatistics'])) continue;

        foreach ($game['teamPlayerStatistics'] as $team) {
            foreach ($team['playerStatistics'] as $p) {
                $name = $p['player']['name']['rawName'] ?? null;
                if (!$name) continue;

                // Extracting metrics based on your Betting Slip needs
                $flatStats[$name] = [
                    'id'        => $p['player']['id'],
                    'pos'       => $p['player']['playerPosition'],
                    'team'      => $team['teamId'] ?? 'N/A',
                    // Passing
                    'pass_yds'  => $p['passingStatistics']['yards'] ?? 0,
                    'pass_tds'  => $p['passingStatistics']['touchdowns'] ?? 0,
                    // Rushing
                    'rush_yds'  => $p['rushingStatistics']['yards'] ?? 0,
                    'rush_tds'  => $p['rushingStatistics']['touchdowns'] ?? 0,
                    // Receiving
                    'rec_yds'   => $p['receivingStatistics']['yards'] ?? 0,
                    'rec_tds'   => $p['receivingStatistics']['touchdowns'] ?? 0,
                    'receptions'=> $p['receivingStatistics']['receptions'] ?? 0,
                    // Calculated Total TDs (Non-Defense)
                    'total_tds' => ($p['passingStatistics']['touchdowns'] ?? 0) + 
                                   ($p['rushingStatistics']['touchdowns'] ?? 0) + 
                                   ($p['receivingStatistics']['touchdowns'] ?? 0),
                    'timestamp' => time()
                ];
            }
        }
    }
}

/**
 * Atomic Write Pattern: 
 * Prevents the JS from reading a half-written file if the cron is mid-save.
 */
$tmp_file = $cache_file . '.tmp';
file_put_contents($tmp_file, json_encode($flatStats, JSON_PRETTY_PRINT));
rename($tmp_file, $cache_file);

echo "Update Complete: " . count($flatStats) . " players processed.";