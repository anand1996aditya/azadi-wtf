<?php
/**
 * Protest Site API
 * Serves protest data as JSON for the frontend
 * Endpoint: /api/protests.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

$dataFile = __DIR__ . '/../data/protests.json';

if (!file_exists($dataFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Data file not found']);
    exit;
}

$data = file_get_contents($dataFile);
$decoded = json_decode($data, true);

if ($decoded === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid JSON data', 'json_error' => json_last_error_msg()]);
    exit;
}

// Remove internal fields if any
unset($decoded['_internal']);

// If a specific city is requested
if (isset($_GET['city'])) {
    $citySlug = preg_replace('/[^a-z-]/', '', strtolower($_GET['city']));
    if (isset($decoded['cities'][$citySlug])) {
        echo json_encode($decoded['cities'][$citySlug], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'City not found']);
    }
    exit;
}

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
