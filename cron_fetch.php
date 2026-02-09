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
                $aliasA = $g['participants'][0]['team']['alias'];
                $aliasB = $g['participants'][1]['team']['alias'];
                // echo "<pre>THIS IS THE ID:";print_r($g['participants'][0]);
                $teamMap[$g['participants'][0]['team']['id']]['alias'] = $aliasA;
                $teamMap[$g['participants'][1]['team']['id']]['alias'] = $aliasB;
                $teamMap[$g['participants'][0]['team']['id']]['name'] = $teamA;
                $teamMap[$g['participants'][1]['team']['id']]['name'] = $teamB;
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
            
            // --- PLAYER & D/ST CUMULATIVE STATISTICS ---
            foreach ($statEntry['teamPlayerStatistics'] as $team) {
                $currentTeamName = $teamMap[$team['teamId']]['name'] ?? "Unknown";
                $currentTeamAlias = $teamMap[$team['teamId']]['alias'] ?? "Unknown";
                $dstKey = $currentTeamName . " D/ST";

                // Initialize D/ST entry for this team if not already set
                if (!isset($flatStats[$dstKey])) {
                    $flatStats[$dstKey] = [
                        'team' => $currentTeamName,
                        'alias' => $currentTeamAlias,
                        'gameStatus' => $game['status'],
                        'tackles' => 0,
                        'sacks' => 0,
                        'interceptions' => 0,
                        'fumbles_forced' => 0,
                        'fumble_recoveries' => 0,
                        'total_tds' => 0,
                        'passes_defended' => 0,
                        'safeties' => 0
                    ];
                }

                foreach ($team['playerStatistics'] as $p) {
                    $name = $p['player']['name']['rawName'] ?? null;
                    
                    // --- NEW: EXPLICIT STAT BLOCK CAPTURE ---
                    $pass = $p['passingStatistics'] ?? []; // CRITICAL: Defines $pass
                    $def  = $p['defenseStatistics'] ?? [];
                    $rush = $p['rushingStatistics'] ?? [];
                    $rec  = $p['receivingStatistics'] ?? [];

                    if ($name) {
                        // 1. Update Individual Player Stats
                        // Track both thrown (QB) and caught (DB) picks
                        $actualInterceptions = ($pass['interceptions'] ?? 0) + ($def['interceptions'] ?? 0);

                        $flatStats[$name] = [
                            'team' => $currentTeamName,
                            'gameStatus' => $game['status'], 
                            'pass_yds' => $pass['yards'] ?? 0,
                            'rush_yds' => $rush['yards'] ?? 0,
                            'rec_yds' => $rec['yards'] ?? 0,
                            'receptions' => $rec['receptions'] ?? 0,
                            'interceptions' => $actualInterceptions, 
                            'total_tds' => ($pass['touchdowns'] ?? 0) + 
                                           ($rush['touchdowns'] ?? 0) + 
                                           ($rec['touchdowns'] ?? 0) +
                                           ($def['touchdowns'] ?? 0)
                        ];

                        // 2. Aggregate Player Defense Data into Team D/ST
                        if (!empty($def)) {
                            $flatStats[$dstKey]['tackles'] += ($def['totalTackles'] ?? 0);
                            $flatStats[$dstKey]['sacks'] += ($def['sacks'] ?? 0);
                            $flatStats[$dstKey]['interceptions'] += ($def['interceptions'] ?? 0);
                            $flatStats[$dstKey]['fumbles_forced'] += ($def['fumblesForced'] ?? 0);
                            $flatStats[$dstKey]['fumble_recoveries'] += ($def['fumbleRecoveries'] ?? 0);
                            $flatStats[$dstKey]['total_tds'] += ($def['touchdowns'] ?? 0);
                            $flatStats[$dstKey]['passes_defended'] += ($def['passesDefended'] ?? 0);
                            $flatStats[$dstKey]['safeties'] += ($def['safeties'] ?? 0);
                        }
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