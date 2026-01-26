<?php
/**
 * Gridiron Giga-Brains: Multi-Game All-Day Crawler
 * PHP 7.4 - Operational Optimization with gameStatus & D/ST Tracking
 */

// 1. CONFIGURATION & SESSION SETUP
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';
$apiKey = "kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ";
$userToken = "m-20DC418084A963F00D64576B85AD62D9";
$activityId = md5(date('Y-m-d') . $userToken); 
$currentDateTime = date('Y-m-d\TH:i:s');
$todayStart = strtotime('today') * 1000;
$todayEnd   = strtotime('tomorrow') * 1000;

function fetchMsn($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? json_decode($res, true) : null;
}

// 2. STAGE 1: LEAGUE SCOUT
$scoutUrl = "https://api.msn.com/sports/livearoundtheleague?" . http_build_query([
    'apikey' => $apiKey, 'version' => '1.0', 'cm' => 'en-us', 'tzoffset' => '-7',
    'activityId' => $activityId, 'it' => 'web', 'user' => $userToken, 'scn' => 'ANON',
    'datetime' => $currentDateTime, 'id' => 'Football_NFL', 'sport' => 'Football', 'withleaguereco' => 'true'
]);

$leagueData = fetchMsn($scoutUrl);
$flatStats = [];
$gamesToFetch = [];
$flatStats["league_slate"] = [];

if (isset($leagueData['value'][0]['schedules'])) {
    foreach ($leagueData['value'][0]['schedules'] as $schedule) {
        foreach ($schedule['games'] as $g) {
            $gameStartTime = (float)$g['startDateTime'];
            if ($gameStartTime >= $todayStart && $gameStartTime < $todayEnd) {
                $status = $g['gameState']['gameStatus'] ?? 'Upcoming';
                $teamA = $g['participants'][0]['team']['shortName']['rawName'];
                $teamB = $g['participants'][1]['team']['shortName']['rawName'];
                print_r($g['participants'][0]['id']);
                $teamMap[$g['participants'][0]['id']] = $teamA;
                $teamMap[$g['participants'][1]['id']] = $teamB;
                $scoreA = (int)($g['participants'][0]['result']['score'] ?? 0);
                $scoreB = (int)($g['participants'][1]['result']['score'] ?? 0);

                // Store gameStatus in team and total objects
                $flatStats[$teamA] = ['score' => $scoreA, 'opponent_score' => $scoreB, 'gameStatus' => $status];
                $flatStats[$teamB] = ['score' => $scoreB, 'opponent_score' => $scoreA, 'gameStatus' => $status];
                
                $totalKey = "{$teamA}@{$teamB} Total";
                $flatStats[$totalKey] = [
                    'total_points' => $scoreA + $scoreB,
                    'gameStatus' => $status,
                    'clock' => ($g['gameState']['gameClock']['minutes'] ?? '0') . ':' . ($g['gameState']['gameClock']['seconds'] ?? '00')
                ];

                $flatStats["league_slate"][] = [
                    'away' => $teamA, 'home' => $teamB, 'away_s' => $scoreA, 'home_s' => $scoreB,
                    'status' => $status, 'clock' => $flatStats[$totalKey]['clock']
                ];

                $gamesToFetch[] = ['id' => $g['id'], 'league' => $g['sportWithLeague'] ?? 'Football_NFL', 'status' => $status];
            }
        }
    }
}

// 3. STAGE 2: DEEP STATISTICS (PLAYER + D/ST)
foreach ($gamesToFetch as $game) {
    $deepUrl = "https://api.msn.com/sports/statistics?" . http_build_query([
        'apikey' => $apiKey, 'version' => '1.0', 'cm' => 'en-us', 'activityId' => $activityId,
        'it' => 'web', 'user' => $userToken, 'scn' => 'ANON', 'ids' => $game['id'],
        'type' => 'Game', 'scope' => 'Playergame', 'sport' => 'Football', 'leagueid' => $game['league']
    ]);

    $deepData = fetchMsn($deepUrl);

    if (isset($deepData['value'][0]['statistics'])) {
        foreach ($deepData['value'][0]['statistics'] as $statEntry) {
            
            // --- D/ST TOUCHDOWN LOGIC ---
            if (isset($statEntry['teamStatistics'])) {
                foreach ($statEntry['teamStatistics'] as $tStat) {
                    $teamRaw = $tStat['team']['shortName']['rawName'];
                    $dstKey = $teamRaw . " D/ST";
                    // Summing Interception and Fumble recovery TDs
                    $defTDs = ($tStat['defensiveStatistics']['interceptionTouchdowns'] ?? 0) + 
                             ($tStat['defensiveStatistics']['fumbleRecoveryTouchdowns'] ?? 0);

                    $flatStats[$dstKey] = [
                        'total_tds' => $defTDs,
                        'gameStatus' => $game['status']
                    ];
                }
            }

            // --- PLAYER STATISTICS ---
            foreach ($statEntry['teamPlayerStatistics'] as $team) {
                // print_r($teamMap);
                print_r($team['teamId']);
                // Get the common name from the map we built in Stage 1
                 $currentTeamName = $teamMap[$team['teamId']] ?? "Unknown";
                foreach ($team['playerStatistics'] as $p) {
                    $name = $p['player']['name']['rawName'] ?? null;
                    if ($name) {
                        $flatStats[$name] = [
                            'team' => $currentTeamName, // NEW: Store the team name
                            'gameStatus' => $game['status'], 
                            'pass_yds' => $p['passingStatistics']['yards'] ?? 0,
                            'rush_yds' => $p['rushingStatistics']['yards'] ?? 0,
                            'rec_yds' => $p['receivingStatistics']['yards'] ?? 0,
                            'receptions' => $p['receivingStatistics']['receptions'] ?? 0,
                            'total_tds' => ($p['passingStatistics']['touchdowns'] ?? 0) + 
                                           ($p['rushingStatistics']['touchdowns'] ?? 0) + 
                                           ($p['receivingStatistics']['touchdowns'] ?? 0)
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
}