<?php
/**
 * Auto-Update Script — Azadi.wtf
 *
 * Fetches latest protest news via Tavily Search API,
 * analyzes findings with DeepSeek, and updates protests.json.
 *
 * Called by cron at intervals based on danger level:
 *   Critical: every hour
 *   High:     every 3 hours
 *   Moderate: every 12 hours
 *   Low:      every 24 hours
 *
 * Manual test (URL):  /cron/update.php?secret=CRON_SECRET&level=critical
 * CLI (cron):         php cron/update.php critical
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sync_datajs.php';
require_once __DIR__ . '/search.php';

// Route shared search.php logs into update.log
$GLOBALS['AZADI_LOG'] = 'logUpdate';

// Phase C: staleness thresholds (days)
if (!defined('MAX_ESCALATION_AGE_DAYS')) define('MAX_ESCALATION_AGE_DAYS', 7);
if (!defined('DORMANT_ARCHIVE_DAYS')) define('DORMANT_ARCHIVE_DAYS', 7);

// Allow long-running cron runs (web-triggered or CLI)
set_time_limit(0);
ignore_user_abort(true);

// ==========================================
// AUTHENTICATION
// ==========================================

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    $providedSecret = $_GET['secret'] ?? '';
    if ($providedSecret !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized');
    }
}

// Accept comma-separated levels: "critical,high" or "moderate,low"
$rawLevel = $_GET['level'] ?? ($argv[1] ?? null);
$filterLevels = $rawLevel ? array_map('trim', explode(',', $rawLevel)) : null;

// Accept comma-separated city slugs: "delhi,mumbai"
$rawCity = $_GET['city'] ?? ($argv[2] ?? null);
$filterCities = $rawCity ? array_map('trim', explode(',', $rawCity)) : null;

// ==========================================
// MAIN
// ==========================================

$filterLabel = ($filterLevels ? implode(',', $filterLevels) : 'all') . ' | ' . ($filterCities ? implode(',', $filterCities) : 'all cities');
logUpdate("=== UPDATE RUN STARTED (filter: {$filterLabel}) ===");

$data = loadData();
if (!$data) {
    logUpdate("ERROR: Failed to load protests.json");
    die("Failed to load data.\n");
}

$updated = 0;
$skipped = 0;
$errors = 0;
$noNewInfo = 0;
$demoted = 0;
$tavilySuccessCount = 0;

foreach ($data['cities'] as $citySlug => &$city) {
    // If filtering by cities, skip non-matching
    if ($filterCities && !in_array($citySlug, $filterCities, true)) {
        continue;
    }

    foreach ($city['protests'] as &$protest) {
        $level = $protest['dangerLevel'];

        // If filtering by levels, skip non-matching
        if ($filterLevels && !in_array($level, $filterLevels, true)) {
            $skipped++;
            continue;
        }

        if (!needsUpdate($protest, $level)) {
            $skipped++;
            continue;
        }

        logUpdate("Updating: {$city['name']} — {$protest['name']} [{$level}]");

        try {
            // Step 1: Search for latest news (TinyFish → Tavily → Firecrawl → SerpAI)
            $searchResults = searchWeb("{$city['name']} {$protest['name']} protest news latest 2026");
            if (!empty($searchResults['headlines']) || !empty($searchResults['answer'])) {
                $tavilySuccessCount++;
            }
            $headlineCount = count($searchResults['headlines'] ?? []);
            logUpdate("  Search returned {$headlineCount} results" . ($searchResults['answer'] ? ' + AI summary' : ''));

            // Mark as checked regardless of outcome (drives re-check cadence)
            $protest['lastChecked'] = date('c');
            // Source attribution — which search API provided this data
            $protest['source'] = $searchResults['source'] ?? 'unknown';

            // Phase A: skip DeepSeek if there is no *new* info (empty OR unchanged results).
            // Saves tokens — a dying protest with static/empty news is not re-analyzed.
            $searchHash = computeSearchHash($searchResults);
            $hasNewInfo = (!empty($searchResults['headlines']) || !empty($searchResults['answer']))
                && (empty($protest['lastSearchHash']) || $protest['lastSearchHash'] !== $searchHash);

            if (!$hasNewInfo) {
                $noNewInfo++;
                logUpdate("  No new info (empty or unchanged search) — skipping analysis");
                usleep(500000);
                continue;
            }
            $protest['lastSearchHash'] = $searchHash;

            // Step 2: Analyze with DeepSeek
            $analysis = analyzeWithDeepSeek($city['name'], $protest, $searchResults);

            if ($analysis) {
                $oldLevel = $protest['dangerLevel'];
                
                // Track trend history — save BEFORE applying new analysis
                if (!isset($protest['trendHistory'])) $protest['trendHistory'] = [];
                $lastEntry = end($protest['trendHistory']);
                $newLevel = $analysis['dangerLevel'] ?? $oldLevel;
                $shouldAdd = !$lastEntry || 
                    $lastEntry['level'] !== $oldLevel ||
                    (time() - strtotime($lastEntry['date'])) > 21600;
                if ($shouldAdd) {
                    $protest['trendHistory'][] = [
                        'date' => date('c'),
                        'level' => $oldLevel,
                        'status' => substr($protest['status'], 0, 200)
                    ];
                    if (count($protest['trendHistory']) > 60) {
                        $protest['trendHistory'] = array_slice($protest['trendHistory'], -60);
                    }
                }
                
                // Phase D: only commit level changes confirmed across 2 runs
                $analysis = consensusCheck($protest, $analysis);
                $protest = applyAnalysis($protest, $analysis);
                $updated++;
                $levelChange = ($oldLevel !== $protest['dangerLevel']) ? " [{$oldLevel} -> {$protest['dangerLevel']}]" : "";
                logUpdate("  Updated: {$protest['name']} — danger: {$protest['dangerLevel']}{$levelChange}");
            } else {
                $errors++;
                logUpdate("  ERROR: DeepSeek analysis returned null for {$protest['name']}");
            }

            // Rate limit: 0.5s between API calls
            usleep(500000);
        } catch (Exception $e) {
            $errors++;
            logUpdate("  EXCEPTION: {$protest['name']} — " . $e->getMessage());
        }
    }
}

// Recalculate city-level overviews
// Track Tavily health — don't blind-archive if news source is unavailable
// With SerpAI fallback, we're covered even when Tavily dies
$tavilyAvailable = ($tavilySuccessCount > 0);
if (!$tavilyAvailable) {
    logUpdate("  No successful search this run (sources down or nothing needed update) — skipping archive/discovery");
}

foreach ($data['cities'] as $citySlug => &$city) {
    // Skip non-matching cities (same filter as main update loop)
    if ($filterCities && !in_array($citySlug, $filterCities, true)) {
        continue;
    }

    // Auto-remove concluded protests — ONLY if Tavily is available (news source working)
    if ($tavilyAvailable) {
        $activeProtests = [];
        $removed = 0;
        foreach ($city['protests'] as $protest) {
            // Primary: explicit DeepSeek signal
            $isEnded = !empty($protest['isEnded']);
            if (!$isEnded) {
                // Fallback: strong "ended" wording in status, and not critical/high
                $status = strtolower($protest['status'] ?? '');
                $level = $protest['dangerLevel'] ?? '';
                $strongEnd = ['officially ended','has ended','called off','concluded',
                              'disbanded','wound down','wrapped up','formally accepted all demands',
                              'no longer active','sit-in has ended','protest has ended'];
                foreach ($strongEnd as $word) {
                    if (strpos($status, $word) !== false && in_array($level, ['low','moderate'])) {
                        $isEnded = true;
                        break;
                    }
                }
            }
            if ($isEnded) {
                $removed++;
                logUpdate("  Archived: {$city['name']} — {$protest['name']} (concluded)");
                continue;
            }

            // Staleness cap — critical/high with no fresh analysis for too long → downgrade
            $lv = $protest['dangerLevel'] ?? 'low';
            if (in_array($lv, ['critical', 'high']) && !empty($protest['lastUpdated'])) {
                $ageDays = round((time() - strtotime($protest['lastUpdated'])) / 86400);
                if ($ageDays > MAX_ESCALATION_AGE_DAYS) {
                    $protest['dangerLevel'] = 'moderate';
                    $demoted++;
                    logUpdate("  Staleness cap: {$city['name']} — {$protest['name']} downgraded {$lv} → moderate ({$ageDays}d no fresh news)");
                }
            }

            // Dormant archive — low with no fresh news for too long → remove
            if (($protest['dangerLevel'] ?? 'low') === 'low' && !empty($protest['lastUpdated'])) {
                $ageDays = round((time() - strtotime($protest['lastUpdated'])) / 86400);
                if ($ageDays > DORMANT_ARCHIVE_DAYS) {
                    $removed++;
                    logUpdate("  Archived: {$city['name']} — {$protest['name']} (dormant, {$ageDays}d)");
                    continue;
                }
            }

            $activeProtests[] = $protest;
        }
        if ($removed > 0) {
            $city['protests'] = $activeProtests;
            logUpdate("  Removed $removed concluded protests from {$city['name']}");
        }
    }

    // DISCOVER NEW PROTESTS — only if Tavily is available
    if ($tavilyAvailable) {
        discoverNewProtests($city, $citySlug, $city['name']);
    }
    
    $city['overallDanger'] = calculateOverallDanger($city['protests']);
    $city['overallStatus'] = generateOverallStatus($city['name'], $city['protests']);
}

// Save
$data['lastUpdated'] = date('c');
if (saveData($data)) {
    logUpdate("SAVED: {$updated} updated, {$skipped} skipped, {$noNewInfo} no-new-info, {$demoted} demoted, {$errors} errors");
    // Phase E: staleness summary — flag silently-stale protests
    $staleCount = 0;
    foreach ($data['cities'] as $sc) {
        foreach ($sc['protests'] as $sp) {
            if (!empty($sp['lastUpdated']) && (time() - strtotime($sp['lastUpdated'])) / 86400 > MAX_ESCALATION_AGE_DAYS) {
                $staleCount++;
            }
        }
    }
    if ($staleCount > 0) logUpdate("  STALE: {$staleCount} protest(s) have no fresh data in >" . MAX_ESCALATION_AGE_DAYS . " days");
    // Sync fresh data to data.js for homepage map
    $synced = syncDataJs();
} else {
    logUpdate("ERROR: Failed to write protests.json — check file permissions");
}

logUpdate("=== UPDATE RUN COMPLETE ===\n");
echo "Done. Updated: {$updated}, Skipped: {$skipped}, No-new-info: {$noNewInfo}, Errors: {$errors}\n";

// ==========================================
// DATA HELPERS
// ==========================================

function loadData() {
    if (!file_exists(DATA_FILE)) return null;
    return json_decode(file_get_contents(DATA_FILE), true);
}

function saveData($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents(DATA_FILE, $json, LOCK_EX) !== false;
}

function needsUpdate($protest, $level) {
    // Use lastChecked (when we last *searched*) for the re-check cadence;
    // fall back to lastUpdated for legacy data.
    $checkTime = $protest['lastChecked'] ?? ($protest['lastUpdated'] ?? '');
    if (empty($checkTime)) return true;
    $lastUpdate = strtotime($checkTime);
    if ($lastUpdate === false) return true;
    $interval = getIntervalForLevel($level);
    $elapsed = time() - $lastUpdate;
    // If server clock is behind (negative elapsed), force update
    if ($elapsed < 0) return true;
    return $elapsed >= ($interval - 60);
}

/**
 * Deterministic hash of search results — used to detect "nothing changed".
 */
