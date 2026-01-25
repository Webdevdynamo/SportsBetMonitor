<?php
/**
 * cron_fetch.php - Multi-Game Crawler & Aggregator
 * PHP 7.4 - Operational Optimization
 */

// 1. CONFIGURATION
$apiKey    = "kO1dI4ptCTTylLkPL1ZTHYP8JhLKb8mRDoA5yotmNJ";
$stats_dir = __DIR__ . '/data';
$cache_file = $stats_dir . '/latest_stats.json';

// League Scout URL
$scoutUrl = "https://api.msn.com/sports/livearoundtheleague?apikey=$apiKey&version=1.0&cm=en-us&tzoffset=-7&it=web&id=Football_NFL&sport=Football";

// Deep Stats Base URL
$statsBaseUrl = "https://api.msn.com/sports/statistics";

/**
 * Perform cURL with precise timeouts
 */
function fetchMsn($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GridironGigaBrains-Crawler/2.0');
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? json_decode($res, true) : null;
}

// 2. SCOUT: Find all games across the league
$leagueData = fetchMsn($scoutUrl);
$flatStats = [];
$activeGameIds = [];
print_r($leagueData);

if (isset($leagueData['value'][0]['schedules'])) {
    foreach ($leagueData['value'][0]['schedules'] as $schedule) {
        foreach ($schedule['games'] as $g) {
            $gameId = $g['id'];
            $status = $g['gameState']['gameStatus'];
            
            // Derive Team Names & Scores (For Moneyline and Specific Game Totals)
            $teamA = $g['participants'][0]['team']['shortName']['rawName'];
            $teamB = $g['participants'][1]['team']['shortName']['rawName'];
            $scoreA = (int)($g['participants'][0]['result']['score'] ?? 0);
            $scoreB = (int)($g['participants'][1]['result']['score'] ?? 0);

            // Map Team Scores
            $flatStats[$teamA] = ['score' => $scoreA, 'opponent_score' => $scoreB];
            $flatStats[$teamB] = ['score' => $scoreB, 'opponent_score' => $scoreA];

            // Map Unique Game Totals (Format: Patriots@Broncos Total)
            $totalKey = "{$teamA}@{$teamB} Total";
            $flatStats[$totalKey] = [
                'total_points' => $scoreA + $scoreB,
                'clock' => $g['gameState']['gameClock']['minutes'] . ':' . $g['gameState']['gameClock']['seconds']
            ];

            // If the game is InProgress or PreGame, queue it for Deep Player Stats
            if ($status === 'InProgress' || $status === 'PreGame') {
                $activeGameIds[] = [
                    'id' => $gameId,
                    'league' => $g['sportWithLeague']
                ];
            }
        }
    }
}

// 3. FETCH: Get Deep Statistics for every active game
foreach ($activeGameIds as $game) {
    $deepUrl = $statsBaseUrl . "?" . http_build_query([
        'apikey'   => $apiKey,
        'version'  => '1.0',
        'cm'       => 'en-us',
        'ids'      => $game['id'],
        'type'     => 'Game',
        'scope'    => 'Playergame',
        'sport'    => 'Football',
        'leagueid' => $game['league']
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
                                            ($p['receivingStatistics']['touchdowns'] ?? 0)
                        ];
                    }
                }
            }
        }
    }
}

// 4. SAVE: Atomic write to avoid JS reading empty file
if (!empty($flatStats)) {
    $tmp_file = $cache_file . '.tmp';
    file_put_contents($tmp_file, json_encode($flatStats));
    rename($tmp_file, $cache_file);
    echo "Updated " . count($flatStats) . " items across " . count($activeGameIds) . " active games.";
}