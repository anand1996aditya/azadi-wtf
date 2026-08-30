<?php
/**
 * Search sources test — verifies TinyFish, Firecrawl, SerpAI, Tavily
 * Self-contained test using the shared search.php fallback chain.
 * Returns JSON: result count per source + fallback chain result.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../cron/search.php';

$query = 'Delhi protest news latest 2026';

$results = [
    'query'    => $query,
    'tinyfish' => count(searchTinyFish($query)['headlines']),
    'firecrawl'=> count(searchFirecrawl($query)['headlines']),
    'serpai'   => count(searchSerpAI($query)['headlines']),
    'tavily'   => count(searchTavily($query)['headlines']),
    'fallback' => count(searchWeb($query)['headlines']),
];

echo json_encode($results, JSON_PRETTY_PRINT);