function computeSearchHash($searchResults) {
    $keys = [];
    foreach ($searchResults['headlines'] ?? [] as $h) {
        $keys[] = trim($h['url'] ?? '') . '|' . trim($h['title'] ?? '');
    }
    if (!empty($searchResults['answer'])) {
        $keys[] = 'answer:' . trim($searchResults['answer']);
    }
    return md5(implode("\n", $keys));
}

// ==========================================
// NAME NORMALIZATION + DEDUP (code-level, no LLM reliance)
// ==========================================

/**
 * Normalize a protest name for comparison: lowercase, strip punctuation, collapse whitespace.
 */
function normalizeProtestName($name) {
    $n = strtolower(trim((string)$name));
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
    return trim(preg_replace('/\s+/', ' ', $n));
}

/**
 * True if $name duplicates any name in $existingNames (exact match or >=85% fuzzy).
 */
function isDuplicateProtestName($name, array $existingNames) {
    $n = normalizeProtestName($name);
    if ($n === '') return false;
    foreach ($existingNames as $existing) {
        $e = normalizeProtestName($existing);
        if ($e === '') continue;
        if ($n === $e) return true;
        similar_text($n, $e, $pct);
        if ($pct >= 85) return true;
    }
    return false;
}

// ==========================================
// DEEPSEEK ANALYSIS
// ==========================================

