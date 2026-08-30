<?php
/**
 * cron/search.php — Shared search fallback chain for azadi.wtf
 *
 * Priority order (cost-effective — free first, most expensive last):
 *   TinyFish (free) → Tavily → Firecrawl → SerpAI
 *
 * Every search-capable script (update, verify, verify_news, tests) must
 * require this file and use searchWeb() so the order stays in ONE place.
 *
 * Functions return a normalized array:
 *   ['answer' => string|null, 'headlines' => [ ['title','url','content','score'], ... ]]
 */

require_once __DIR__ . '/../config.php';

/**
 * Internal logger — scripts set $GLOBALS['AZADI_LOG'] to their log fn name
 * (e.g. 'logUpdate', 'vlog', 'nvlog'). Defaults to silent.
 */
function azadiLog($msg) {
    if (!empty($GLOBALS['AZADI_LOG']) && is_callable($GLOBALS['AZADI_LOG'])) {
        call_user_func($GLOBALS['AZADI_LOG'], $msg);
    }
}

// ==========================================
// TINYFISH (MCP over HTTP) — #1 free source
// ==========================================

function tinyfishMcpCall($method, $params, $sessionId = null) {
    $payload = [
        'jsonrpc' => '2.0',
        'id'      => rand(1, 999999),
        'method'  => $method,
        'params'  => $params
    ];

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . TINYFISH_API_KEY
    ];
    if ($sessionId) {
        $headers[] = 'Mcp-Session-Id: ' . $sessionId;
    }

    $ch = curl_init(TINYFISH_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [null, null, $curlError];
    }

    $headerBlock = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $sid = null;
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (stripos($line, 'mcp-session-id:') === 0) {
            $sid = trim(substr($line, strlen('mcp-session-id:')));
        }
    }

    return [$body, $sid, null];
}

