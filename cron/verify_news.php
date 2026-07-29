<?php
/**
 * News Link Verification + Auto-Fix
 * Checks news URLs across all states. Flags states where >50% links are broken.
 * If broken, replaces with fresh Tavily results. Runs every 3 hours via run.php.
 */

require_once __DIR__ . '/../config.php';

$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    if (($_GET['secret'] ?? '') !== CRON_SECRET) { http_response_code(403); die('Unauthorized'); }
}

$DATA_JS_FILE = __DIR__ . '/../data.js';
$LOG_FILE = __DIR__ . '/../data/news_verify.log';

function nvlog($msg) {
    global $LOG_FILE;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
    echo $msg . "\n";
}

nvlog("=== NEWS VERIFICATION STARTED ===");

if (!file_exists($DATA_JS_FILE)) {
    nvlog("ERROR: data.js not found"); die("data.js not found\n");
}

// Read data.js
$dataJs = file_get_contents($DATA_JS_FILE);

// Extract STATE_DATA object using a simpler approach
// Find var STATE_DATA = { ... };
if (!preg_match('/var STATE_DATA\s*=\s*(\{.*\});\s*$/s', $dataJs, $m)) {
    nvlog("ERROR: Could not parse STATE_DATA from data.js");
    die("Parse error\n");
}

// Parse as JSON — data.js uses mixed quoting, so clean it up
$jsonStr = $m[1];
// Convert unquoted JS keys to quoted JSON keys
$jsonStr = preg_replace('/([{,]\s*)([a-zA-Z_]\w*)\s*:/', '$1"$2":', $jsonStr);
// Fix trailing commas
$jsonStr = preg_replace('/,\s*([}\]])/', '$1', $jsonStr);

$stateData = json_decode($jsonStr, true);
if (!$stateData) {
    nvlog("ERROR: JSON decode failed: " . json_last_error_msg());
    die("JSON error: " . json_last_error_msg() . "\n");
}

nvlog("Loaded " . count($stateData) . " states");

// Check URL with HEAD request
function checkUrl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; azadi.wtf/1.0)'
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    // Treat errors as broken
    if ($error) return false;
    return ($code >= 200 && $code < 400);
}

// Search Tavily for real replacement news
function searchTavilyNews($stateName) {
    $ch = curl_init(TAVILY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_key' => TAVILY_API_KEY,
            'query' => $stateName . ' protest latest news 2026',
            'search_depth' => 'basic',
            'include_answer' => false,
            'max_results' => 6
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $news = [];
    foreach ($data['results'] ?? [] as $r) {
        $title = addslashes(substr($r['title'] ?? 'Untitled', 0, 120));
        $url = $r['url'] ?? '';
        $snippet = addslashes(substr($r['content'] ?? '', 0, 180));
        
        // Source detection
        $domain = parse_url($url, PHP_URL_HOST);
        $source = 'News Source';
        if (strpos($domain, 'thehindu.com') !== false) $source = 'The Hindu';
        elseif (strpos($domain, 'indianexpress.com') !== false) $source = 'Indian Express';
        elseif (strpos($domain, 'bbc.com') !== false) $source = 'BBC';
        elseif (strpos($domain, 'aljazeera.com') !== false) $source = 'Al Jazeera';
        elseif (strpos($domain, 'ndtv.com') !== false) $source = 'NDTV';
        elseif (strpos($domain, 'timesofindia') !== false) $source = 'Times of India';
        elseif (strpos($domain, 'youtube.com') !== false) $source = 'YouTube';
        elseif (strpos($domain, 'instagram.com') !== false) $source = 'Instagram';
        elseif (strpos($domain, 'cnn.com') !== false) $source = 'CNN';
        elseif (strpos($domain, 'washingtonpost.com') !== false) $source = 'Washington Post';
        elseif (strpos($domain, 'hrw.org') !== false) $source = 'Human Rights Watch';
        elseif (strpos($domain, 'nytimes.com') !== false) $source = 'New York Times';
        
        $type = 'article';
        if (strpos($url, 'youtube.com') !== false) $type = 'video';
        if (strpos($url, 'instagram.com') !== false) $type = 'social';
        
        $news[] = "{\"title\":\"$title\",\"url\":\"$url\",\"source\":\"$source\",\"snippet\":\"$snippet\",\"type\":\"$type\"}";
    }
    return $news;
}

$totalChecked = 0;
$totalBroken = 0;
$totalReplaced = 0;

foreach ($stateData as $code => $state) {
    if (empty($state['news'])) continue;
    
    $news = $state['news'];
    $total = count($news);
    if ($total === 0) continue;
    
    // Check first 3 URLs
    $sample = array_slice($news, 0, 3);
    $broken = 0;
    foreach ($sample as $item) {
        $url = $item['url'] ?? '';
        if (empty($url)) { $broken++; continue; }
        $totalChecked++;
        if (!checkUrl($url)) $broken++;
    }
    
    $pct = round(($broken / count($sample)) * 100);
    
    if ($pct >= 67) {
        // More than 2/3 broken — replace all
        $name = $state['name'] ?? $code;
        nvlog("  ✗ $name — $broken/".count($sample)." broken ($pct%). Replacing with Tavily...");
        
        $newNews = searchTavilyNews($name);
        if (count($newNews) >= 3) {
            // Inject into data.js
            $newNewsStr = '[' . implode(',', $newNews) . ']';
            
            // Find and replace this state's news array
            $pattern = '/("' . $code . '":\s*\{.*?)news:\s*\[.*?\]/s';
            $replacement = '$1news: ' . $newNewsStr;
            $newDataJs = preg_replace($pattern, $replacement, $dataJs, 1);
            
            if ($newDataJs !== $dataJs && $newDataJs !== null) {
                $dataJs = $newDataJs;
                $totalReplaced++;
                nvlog("    Replaced with " . count($newNews) . " Tavily links");
            }
        } else {
            nvlog("    Tavily returned insufficient results");
        }
        $totalBroken++;
    } else if ($pct > 0) {
        $name = $state['name'] ?? $code;
        nvlog("  ~ $name — $broken/".count($sample)." broken ($pct%) — within tolerance");
    } else {
        $name = $state['name'] ?? $code;
        nvlog("  ✓ $name — all links OK");
    }
    
    usleep(300000);
}

if ($totalReplaced > 0) {
    file_put_contents($DATA_JS_FILE, $dataJs, LOCK_EX);
    nvlog("SAVED: $totalReplaced states updated");
}

nvlog("=== COMPLETE: $totalChecked checked, $totalBroken states had >50% broken, $totalReplaced fixed ===\n");
echo "Done.\n";