function analyzeWithDeepSeek($cityName, $protest, $searchResults) {
    $answer = $searchResults['answer'] ?? '';
    $headlines = $searchResults['headlines'] ?? [];

    // Build a structured summary of search results for DeepSeek
    $searchSummary = '';
    if ($answer) {
        $searchSummary .= "TAVILY AI SUMMARY:\n{$answer}\n\n";
    }
    $searchSummary .= "SEARCH RESULTS:\n";
    foreach ($headlines as $i => $h) {
        $searchSummary .= ($i + 1) . ". {$h['title']}\n";
        $searchSummary .= "   " . substr($h['content'], 0, 300) . "\n";
        $searchSummary .= "   Relevance: " . round($h['score'] * 100) . "%\n\n";
    }

    if (empty(trim($searchSummary))) {
        $searchSummary = "No recent search results found. The situation may be unchanged or under-reported.";
    }

    // Tailor detail depth by current severity: high/critical get richer detail,
    // moderate/low stay concise. (Uses PREVIOUS assessment level.)
    $currentLevel = $protest['dangerLevel'] ?? 'moderate';
    $isHighRisk = in_array($currentLevel, ['critical', 'high'], true);

    if ($isHighRisk) {
        $statusNote = '5-8 sentences (minimum 500 characters)';
        $reasonNote = '4-5 detailed sentences (minimum 250 characters)';
        $detNote   = '150-300 chars each';
    } else {
        $statusNote = '3-5 sentences (minimum 300 characters)';
        $reasonNote = '2-3 detailed sentences (minimum 150 characters)';
        $detNote   = '80-160 chars each';
    }

    $systemPrompt = <<<PROMPT
You are a protest safety analyst for India. Your job is to analyze the latest news and search results about a specific protest, then update its safety assessment in structured JSON.

OUTPUT ONLY THIS JSON FORMAT:
{
  "status": "A THOROUGH journalistic update ({$statusNote}). Include: how many people participating, where exactly gathered, what happened most recently with dates, police/government posture, what protestors plan next. Be specific and factual.",
  "dangerLevel": "critical|high|moderate|low",
  "dangerReason": "Detailed sentences explaining WHY this danger level was assigned ({$reasonNote}). Reference specific evidence: police numbers, injuries, arrests, clashes, weapons, roadblocks, government response. Include dates and locations.",
  "isEnded": false,
  "recencyDays": 0,
  "confidence": "medium",
  "details": [
    "Police presence: [Exact locations, numbers deployed, equipment like barricades/drones/water cannons, road closures — {$detNote}]",
    "Recent incidents: [Specific events with dates, injuries, arrests, property damage, who did what — {$detNote}]",
    "Medical access: [Which hospitals nearby, ambulances on site, injuries reported, accessibility concerns — {$detNote}]",
    "Legal support: [Organizations/lawyers involved, helplines if available, legal status of detained protesters, court interventions — {$detNote}]"
  ]
}

DANGER LEVEL DEFINITIONS:
- CRITICAL: Police violence (lathi charges, water cannons, tear gas), mass detentions, medical access blocked, Section 144/163 imposed with active enforcement. Protester injuries confirmed.
- HIGH: Some detentions, elevated police presence, area restrictions imposed, tense standoffs. No mass violence but potential for escalation.
- MODERATE: Police present but restrained, minor incidents, generally peaceful. Some restrictions or identity checks but no violence.
- LOW: Peaceful, no incidents, police presence minimal or administrative only. Protests well-organized with cooperative atmosphere.

CRITICAL RULES:
- NEVER fabricate incidents, violence, numbers, or dates. If search results don't mention violence, do NOT invent it.
- ABSOLUTELY NO HALLUCINATION: if you genuinely lack verified information, it is BETTER to provide FEWER/shorter lines (e.g. 3-4 sentences instead of the requested minimum) than to invent or pad detail. Shorter but accurate always beats longer but fabricated. Never speculate, extrapolate, or fill gaps with assumptions.
- Set "isEnded": true ONLY if the search/news clearly confirms the protest has CONCLUDED — it ended, was called off, its demands were fully met and the gathering dispersed, or organisers formally announced it is over. Otherwise keep "isEnded": false.
- Set "recencyDays": the number of days since the MOST RECENT confirmed incident/escalation mentioned in the news (0 = today, 1 = yesterday). If the news only contains old/historical events, use a larger number honestly. Never set it to 0 just to look current.
- Set "confidence": "low" | "medium" | "high" — how confident you are in this assessment. Use "low" if news is sparse/old/stale, "high" if recent and detailed.
- If search shows clear escalation (new reports of violence, detentions, restrictions), RAISE the danger level with justification.
- If search shows clear de-escalation (protests ending, situation calming), you may LOWER the level with justification.
- If search results are sparse, be measured and note the uncertainty.
- Output ONLY the JSON object. No markdown, no explanation, no code fences.
PROMPT;

    $userPrompt = <<<PROMPT
PROTEST TO ANALYZE:
City: {$cityName}
Protest Name: {$protest['name']}
Location: {$protest['location']}
Cause: {$protest['cause']}

PREVIOUS ASSESSMENT:
Danger Level: {$protest['dangerLevel']}
Status: {$protest['status']}
Risk Assessment: {$protest['dangerReason']}
Last Updated: {$protest['lastUpdated']}

CURRENT SEARCH INTELLIGENCE:
{$searchSummary}

Based on the above search intelligence, provide an updated assessment in the specified JSON format.
PROMPT;

    // Phase B: call DeepSeek, retry once on failure, then deterministic reconcile
    $analysis = deepSeekChat($systemPrompt, $userPrompt);
    $analysis = ($analysis !== null) ? reconcileAnalysis($analysis) : null;

    if ($analysis === null) {
        logUpdate("  DeepSeek returned invalid output — retrying once with repair prompt");
        $analysis = deepSeekChat($systemPrompt, $userPrompt . "\n\nIMPORTANT: Output ONLY the valid JSON object — no markdown, no code fences, no extra text.");
        $analysis = ($analysis !== null) ? reconcileAnalysis($analysis) : null;
    }
    if ($analysis === null) return null;

    return $analysis;
}