function searchTinyFish($query) {
    if (!defined('TINYFISH_API_KEY') || TINYFISH_API_KEY === '') {
        return ['answer' => null, 'headlines' => []];
    }

    // Step 1: initialize to obtain a session id
    list($body, $sid, $err) = tinyfishMcpCall('initialize', [
        'protocolVersion' => '2024-11-05',
        'capabilities'    => new stdClass(),
        'clientInfo'      => ['name' => 'azadi-cron', 'version' => '3.0']
    ]);

    if ($err || !$sid) {
        azadiLog("  TinyFish init failed" . ($err ? ": {$err}" : " (no session)"));
        return ['answer' => null, 'headlines' => []];
    }

    // Step 2: call the search tool
    list($body, $sid2, $err2) = tinyfishMcpCall('tools/call', [
        'name'      => 'search',
        'arguments' => [
            'query'       => $query,
            'domain_type' => 'news',
            'location'    => 'IN'
        ]
    ], $sid);

    if ($err2) {
        azadiLog("  TinyFish search cURL error: {$err2}");
        return ['answer' => null, 'headlines' => []];
    }

    $decoded = json_decode($body, true);
    if (!$decoded || !isset($decoded['result']['content'])) {
        azadiLog("  TinyFish returned invalid response");
        return ['answer' => null, 'headlines' => []];
    }

    $text = '';
    foreach ($decoded['result']['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    $inner = json_decode($text, true);
    $headlines = [];
    foreach ($inner['results'] ?? [] as $r) {
        $headlines[] = [
            'title'   => $r['title'] ?? 'Untitled',
            'url'     => $r['url'] ?? '',
            'content' => $r['snippet'] ?? '',
            'score'   => 5
        ];
    }

    azadiLog("  TinyFish returned " . count($headlines) . " results");
    return ['answer' => null, 'headlines' => $headlines];
}

// ==========================================
// FIRECRAWL — #2 free source
// ==========================================

function searchFirecrawl($query) {
    if (!defined('FIRECRAWL_API_KEY') || FIRECRAWL_API_KEY === '') {
        return ['answer' => null, 'headlines' => []];
    }

    $payload = ['query' => $query, 'limit' => 8];

    $ch = curl_init(FIRECRAWL_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . FIRECRAWL_API_KEY
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        azadiLog("  Firecrawl cURL error: {$curlError}");
        return ['answer' => null, 'headlines' => []];
    }
    if ($httpCode !== 200) {
        azadiLog("  Firecrawl HTTP {$httpCode}: " . substr($response, 0, 200));
        return ['answer' => null, 'headlines' => []];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        azadiLog("  Firecrawl returned invalid JSON");
        return ['answer' => null, 'headlines' => []];
    }

    $headlines = [];
    foreach ($decoded['data'] ?? [] as $r) {
        $headlines[] = [
            'title'   => $r['title'] ?? 'Untitled',
            'url'     => $r['url'] ?? '',
            'content' => $r['description'] ?? '',
            'score'   => 5
        ];
    }

    azadiLog("  Firecrawl returned " . count($headlines) . " results");
    return ['answer' => null, 'headlines' => $headlines];
}

// ==========================================
// SERPAI — #3 fallback (serpapi/serper/etc.)
// ==========================================

function searchSerpAI($query) {
    if (!defined('SERPAI_API_KEY') || SERPAI_API_KEY === '' || SERPAI_API_KEY === 'YOUR_SERPAI_KEY_HERE') {
        return ['answer' => null, 'headlines' => []];
    }

    $provider = defined('SERPAI_PROVIDER') ? SERPAI_PROVIDER : 'serpapi';
    $q = urlencode($query);

    switch ($provider) {
        case 'serper':
            $url = SERPAI_API_URL;
            $body = json_encode(['q' => $q, 'num' => 8]);
            $headers = ['Content-Type: application/json', 'X-API-KEY: ' . SERPAI_API_KEY];
            $isPost = true;
            break;
        case 'serpdog':
            $url = SERPAI_API_URL . '?api_key=' . SERPAI_API_KEY . '&q=' . $q . '&num=8&gl=in&hl=en';
            $body = null; $headers = []; $isPost = false;
            break;
        case 'valueserp':
            $url = SERPAI_API_URL . '?api_key=' . SERPAI_API_KEY . '&q=' . $q . '&num=8&location=India&hl=en';
            $body = null; $headers = []; $isPost = false;
            break;
        case 'serpapi':
        default:
            $url = SERPAI_API_URL . '?api_key=' . SERPAI_API_KEY . '&q=' . $q . '&num=8&gl=in&hl=en&engine=google';
            $body = null; $headers = []; $isPost = false;
            break;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers
    ];
    if ($isPost && $body) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        azadiLog("  SerpAI cURL error: {$curlError}");
        return ['answer' => null, 'headlines' => []];
    }
    if ($httpCode !== 200) {
        azadiLog("  SerpAI HTTP {$httpCode}: " . substr($response, 0, 200));
        return ['answer' => null, 'headlines' => []];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        azadiLog("  SerpAI returned invalid JSON");
        return ['answer' => null, 'headlines' => []];
    }

    $answer = null;
    $headlines = [];

    switch ($provider) {
        case 'serper':
            $answer = $decoded['answerBox']['answer'] ?? $decoded['answerBox']['snippet'] ?? null;
            foreach ($decoded['organic'] ?? [] as $r) {
                $headlines[] = [
                    'title'   => $r['title'] ?? 'Untitled',
                    'url'     => $r['link'] ?? '',
                    'content' => $r['snippet'] ?? '',
                    'score'   => 5
                ];
            }
            break;
        default:
            $answer = $decoded['answer_box']['answer'] ?? $decoded['answer_box']['snippet'] ?? null;
            foreach ($decoded['organic_results'] ?? [] as $r) {
                $headlines[] = [
                    'title'   => $r['title'] ?? 'Untitled',
                    'url'     => $r['link'] ?? '',
                    'content' => $r['snippet'] ?? '',
                    'score'   => 5
                ];
            }
            break;
    }

    azadiLog("  SerpAI returned " . count($headlines) . " results" . ($answer ? " + answer" : ""));
    return ['answer' => $answer, 'headlines' => $headlines];
}

// ==========================================
// TAVILY — #4 last resort (paid)
// ==========================================

function searchTavily($query) {
    if (!defined('TAVILY_API_KEY') || TAVILY_API_KEY === '') {
        return ['answer' => null, 'headlines' => []];
    }

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
        azadiLog("  Tavily cURL error: {$curlError}");
        return ['answer' => null, 'headlines' => []];
    }
    if ($httpCode !== 200) {
        azadiLog("  Tavily HTTP {$httpCode}: " . substr($response, 0, 200));
        return ['answer' => null, 'headlines' => []];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        azadiLog("  Tavily returned invalid JSON");
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

    azadiLog("  Tavily returned " . count($headlines) . " results");
    return ['answer' => $answer, 'headlines' => $headlines];
}

// ==========================================
// RESULT POST-PROCESSING (deterministic hygiene)
// ==========================================

/**
 * Remove duplicate headlines (same URL or near-identical title).
 * Reduces prompt size and redundant context sent to DeepSeek.
 */
function dedupHeadlines(array $headlines) {
    $seen = [];
    $out = [];
    foreach ($headlines as $h) {
        $url = strtolower(trim($h['url'] ?? ''));
        $title = strtolower(trim($h['title'] ?? ''));
        if ($url !== '') {
            $key = 'u:' . $url;
        } else {
            $key = 't:' . preg_replace('/[^a-z0-9]+/', '', $title);
        }
        if ($key === 't:' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $h;
    }
    return $out;
}

// ==========================================
// UNIFIED FALLBACK — the single entry point
// ==========================================

/**
 * Search with the full fallback chain (cost-effective order).
 * TinyFish (free) → Tavily (~$0.008/search) → Firecrawl (~$0.01/search) → SerpAI (most expensive).
 * Returns the first source that yields results (deduplicated).
 */
function searchWeb($query) {
    // 1. TinyFish (free)
    $result = searchTinyFish($query);
    $result['headlines'] = dedupHeadlines($result['headlines'] ?? []);
    if (!empty($result['headlines']) || !empty($result['answer'])) {
        $result['source'] = 'tinyfish';
        return $result;
    }
    azadiLog("  TinyFish returned no results — trying Tavily");

    // 2. Tavily (cheapest paid, also returns AI answer)
    $result = searchTavily($query);
    $result['headlines'] = dedupHeadlines($result['headlines'] ?? []);
    if (!empty($result['headlines']) || !empty($result['answer'])) {
        $result['source'] = 'tavily';
        return $result;
    }
    azadiLog("  Tavily returned no results — trying Firecrawl");

    // 3. Firecrawl
    $result = searchFirecrawl($query);
    $result['headlines'] = dedupHeadlines($result['headlines'] ?? []);
    if (!empty($result['headlines']) || !empty($result['answer'])) {
        $result['source'] = 'firecrawl';
        return $result;
    }
    azadiLog("  Firecrawl returned no results — trying SerpAI");

    // 4. SerpAI (most expensive, last resort)
    $result = searchSerpAI($query);
    $result['headlines'] = dedupHeadlines($result['headlines'] ?? []);
    $result['source'] = 'serpai';
    return $result;
}
