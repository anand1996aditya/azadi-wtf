<?php
/**
 * cron/health.php — the protest health sweeper for azadi.wtf.
 *
 * One gentle pass over every protest. It reports what is wrong, then repairs what
 * it can. It runs three times a day, offset from the update runs.
 *
 * What it checks and fixes:
 *   - Integrity  : missing id, slug, status, dangerReason, source, lastChecked, trendHistory.
 *   - Trend      : a protest with no history starts one today, at its current level.
 *   - Freshness  : lastChecked older than the level window (critical 1h ... low 24h).
 *   - Dormancy   : no real change for DORMANT_DAYS (7). Severity steps down one level.
 *   - Archive    : dormant, and no change for ARCHIVE_DAYS (14). Moves to archive.json.
 *   - Purge      : archived entries older than ARCHIVE_KEEP_DAYS (30) are deleted.
 *   - Review     : a protest the analyser marked ended is FLAGGED, never auto-closed.
 *
 * Cost: free searches are generous. Paid calls are capped at HEALTH_PAID_CAP per
 * sweep, and the sweep records which provider answered. It never spends blind.
 *
 * Run:
 *   CLI     : php cron/health.php [--dry-run] [--force]
 *   Web     : curl -H "Authorization: Bearer $AZADI_CRON_SECRET" \
 *                  "https://azadi.wtf/cron/health.php?dry=1"
 *             (the secret is never taken from the URL)
 */
