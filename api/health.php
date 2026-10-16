<?php
/**
 * api/health.php — serves the latest health sweep report.
 *
 * Public on purpose (10 Oct 2026): the report holds counts and issue lists, no
 * secrets. If it ever leaks something, move it behind the secret.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$file = __DIR__ . '/../data/health.json';
if (!file_exists($file)) {
    http_response_code(503);
    echo json_encode(['error' => 'no health report yet']);
    exit;
}
readfile($file);