// ==========================================
// PHASE B: DEEPSEEK CALL + DETERMINISTIC VALIDATION
// ==========================================

/**
 * Make a single DeepSeek chat-completions call and return the parsed JSON array,
 * or null on any failure (HTTP error, malformed response, or unparseable JSON).
 */
function deepSeekChat($systemPrompt, $userPrompt) {
    $payload = [
        'model'       => DEEPSEEK_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt]
        ],
        'temperature' => 0.3,
        'max_tokens'  => 8000
    ];

    $ch = curl_init(DEEPSEEK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logUpdate("  DeepSeek cURL error: {$curlError}");
        return null;
    }
    if ($httpCode !== 200) {
        logUpdate("  DeepSeek HTTP {$httpCode}: " . substr($response, 0, 300));
        return null;
    }

    $decoded = json_decode($response, true);
    if (!$decoded || !isset($decoded['choices'][0]['message']['content'])) {
        logUpdate("  DeepSeek unexpected response format");
        return null;
    }

    $content = $decoded['choices'][0]['message']['content'];

    // Extract JSON from response (strip code fences, fallback to first {...})
    $json = trim($content);
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $matches)) {
        $json = trim($matches[1]);
    }
    $analysis = json_decode($json, true);
    if (!$analysis && preg_match('/\{.*\}/s', $json, $m)) {
        $analysis = json_decode($m[0], true);
    }
    if (!$analysis) {
        logUpdate("  Failed to parse DeepSeek response as JSON. Raw: " . substr($content, 0, 200));
        return null;
    }
    return $analysis;
}

