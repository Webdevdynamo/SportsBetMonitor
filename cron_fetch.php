<?php
/**
 * cron_fetch.php - Multi-Game Crawler & Aggregator
 * PHP 7.4 - Validated MSN API Parameters
 */

// 1. CONFIGURATION
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';

// Static Auth & Environment Params
$apiKey = "kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ";
$userToken = "m-20DC418084A963F00D64576B85AD62D9";

/**
 * Perform cURL with precise timeouts
 */
function fetchMsn($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? json_decode($res, true) : null;
}

// 2. BUILD THE SCOUT URL (Including all required parameters)
// We generate a fresh datetime and activityId to ensure the API provides live data
$currentDateTime = date('Y-m-d\TH:i:s');
$activityId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

$scoutParams = [
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
];

$scoutUrl = "https://api.msn.com/sports/livearoundtheleague?" . http_build_query($scoutParams);


// 3. EXECUTE SCOUT
$leagueData = fetchMsn($scoutUrl);
print_r($leagueData);
$flatStats = [];
$activeGameIds = [];

if (isset($leagueData['value'][0]['schedules'])) {
    foreach ($leagueData['value'][0]['schedules'] as $schedule) {
        foreach ($schedule['games'] as $g) {
            $gameId = $g['id'];
            $status = $g['gameState']['gameStatus'];
            
            // Extract Team Scores
            $teamA = $g['participants'][0]['team']['shortName']['rawName'];
            $teamB = $g['participants'][1]['team']['shortName']['rawName'];
            $scoreA = (int)($g['participants'][0]['result']['score'] ?? 0);
            $scoreB = (int)($g['participants'][1]['result']['score'] ?? 0);

            // Map Team and Multi-Game Total keys
            $flatStats[$teamA] = ['score' => $scoreA, 'opponent_score' => $scoreB];
            $flatStats[$teamB] = ['score' => $scoreB, 'opponent_score' => $scoreA];
            $flatStats["{$teamA}@{$teamB} Total"] = [
                'total_points' => $scoreA + $scoreB,
                'clock' => $g['gameState']['gameClock']['minutes'] . ':' . $g['gameState']['gameClock']['seconds']
            ];

            // Queue deep fetch for active games
            if ($status === 'InProgress' || $status === 'PreGame') {
                $activeGameIds[] = ['id' => $gameId, 'league' => $g['sportWithLeague']];
            }
        }
    }
}

// 4. FETCH DEEP STATS
foreach ($activeGameIds as $game) {
    $statParams = [
        'apikey'   => $apiKey,
        'version'  => '1.0',
        'cm'       => 'en-us',
        'activityId' => $activityId,
        'it'       => 'web',
        'user'     => $userToken,
        'scn'      => 'ANON',
        'ids'      => $game['id'],
        'type'     => 'Game',
        'scope'    => 'Playergame',
        'sport'    => 'Football',
        'leagueid' => $game['league']
    ];

    $deepUrl = "https://api.msn.com/sports/statistics?" . http_build_query($statParams);
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
                                            ($p['receivingStatistics']['touchdowns'] ?? 0)
                        ];
                    }
                }
            }
        }
    }
}

// 5. ATOMIC SAVE
if (!empty($flatStats)) {
    $tmp = $cache_file . '.tmp';
    file_put_contents($tmp, json_encode($flatStats));
    rename($tmp, $cache_file);
}