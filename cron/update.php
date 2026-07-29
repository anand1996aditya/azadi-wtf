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
            // Step 1: Search Tavily for latest news
            $searchResults = searchTavily($city['name'], $protest);
            $headlineCount = count($searchResults['headlines'] ?? []);
            logUpdate("  Tavily returned {$headlineCount} results" . ($searchResults['answer'] ? ' + AI summary' : ''));

            // Step 2: Analyze with DeepSeek
            $analysis = analyzeWithDeepSeek($city['name'], $protest, $searchResults);

            if ($analysis) {
                $oldLevel = $protest['dangerLevel'];
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
foreach ($data['cities'] as $citySlug => &$city) {
    // Auto-remove ended protests
    $activeProtests = [];
    $removed = 0;
    foreach ($city['protests'] as $protest) {
        $status = strtolower($protest['status']);
        $isEnded = false;
        $endWords = ['ended','concluded','formally ended','sit-in has ended','protest has ended',
                     'no longer active','disbanded','called off','wound down','wrapped up'];
        foreach ($endWords as $word) {
            if (strpos($status, $word) !== false) { $isEnded = true; break; }
        }
        // Only remove if danger is low AND status indicates it ended
        if ($isEnded && $protest['dangerLevel'] === 'low') {
            $removed++;
            logUpdate("  Archived: {$city['name']} — {$protest['name']} (ended)");
        } else {
            $activeProtests[] = $protest;
        }
    }
    if ($removed > 0) {
        $city['protests'] = $activeProtests;
        logUpdate("  Removed $removed ended protests from {$city['name']}");
    }
    
    $city['overallDanger'] = calculateOverallDanger($city['protests']);
    $city['overallStatus'] = generateOverallStatus($city['name'], $city['protests']);
}

// Save
$data['lastUpdated'] = date('c');
if (saveData($data)) {
    logUpdate("SAVED: {$updated} updated, {$skipped} skipped, {$errors} errors");
    // Sync fresh data to data.js for homepage map
    $synced = syncDataJs();
} else {
    logUpdate("ERROR: Failed to write protests.json — check file permissions");
}

logUpdate("=== UPDATE RUN COMPLETE ===\n");
echo "Done. Updated: {$updated}, Skipped: {$skipped}, Errors: {$errors}\n";

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
    if (empty($protest['lastUpdated'])) return true;
    $lastUpdate = strtotime($protest['lastUpdated']);
    if ($lastUpdate === false) return true;
    $interval = getIntervalForLevel($level);
    $elapsed = time() - $lastUpdate;
    // If server clock is behind (negative elapsed), force update
    if ($elapsed < 0) return true;
    return $elapsed >= ($interval - 60);
}

// ==========================================
// TAVILY SEARCH
// ==========================================

/**
 * Search Tavily for latest news about a specific protest.
 * Returns { answer, headlines: [{title, url, content, score}] }
 */
function searchTavily($cityName, $protest) {
    // Build a targeted search query
    $query = "{$protest['name']} {$cityName} {$protest['location']} protest latest news 2026";

    $payload = [
        'api_key'        => TAVILY_API_KEY,
        'query'          => $query,
        'search_depth'   => 'advanced',
        'include_answer' => true,
        'max_results'    => 8,
        'include_images' => false
    ];

    $ch = curl_init(TAVILY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logUpdate("  Tavily cURL error: {$curlError}");
        return ['answer' => null, 'headlines' => []];
    }

    if ($httpCode !== 200) {
        logUpdate("  Tavily HTTP {$httpCode}: " . substr($response, 0, 300));
        // Fallback: broader search
        return searchTavilyFallback($cityName);
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        logUpdate("  Tavily returned invalid JSON");
        return ['answer' => null, 'headlines' => []];
    }

    $answer = $decoded['answer'] ?? null;
    $headlines = [];

    foreach ($decoded['results'] ?? [] as $r) {
        $headlines[] = [
            'title'   => $r['title'] ?? 'Untitled',
            'url'     => $r['url'] ?? '',
            'content' => $r['content'] ?? '',
            'score'   => $r['score'] ?? 0
        ];
    }

    return [
        'answer'    => $answer,
        'headlines' => $headlines
    ];
}

/** Fallback: broader city-level search if protest-specific search fails */
function searchTavilyFallback($cityName) {
    $query = "{$cityName} protest police action news 2026";

    $payload = [
        'api_key'        => TAVILY_API_KEY,
        'query'          => $query,
        'search_depth'   => 'advanced',
        'include_answer' => true,
        'max_results'    => 5,
        'include_images' => false
    ];

    $ch = curl_init(TAVILY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logUpdate("  Tavily fallback cURL error: {$curlError}");
        return ['answer' => null, 'headlines' => []];
    }

    $decoded = json_decode($response, true);
    $headlines = [];
    foreach ($decoded['results'] ?? [] as $r) {
        $headlines[] = [
            'title'   => $r['title'] ?? 'Untitled',
            'url'     => $r['url'] ?? '',
            'content' => $r['content'] ?? '',
            'score'   => $r['score'] ?? 0
        ];
    }

    return [
        'answer'    => $decoded['answer'] ?? null,
        'headlines' => $headlines
    ];
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

    $systemPrompt = <<<PROMPT
You are a protest safety analyst for India. Your job is to analyze the latest news and search results about a specific protest, then update its safety assessment in structured JSON.

OUTPUT ONLY THIS JSON FORMAT:
{
  "status": "2-4 factual sentences describing the CURRENT situation. Be specific about what's happening right now. If search reveals no major changes, acknowledge that the situation appears stable and cite what was last known.",
  "dangerLevel": "critical|high|moderate|low",
  "dangerReason": "2-3 sentences explaining WHY this danger level was assigned. Reference specific evidence from the search results. If no new evidence, explain using the previous assessment.",
  "details": [
    "Police presence: [assessment — heavy/moderate/light/unknown — with specifics if available]",
    "Recent incidents: [what happened since the last update — be factual]",
    "Medical access: [available/restricted/severely restricted/unknown]",
    "Legal support: [assessment]"
  ]
}

DANGER LEVEL DEFINITIONS:
- CRITICAL: Police violence (lathi charges, water cannons, tear gas), mass detentions, medical access blocked, Section 144/163 imposed with active enforcement. Protester injuries confirmed.
- HIGH: Some detentions, elevated police presence, area restrictions imposed, tense standoffs. No mass violence but potential for escalation.
- MODERATE: Police present but restrained, minor incidents, generally peaceful. Some restrictions or identity checks but no violence.
- LOW: Peaceful, no incidents, police presence minimal or administrative only. Protests well-organized with cooperative atmosphere.

CRITICAL RULES:
- NEVER fabricate incidents or violence. If search results don't mention violence, do NOT invent it.
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

    $payload = [
        'model'       => DEEPSEEK_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt]
        ],
        'temperature' => 0.3,
        'max_tokens'  => 2000
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
        CURLOPT_TIMEOUT        => 30,
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

    // Extract JSON from response
    $json = trim($content);
    // Strip code fences if present
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $matches)) {
        $json = trim($matches[1]);
    }
    // Fallback: find first JSON object
    $analysis = json_decode($json, true);
    if (!$analysis && preg_match('/\{.*\}/s', $json, $m)) {
        $analysis = json_decode($m[0], true);
    }

    if (!$analysis) {
        logUpdate("  Failed to parse DeepSeek response as JSON. Raw: " . substr($content, 0, 200));
        return null;
    }

    if (empty($analysis['status']) && empty($analysis['dangerLevel'])) {
        logUpdate("  Analysis missing required fields (status and dangerLevel)");
        return null;
    }

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