/**
 * Deterministic post-processing of DeepSeek's analysis:
 * 1) strict schema validation, 2) URL hygiene, 3) cross-field consistency (downgrade).
 * Returns a reconciled analysis, or null if fundamentally invalid.
 */
function reconcileAnalysis($analysis) {
    if (!is_array($analysis)) return null;

    // 1. Schema validation
    if (empty($analysis['status']) || !is_string($analysis['status'])) return null;
    if (empty($analysis['dangerLevel']) || !is_string($analysis['dangerLevel'])) return null;
    $level = strtolower($analysis['dangerLevel']);
    if (!in_array($level, ['critical', 'high', 'moderate', 'low'])) return null;
    $analysis['dangerLevel'] = $level;

    // Fill missing optional fields with safe defaults
    if (empty($analysis['dangerReason']) || !is_string($analysis['dangerReason'])) {
        $analysis['dangerReason'] = 'Risk assessment based on latest news.';
    }
    if (empty($analysis['details']) || !is_array($analysis['details'])) {
        $analysis['details'] = ['Police presence: Unknown','Recent incidents: See status','Medical access: Unknown','Legal support: Unknown'];
    }
    $analysis['isEnded'] = !empty($analysis['isEnded']) && $analysis['isEnded'] !== 'false';

    // 2. URL hygiene — hard rule: never emit URLs in prose fields
    foreach (['status', 'dangerReason'] as $f) {
        $analysis[$f] = preg_replace('#https?://\S+#i', '[link removed]', $analysis[$f]);
    }
    if (is_array($analysis['details'])) {
        foreach ($analysis['details'] as $i => $d) {
            $analysis['details'][$i] = preg_replace('#https?://\S+#i', '[link removed]', (string)$d);
        }
    }

    // Parse optional recency/confidence signals
    $analysis['recencyDays'] = (isset($analysis['recencyDays']) && is_numeric($analysis['recencyDays'])) ? (int)$analysis['recencyDays'] : 0;
    if (isset($analysis['confidence']) && is_string($analysis['confidence'])) {
        $analysis['confidence'] = strtolower($analysis['confidence']);
        if (!in_array($analysis['confidence'], ['low', 'medium', 'high'])) $analysis['confidence'] = 'medium';
    } else {
        $analysis['confidence'] = 'medium';
    }

    // 3. Cross-field consistency — deterministic downgrade on contradiction
    $status = strtolower($analysis['status']);
    if ($analysis['isEnded']) {
        $analysis['dangerLevel'] = 'low'; // archive step will remove it
        return $analysis;
    }
    if (in_array($analysis['dangerLevel'], ['critical', 'high'])) {
        $strongCalm = ['officially ended','has ended','called off','concluded','dispersed',
                       'wound down','no longer active','formally accepted all demands'];
        $moderateCalm = ['peaceful','no violence','no incidents','calm','subsided','died down','low turnout'];
        $hasStrong = false;
        foreach ($strongCalm as $w) { if (strpos($status, $w) !== false) { $hasStrong = true; break; } }
        $hasModerate = false;
        foreach ($moderateCalm as $w) { if (strpos($status, $w) !== false) { $hasModerate = true; break; } }
        if ($hasStrong) {
            logUpdate("  Reconcile: status says concluded — downgrading {$analysis['dangerLevel']} → low");
            $analysis['dangerLevel'] = 'low';
        } elseif ($hasModerate) {
            logUpdate("  Reconcile: status says calm — downgrading {$analysis['dangerLevel']} → moderate");
            $analysis['dangerLevel'] = 'moderate';
        }
    }

    // 4. Recency requirement — critical/high needs a recent incident
    if (in_array($analysis['dangerLevel'], ['critical', 'high']) && $analysis['recencyDays'] > MAX_ESCALATION_AGE_DAYS) {
        logUpdate("  Staleness: last incident {$analysis['recencyDays']}d ago — downgrading {$analysis['dangerLevel']} → moderate");
        $analysis['dangerLevel'] = 'moderate';
    }

    return $analysis;
}

