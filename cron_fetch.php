<?php
/**
 * Gridiron Giga-Brains: Multi-Game All-Day Crawler
 * PHP 7.4 - Operational Optimization (JSON-Only)
 */

// 1. CONFIGURATION & SESSION SETUP
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';

// Static Auth Params from your verified browser session
$apiKey = "kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ";
$userToken = "m-20DC418084A963F00D64576B85AD62D9";

// Session Persistence: MD5 hash of date + token prevents API rejection
$activityId = md5(date('Y-m-d') . $userToken); 
$currentDateTime = date('Y-m-d\TH:i:s');

// Boundaries for "Today" in milliseconds (MSN/SportRadar format)
$todayStart = strtotime('today') * 1000;
$todayEnd   = strtotime('tomorrow') * 1000;

/**
 * Universal cURL Fetcher with precise timeouts
 */
function fetchMsn($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Increased for large Sunday slates
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? json_decode($res, true) : null;
}

// 2. STAGE 1: LEAGUE SCOUT (Around the League)
$scoutUrl = "https://api.msn.com/sports/livearoundtheleague?" . http_build_query([
    'apikey'         => $apiKey,
    'version'        => '1.0',
    'cm'             => 'en-us',
    'tzoffset'       => '-7',
    'activityId'     => $activityId,
    'it'             => 'web',
    'user'           => $userToken,
    'scn'            => 'ANON',
    'datetime'       => $currentDateTime,
    'id'             => 'Football_NFL',
    'sport'          => 'Football',
    'withleaguereco' => 'true'
]);

$leagueData = fetchMsn($scoutUrl);
$flatStats = [];
$gamesToFetch = [];
$flatStats["league_slate"] = []; // For the top-of-page box scores

if (isset($leagueData['value'][0]['schedules'])) {
    foreach ($leagueData['value'][0]['schedules'] as $schedule) {
        foreach ($schedule['games'] as $g) {
            $gameStartTime = (float)$g['startDateTime'];
            
            // FILTER: Only process games scheduled for the current day
            if ($gameStartTime >= $todayStart && $gameStartTime < $todayEnd) {
                
                $teamA = $g['participants'][0]['team']['shortName']['rawName'];
                $teamB = $g['participants'][1]['team']['shortName']['rawName'];
                $scoreA = (int)($g['participants'][0]['result']['score'] ?? 0);
                $scoreB = (int)($g['participants'][1]['result']['score'] ?? 0);
                $status = $g['gameState']['gameStatus'] ?? 'Upcoming';

                // Map High-Level Score Data (Moneyline support)
                $flatStats[$teamA] = ['score' => $scoreA, 'opponent_score' => $scoreB];
                $flatStats[$teamB] = ['score' => $scoreB, 'opponent_score' => $scoreA];

                // Map Unique Game Total (e.g., Patriots@Broncos Total)
                $totalKey = "{$teamA}@{$teamB} Total";
                $flatStats[$totalKey] = [
                    'total_points' => $scoreA + $scoreB,
                    'clock' => ($g['gameState']['gameClock']['minutes'] ?? '0') . ':' . ($g['gameState']['gameClock']['seconds'] ?? '00')
                ];

                // Add to the League Slate list for the ticker
                $flatStats["league_slate"][] = [
                    'away'   => $teamA,
                    'home'   => $teamB,
                    'away_s' => $scoreA,
                    'home_s' => $scoreB,
                    'status' => $status,
                    'clock'  => ($g['gameState']['gameClock']['minutes'] ?? '0') . ':' . ($g['gameState']['gameClock']['seconds'] ?? '00')
                ];

                // Queue ID for deep-fetch (player stats)
                $gamesToFetch[] = [
                    'id' => $g['id'],
                    'league' => $g['sportWithLeague'] ?? 'Football_NFL'
                ];
            }
        }
    }
}

// 3. STAGE 2: DEEP STATISTICS (Iterate all games discovered today)
foreach ($gamesToFetch as $game) {
    // Reset variable to ensure URLs don't concatenate
    $deepUrl = "https://api.msn.com/sports/statistics?" . http_build_query([
        'apikey'     => $apiKey,
        'version'    => '1.0',
        'cm'         => 'en-us',
        'activityId' => $activityId,
        'it'         => 'web',
        'user'       => $userToken,
        'scn'        => 'ANON',
        'ids'        => $game['id'],
        'type'       => 'Game',
        'scope'      => 'Playergame',
        'sport'      => 'Football',
        'leagueid'   => $game['league']
    ]);

    $deepData = fetchMsn($deepUrl);

    if (isset($deepData['value'][0]['statistics'])) {
        foreach ($deepData['value'][0]['statistics'] as $statEntry) {
            foreach ($statEntry['teamPlayerStatistics'] as $team) {
                foreach ($team['playerStatistics'] as $p) {
                    $name = $p['player']['name']['rawName'] ?? null;
                    if ($name) {
                        $flatStats[$name] = [
                            'pass_yds'   => $p['passingStatistics']['yards'] ?? 0,
                            'rush_yds'   => $p['rushingStatistics']['yards'] ?? 0,
                            'rec_yds'    => $p['receivingStatistics']['yards'] ?? 0,
                            'receptions' => $p['receivingStatistics']['receptions'] ?? 0,
                            'total_tds'  => ($p['passingStatistics']['touchdowns'] ?? 0) + 
                                            ($p['rushingStatistics']['touchdowns'] ?? 0) + 
                                            ($p['receivingStatistics']['touchdowns'] ?? 0),
                            'updated'    => time()
                        ];
                    }
                }
            }
        }
    }
}

// 4. ATOMIC SAVE
if (!empty($flatStats)) {
    if (!is_dir($stats_dir)) mkdir($stats_dir, 0755, true);
    
    $tmp = $cache_file . '.tmp';
    file_put_contents($tmp, json_encode($flatStats, JSON_PRETTY_PRINT));
    rename($tmp, $cache_file);
    
    echo "Updated " . count($flatStats) . " data points across " . count($gamesToFetch) . " games for today.";
} else {
    echo "No games found for today's date range.";
}