$HEALTH_INCLUDED = !empty($GLOBALS['HEALTH_FROM_UPDATE']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/sync_datajs.php';

$GLOBALS['AZADI_LOG'] = 'healthLog';

$isCLI = (php_sapi_name() === 'cli');
$dryRun = false;
$force = false;
if ($isCLI) {
    $dryRun = in_array('--dry-run', $argv ?? [], true);
    $force = in_array('--force', $argv ?? [], true);
} else {
    // When included by cron/update.php the request is already authenticated.
    if (!$HEALTH_INCLUDED) {
        azadi_cron_require_auth();
    }
    $dryRun = !empty($_GET['dry']);
    $force = !empty($_GET['force']);
}

set_time_limit(0);
ignore_user_abort(true);

// ==========================================
// CONSTANTS (defined in config.php if present)
// ==========================================
if (!defined('DORMANT_DAYS'))        define('DORMANT_DAYS', 7);
if (!defined('ARCHIVE_DAYS'))        define('ARCHIVE_DAYS', 14);
if (!defined('ARCHIVE_KEEP_DAYS'))   define('ARCHIVE_KEEP_DAYS', 30);
if (!defined('HEALTH_PAID_CAP'))     define('HEALTH_PAID_CAP', 30);
if (!defined('HEALTH_FREE_CAP'))     define('HEALTH_FREE_CAP', 400);
if (!defined('HEALTH_MIN_GAP_HOURS')) define('HEALTH_MIN_GAP_HOURS', 6);
if (!defined('ARCHIVE_FILE'))        define('ARCHIVE_FILE', DATA_DIR . 'archive.json');
if (!defined('HEALTH_FILE'))         define('HEALTH_FILE', DATA_DIR . 'health.json');
if (!defined('HEALTH_LOG'))          define('HEALTH_LOG', DATA_DIR . 'health.log');
if (!defined('HEALTH_LOCK'))         define('HEALTH_LOCK', DATA_DIR . '.health.lock');

$LEVELS = ['critical', 'high', 'moderate', 'low'];

function healthLog($msg) {
    file_put_contents(HEALTH_LOG, '[' . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND | LOCK_EX);
}

function stepDown($level, array $levels) {
    $i = array_search($level, $levels, true);
    if ($i === false) return $level;
    return $levels[min($i + 1, count($levels) - 1)];
}

function loadJson($path) {
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function saveJson($path, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function citySlugFromName($name) {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// ==========================================
// SINGLE-RUNNER LOCK
// ==========================================
$lock = @fopen(HEALTH_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    healthLog('SKIPPED: another health sweep is running.');
    echo "Skipped: another sweep is running.\n";
    return $HEALTH_INCLUDED ? null : exit(0);
}
ftruncate($lock, 0);
fwrite($lock, (string)getmypid());

// ==========================================
// CADENCE GUARD — three sweeps a day, no more
// ==========================================
$previous = loadJson(HEALTH_FILE);
if (!$force && is_array($previous) && !empty($previous['at'])) {
    $gap = (time() - strtotime($previous['at'])) / 3600;
    if ($gap >= 0 && $gap < HEALTH_MIN_GAP_HOURS) {
        healthLog(sprintf('SKIPPED: only %.1fh since the last sweep (minimum %d).', $gap, HEALTH_MIN_GAP_HOURS));
        if (!$HEALTH_INCLUDED) echo sprintf("Skipped: last sweep was %.1f hours ago.\n", $gap);
        flock($lock, LOCK_UN); fclose($lock);
        return;
    }
}

// Keep update.log bounded. It reached 16 MB with no rotation. (10 Oct 2026)
$ulog = LOG_FILE;
if (file_exists($ulog) && filesize($ulog) > 16 * 1024 * 1024) {
    $fh = fopen($ulog, 'r');
    fseek($fh, -2 * 1024 * 1024, SEEK_END);
    $tail = fread($fh, 2 * 1024 * 1024);
    fclose($fh);
    $nl = strpos($tail, "\n");
    if ($nl !== false) $tail = substr($tail, $nl + 1);
    file_put_contents($ulog, "[log rotated " . date('c') . "]\n" . $tail, LOCK_EX);
    healthLog('  rotated update.log to the last 2 MB');
}

$started = microtime(true);
healthLog('=== HEALTH SWEEP STARTED' . ($dryRun ? ' (dry run)' : '') . ' ===');

$data = loadJson(DATA_FILE);
if (!$data || empty($data['cities'])) {
    healthLog('ERROR: protests.json missing or invalid.');
    flock($lock, LOCK_UN); fclose($lock);
    if ($HEALTH_INCLUDED) return; exit(1);
}

$report = [
    'at'          => date('c'),
    'dryRun'      => $dryRun,
    'cities'      => count($data['cities']),
    'protests'    => 0,
    'issues'      => [],
    'repaired'    => [],
    'dormant'     => 0,
    'archived'    => 0,
    'purged'      => 0,
    'flagged'     => 0,
    'searched'    => 0,
    'paidCalls'   => 0,
    'geoCitiesMissingState' => [],
];

$archive = loadJson(ARCHIVE_FILE);
if (!is_array($archive)) $archive = ['archived' => []];

$searchBudgetFree = HEALTH_FREE_CAP;
$searchBudgetPaid = HEALTH_PAID_CAP;

// Searching is slow, so a web call never does it. The update cron already searches
// every due item; this sweep's own search is only a thin top-up for items the cap
// starved. Structure work (integrity, trend, dormancy, archive, purge) always runs.
$doSearch = $isCLI || !empty($_GET['search']);
if (!$doSearch) { $searchBudgetFree = 0; $searchBudgetPaid = 0; }

// ==========================================
// PASS 1 — integrity, trend, dormancy, review flags
// ==========================================

foreach ($data['cities'] as $citySlug => &$city) {
    $cityName = $city['name'] ?? $citySlug;

    if (empty($city['slug'])) {
        $city['slug'] = citySlugFromName($cityName);
        $report['issues'][] = "city {$cityName}: missing slug";
        if (!$dryRun) $report['repaired'][] = "city {$cityName}: slug set to {$city['slug']}";
    }

    // --- city-level integrity (10 Oct 2026) ---
    // A city must carry a name, a valid overall level, an overall status, and at
    // least one protest. Its overall level must equal the worst level it holds.
    if (empty($city['name'])) {
        $report['issues'][] = "city {$citySlug}: missing name";
        if (!$dryRun) $city['name'] = ucfirst($citySlug);
    }
    $overall = $city['overallDanger'] ?? '';
    if ($overall === '' || !in_array($overall, $LEVELS, true)) {
        $report['issues'][] = "city {$cityName}: invalid overallDanger";
    }
    if (empty($city['overallStatus'])) {
        $report['issues'][] = "city {$cityName}: missing overallStatus";
    }
    if (empty($city['protests'])) {
        $report['issues'][] = "city {$cityName}: shows no protests";
        $report['emptyCities'][] = $cityName;
    } elseif (in_array($overall, $LEVELS, true)) {
        $worst = 'low';
        $worstIdx = array_search($worst, $LEVELS, true);
        foreach ($city['protests'] as $pp) {
            $pi = array_search($pp['dangerLevel'] ?? 'low', $LEVELS, true);
            if ($pi !== false && $pi < $worstIdx) { $worst = $LEVELS[$pi]; $worstIdx = $pi; }
        }
        if ($worst !== $overall) {
            $report['issues'][] = "city {$cityName}: overallDanger={$overall} but worst protest is {$worst}";
            $report['overallMismatch'][] = "{$cityName}: shows {$overall}, worst is {$worst}";
        }
    }

    // A city absent from the site's own map never reaches the map at all.
    if (defined('CITY_TO_STATE') && !isset(CITY_TO_STATE[$citySlug])) {
        $report['geoCitiesMissingState'][] = $cityName . " ({$citySlug})";
    }

    $seenNames = [];
    foreach ($city['protests'] as $idx => &$p) {
        $report['protests']++;
        $pname = $p['name'] ?? "(unnamed #{$idx})";

        if (empty($p['id'])) {
            $p['id'] = citySlugFromName($cityName) . '-' . substr(md5($pname), 0, 12);
            $report['issues'][] = "{$cityName}/{$pname}: missing id";
            if (!$dryRun) $report['repaired'][] = "{$cityName}/{$pname}: id assigned";
        }
        if (empty($p['dangerLevel']) || !in_array($p['dangerLevel'], $LEVELS, true)) {
            $report['issues'][] = "{$cityName}/{$pname}: invalid dangerLevel";
            if (!$dryRun) $p['dangerLevel'] = 'low';
        }
        if (empty($p['status'])) {
            $report['issues'][] = "{$cityName}/{$pname}: missing status";
        }
        if (empty($p['dangerReason'])) {
            $report['issues'][] = "{$cityName}/{$pname}: missing dangerReason";
        }
        if (empty($p['source'])) {
            $report['issues'][] = "{$cityName}/{$pname}: missing source";
            if (!$dryRun) $p['source'] = 'unknown';
        }

        if (empty($p['trendHistory']) || !is_array($p['trendHistory'])) {
            $report['issues'][] = "{$cityName}/{$pname}: no trend history";
            if (!$dryRun) {
                $p['trendHistory'] = [[
                    'date'   => date('c'),
                    'level'  => $p['dangerLevel'] ?? 'low',
                    'status' => mb_substr($p['status'] ?? '', 0, 200),
                ]];
                $report['repaired'][] = "{$cityName}/{$pname}: trend started today";
            }
        }

        if (empty($p['lastChecked'])) {
            $report['issues'][] = "{$cityName}/{$pname}: missing lastChecked";
            if (!$dryRun) $p['lastChecked'] = date('c');
        }

        $lower = strtolower(($p['status'] ?? '') . ' ' . ($p['dangerReason'] ?? ''));
        if (!empty($p['isEnded']) || strpos($lower, 'concluded') !== false || strpos($lower, 'ended') !== false) {
            if (empty($p['reviewNeeded'])) {
                $p['reviewNeeded'] = true;
                $report['flagged']++;
                $report['issues'][] = "{$cityName}/{$pname}: looks concluded — flagged for review, not closed";
                if (!$dryRun) $report['repaired'][] = "{$cityName}/{$pname}: reviewNeeded set";
            }
        }

        $key = strtolower(preg_replace('/[^a-z0-9]+/', '', $pname));
        if ($key !== '' && isset($seenNames[$key])) {
            $p['duplicateOf'] = $seenNames[$key];
            $report['issues'][] = "{$cityName}/{$pname}: duplicate of {$seenNames[$key]}";
        } else {
            $seenNames[$key] = $p['id'] ?? $pname;
        }

        // A protest whose location names a DIFFERENT known city is probably filed
        // under the wrong city. Flag only — never move it automatically. (10 Oct 2026)
        $ownCityLower = strtolower($cityName);
        $locLower = strtolower($p['location'] ?? '');
        if ($locLower !== '' && strpos($locLower, $ownCityLower) === false) {
            foreach ($data['cities'] as $otherCity) {
                $otherName = strtolower($otherCity['name'] ?? '');
                if ($otherName === '' || $otherName === $ownCityLower || mb_strlen($otherName) < 4) continue;
                if (strpos($locLower, $otherName) !== false) {
                    $p['locationMismatch'] = $otherCity['name'];
                    $report['locationMismatches'][] = "{$cityName}/{$pname}: location names {$otherCity['name']}";
                    $report['issues'][] = "{$cityName}/{$pname}: location names {$otherCity['name']}";
                    break;
                }
            }
        }

        $ref = $p['lastUpdated'] ?? ($p['lastChecked'] ?? '');
        $ageDays = $ref ? (time() - strtotime($ref)) / 86400 : 999;

        if ($ageDays >= DORMANT_DAYS) {
            if (empty($p['dormant'])) {
                $p['dormant'] = true;
                $report['dormant']++;
                $report['issues'][] = "{$cityName}/{$pname}: dormant (" . round($ageDays, 1) . " days with no change)";
            }
            if (!$dryRun && $ageDays >= DORMANT_DAYS) {
                $newLevel = stepDown($p['dangerLevel'] ?? 'low', $LEVELS);
                if ($newLevel !== ($p['dangerLevel'] ?? '')) {
                    $p['dangerLevel'] = $newLevel;
                    $report['repaired'][] = "{$cityName}/{$pname}: severity stepped down to {$newLevel}";
                }
            }
            if ($ageDays >= ARCHIVE_DAYS) {
                $p['_archiveCandidate'] = true;
            }
        }
    }
    unset($p);
}
unset($city);

// ==========================================
// PASS 1b — city summary honesty
// ==========================================
// overallStatus often opens with a protest count. After archiving, that count goes
// stale (a city said "4 active protests" while holding 1). Correct the number only.
foreach ($data['cities'] as $citySlug => &$city) {
    $cityName = $city['name'] ?? $citySlug;
    $real = count($city['protests'] ?? []);
    if (!empty($city['overallStatus'])
        && preg_match('/^\s*(\d+)(\s+(?:active\s+)?protest)/i', $city['overallStatus'], $m)) {
        if ((int)$m[1] !== $real) {
            if (!$dryRun) {
                $city['overallStatus'] = preg_replace('/^\s*\d+/', (string)$real, $city['overallStatus'], 1);
                $report['repaired'][] = "{$cityName}: overallStatus count {$m[1]} -> {$real}";
            }
            $report['issues'][] = "{$cityName}: overallStatus count {$m[1]} but holds {$real}";
        }
    }
    if ($real > 0 && empty($city['overallStatus'])) {
        $report['issues'][] = "{$cityName}: missing overallStatus";
    }
}
unset($city);

// ==========================================
// PASS 2 — freshness search, inside both budgets
// ==========================================
if ($doSearch) {
foreach ($data['cities'] as $citySlug => &$city) {
    $cityName = $city['name'] ?? $citySlug;
    foreach ($city['protests'] as $idx => &$p) {
        if (!empty($p['dormant']) || !empty($p['_archiveCandidate'])) continue;

        $level = $p['dangerLevel'] ?? 'low';
        $window = getIntervalForLevel($level);
        $checked = $p['lastChecked'] ?? '';
        $age = $checked ? (time() - strtotime($checked)) : PHP_INT_MAX;
        if ($age < $window) continue;

        if ($searchBudgetFree <= 0 && $searchBudgetPaid <= 0) {
            $report['issues'][] = "budget reached — {$cityName}/{$p['name']} not re-checked";
            continue;
        }

        $query = "{$cityName} {$p['name']} protest news latest 2026";
        try {
            $r = searchWeb($query);
        } catch (Exception $e) {
            healthLog("  search failed for {$p['name']}: " . $e->getMessage());
            continue;
        }
        $source = $r['source'] ?? 'none';
        if ($source === 'tinyfish') {
            $searchBudgetFree--;
        } else {
            $searchBudgetPaid--;
            $report['paidCalls']++;
            healthLog("  PAID CALL (source={$source}) for {$p['name']}");
        }
        $report['searched']++;

        if (!$dryRun) {
            $p['lastChecked'] = date('c');
            if (!empty($r['headlines']) || !empty($r['answer'])) {
                $hash = md5(json_encode($r['headlines'] ?? []) . ($r['answer'] ?? ''));
                if (($p['lastSearchHash'] ?? '') !== $hash) {
                    $p['lastSearchHash'] = $hash;
                    $p['lastUpdated'] = date('c');
                    $p['source'] = $source;
                    $p['dormant'] = false;
                }
            }
        }
        usleep(400000);
    }
    unset($p);
}
unset($city);
}

// ==========================================
// PASS 3 — archive candidates, then purge
// ==========================================
foreach ($data['cities'] as $citySlug => &$city) {
    if (empty($city['protests'])) continue;
    $kept = [];
    $seenIds = [];
    foreach ($city['protests'] as $p) {
        $pid = $p['id'] ?? '';
        if ($pid !== '' && isset($seenIds[$pid])) {
            $report['duplicatesRemoved']++;
            $report['repaired'][] = "removed duplicate id {$pid} ({$p['name']})";
            if (!$dryRun) continue;
        }
        if ($pid !== '') $seenIds[$pid] = true;
        if (!empty($p['_archiveCandidate']) && !$dryRun) {
            unset($p['_archiveCandidate']);
            $p['archivedAt'] = date('c');
            $p['archivedFromCity'] = $city['name'] ?? $citySlug;
            $archive['archived'][] = $p;
            $report['archived']++;
            $report['repaired'][] = "archived {$p['name']} ({$city['name']})";
        } else {
            unset($p['_archiveCandidate']);
            $kept[] = $p;
        }
    }
    $city['protests'] = $kept;
}
unset($city);

// Recount from the final data — PASS 3 removed duplicates and archived items.
$report['protests'] = 0;
foreach ($data['cities'] as $rc) { $report['protests'] += count($rc['protests'] ?? []); }

$before = count($archive['archived']);
$archive['archived'] = array_values(array_filter($archive['archived'], function ($a) {
    $at = $a['archivedAt'] ?? '';
    return $at && (time() - strtotime($at)) / 86400 < ARCHIVE_KEEP_DAYS;
}));
$report['purged'] = $before - count($archive['archived']);

// ==========================================
// SAVE
// ==========================================
$report['duration'] = round(microtime(true) - $started, 1);
$report['archiveSize'] = count($archive['archived']);
$report['ok'] = (count($report['geoCitiesMissingState']) === 0);

if (!$dryRun) {
    $data['refresh']['healthAt'] = date('c');
    saveJson(DATA_FILE, $data);
    saveJson(ARCHIVE_FILE, $archive);
    // Rebuild the map data now, so archived protests leave the map immediately
    // instead of waiting for the next update run.
    try { syncDataJs(); healthLog('  data.js resynced'); }
    catch (Exception $e) { healthLog('  data.js resync failed: ' . $e->getMessage()); }
    saveJson(HEALTH_FILE, $report);
}

healthLog(sprintf(
    'DONE in %.1fs — %d protests, %d issues, %d repaired, %d dormant, %d archived, %d purged, %d flagged, %d searched (%d paid)',
    $report['duration'], $report['protests'], count($report['issues']), count($report['repaired']),
    $report['dormant'], $report['archived'], $report['purged'], $report['flagged'],
    $report['searched'], $report['paidCalls']
));
healthLog('=== HEALTH SWEEP COMPLETE ===' . "\n");

if (!$HEALTH_INCLUDED) echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

flock($lock, LOCK_UN);
fclose($lock);