// ==========================================
// PHASE D: LEVEL CONSENSUS (prevent flapping)
// ==========================================

/**
 * A danger-level change must be proposed on TWO consecutive runs before it commits.
 * Prevents a single outlier headline from flipping a level up or down.
 * Modifies $protest's pendingLevel/pendingSince; returns the (possibly held) analysis.
 */
function consensusCheck(&$protest, $analysis) {
    $newLevel = $analysis['dangerLevel'] ?? ($protest['dangerLevel'] ?? 'low');
    $oldLevel = $protest['dangerLevel'] ?? 'low';

    if ($newLevel === $oldLevel) {
        // No change — clear any stale pending state
        unset($protest['pendingLevel'], $protest['pendingSince']);
        return $analysis;
    }

    // A change is proposed
    if (isset($protest['pendingLevel']) && $protest['pendingLevel'] === $newLevel) {
        // Confirmed on a second consecutive run → commit
        unset($protest['pendingLevel'], $protest['pendingSince']);
        logUpdate("  Consensus: {$oldLevel} → {$newLevel} confirmed");
        return $analysis;
    }

    // First proposal — hold the current level, mark pending
    $protest['pendingLevel'] = $newLevel;
    $protest['pendingSince'] = date('c');
    $analysis['dangerLevel'] = $oldLevel;
    logUpdate("  Consensus: proposed {$oldLevel} → {$newLevel}; holding for confirmation");
    return $analysis;
}

// ==========================================
// APPLY ANALYSIS
// ==========================================

function applyAnalysis($protest, $analysis) {
    if (!empty($analysis['status'])) {
        $protest['status'] = $analysis['status'];
    }

    if (!empty($analysis['dangerLevel'])) {
        $level = strtolower($analysis['dangerLevel']);
        if (in_array($level, ['critical', 'high', 'moderate', 'low'])) {
            $protest['dangerLevel'] = $level;
        }
    }

    if (!empty($analysis['dangerReason'])) {
        $protest['dangerReason'] = $analysis['dangerReason'];
    }

    if (!empty($analysis['details']) && is_array($analysis['details'])) {
        $protest['details'] = $analysis['details'];
    }

    // Carry the explicit "ended" signal onto the protest record
    if (isset($analysis['isEnded'])) {
        $protest['isEnded'] = (bool)$analysis['isEnded'];
    }

    // Store confidence + recency for display/audit
    if (isset($analysis['confidence'])) $protest['confidence'] = $analysis['confidence'];
    if (isset($analysis['recencyDays'])) $protest['recencyDays'] = $analysis['recencyDays'];

    $protest['lastUpdated'] = date('c');
    return $protest;
}

// ==========================================
// CITY-LEVEL CALCULATIONS
// ==========================================

function calculateOverallDanger($protests) {
    $levels = ['low' => 0, 'moderate' => 1, 'high' => 2, 'critical' => 3];
    $reverse = [0 => 'low', 1 => 'moderate', 2 => 'high', 3 => 'critical'];
    $max = 0;
    foreach ($protests as $p) {
        $val = $levels[$p['dangerLevel']] ?? 0;
        if ($val > $max) $max = $val;
    }
    return $reverse[$max];
}

function generateOverallStatus($cityName, $protests) {
    $count = count($protests);
    $parts = [];
    foreach ($protests as $p) {
        $parts[] = $p['name'] . ' (' . ucfirst($p['dangerLevel']) . ')';
    }
    if ($count === 1) {
        return "1 active protest in {$cityName}: {$parts[0]}. Situation is " . $protests[0]['dangerLevel'] . ".";
    }
    return "{$count} active protests in {$cityName}: " . implode(' | ', $parts) . ".";
}

/**
 * Discover NEW protests in a city using Tavily.
 * Searches for protests not already in our list and adds them.
 */
function discoverNewProtests(&$city, $slug, $cityName) {
    // Only run discovery once every 12 hours to save API calls
    // Check if we've run discovery recently
    $lastDiscovery = $city['_lastDiscovery'] ?? 0;
    if ((time() - $lastDiscovery) < 43200) return; // 12 hours

    // Mark discovery as run regardless of outcome (throttle: once per 12h even if no new protest found)
    $city['_lastDiscovery'] = time();

    $query = "$cityName new protest latest news August 2026";
    
    // Search via shared fallback chain (TinyFish → Firecrawl → SerpAI → Tavily)
    $answer = '';
    $headlines = [];
    $searchResult = searchWeb($query);
    
    if (!empty($searchResult['headlines']) || !empty($searchResult['answer'])) {
        global $tavilySuccessCount;
        $tavilySuccessCount++;
        $answer = $searchResult['answer'] ?? '';
        foreach ($searchResult['headlines'] as $h) {
            $headlines[] = $h['title'] . ' — ' . ($h['content'] ?? '');
        }
    }
    
    if (empty($answer) && empty($headlines)) return;
    
    // Ask DeepSeek to identify NEW protests from the headlines
    $existingNames = [];
    foreach ($city['protests'] as $p) $existingNames[] = $p['name'];
    
    $prompt = "You are enriching a public safety map (azadi.wtf). Write DETAILED, FACTUAL content based on news headlines. Each field must be thorough and specific.\n\n";
    $prompt .= "City: $cityName\n";
    $prompt .= "Existing protests: " . implode('; ', $existingNames) . "\n\n";
    $prompt .= "Latest news headlines:\n" . implode("\n", array_slice($headlines, 0, 5)) . "\n\n";
    $prompt .= "News AI summary: $answer\n\n";
    $prompt .= "Are there any NEW protests in $cityName that are NOT in the existing list? ";
    $prompt .= "If yes, output ONLY a JSON array of new protests. Each must have:\n\n";
    $prompt .= "- name: A clear, descriptive title (max 80 chars)\n";
    $prompt .= "- location: Specific area/landmark in $cityName where the protest is happening\n";
    $prompt .= "- cause: What are they protesting about? Be specific (1-2 sentences)\n";
    $prompt .= "- dangerLevel: One of 'critical', 'high', 'moderate', or 'low'\n";
    $prompt .= "- dangerReason: 2-3 detailed sentences explaining WHY this danger level. Cite specific incidents: police numbers, injuries, arrests, clashes, weapons, roadblocks, government response. Be specific with dates/locations/numbers if available.\n";
    $prompt .= "- status: A THOROUGH 3-5 sentence journalistic update. Include: how many people participating, where exactly they are gathered, what happened most recently with dates, police/government posture, what the protestors plan next. Minimum 300 characters.\n";
    $prompt .= "- details: Array of exactly 4 strings, each 80-160 characters:\n";
    $prompt .= "  [0] Police presence: Exact locations, numbers deployed, equipment (barricades, drones, water cannons), road closures\n";
    $prompt .= "  [1] Recent incidents: Specific events with dates, injuries, arrests, property damage, who did what\n";
    $prompt .= "  [2] Medical access: Which hospitals are nearby, whether ambulances are on site, any injuries reported, accessibility concerns\n";
    $prompt .= "  [3] Legal support: Which organizations/lawyers are involved, helpline numbers if available, legal status of detained protesters, court interventions\n\n";
    $prompt .= "If there are genuinely no new protests beyond the existing list, output an empty array []. Do NOT include protests already in the existing list.";
    
    $payload = [
        'model' => DEEPSEEK_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.3,
        'max_tokens' => 8000
    ];
    
    $ch = curl_init(DEEPSEEK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . DEEPSEEK_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    $decoded = json_decode($resp, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    
    // Extract JSON from response
    $json = $content;
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) $json = trim($m[1]);
    if (!preg_match('/^\[/', $json) && preg_match('/\[.*\]/s', $json, $m)) $json = $m[0];
    
    $newProtests = json_decode($json, true);
    if (!is_array($newProtests) || empty($newProtests)) return;
    
    $added = 0;
    $knownNames = [];
    foreach ($city['protests'] as $ep) $knownNames[] = $ep['name']; // existing names

    foreach ($newProtests as $np) {
        // Validate required fields
        if (empty($np['name']) || empty($np['dangerLevel'])) continue;
        if (!in_array($np['dangerLevel'], ['critical','high','moderate','low'])) continue;
        
        // Code-level dedup: skip if name matches an existing OR already-added protest
        if (isDuplicateProtestName($np['name'], $knownNames)) {
            logUpdate("  Skipped duplicate: {$np['name']}");
            continue;
        }
        $knownNames[] = $np['name'];
        
        // Add the new protest
        $np['id'] = $slug . '-' . substr(md5(normalizeProtestName($np['name'])), 0, 12);
        $np['lastUpdated'] = date('c');
        $np['source'] = $searchResult['source'] ?? 'unknown';
        if (empty($np['location'])) $np['location'] = $cityName;
        if (empty($np['cause'])) $np['cause'] = 'Newly discovered protest';
        if (empty($np['details']) || !is_array($np['details'])) {
            $np['details'] = ['Police presence: Unknown','Recent incidents: See status','Medical access: Unknown','Legal support: Unknown'];
        }
        if (empty($np['dangerReason'])) $np['dangerReason'] = 'Newly discovered protest. Risk assessment based on initial reports.';
        
        $city['protests'][] = $np;
        $added++;
        logUpdate("  DISCOVERED: {$cityName} — {$np['name']} [{$np['dangerLevel']}]");
    }
    
    if ($added > 0) {
        logUpdate("  Added $added new protests to {$cityName}");
    }
}
