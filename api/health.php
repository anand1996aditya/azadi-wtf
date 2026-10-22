<?php
/**
 * api/health.php — serves the latest health sweep report.
 *
 * Public response (audit F8, 23 Oct 2026): status and counts only. The full
 * report carries internal notes about data quality and is returned only to a
 * caller that presents the cron secret in a header:
 *   curl -H "Authorization: Bearer $AZADI_CRON_SECRET" https://azadi.wtf/api/health.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$file = __DIR__ . '/../data/health.json';
if (!file_exists($file)) {
    http_response_code(503);
    echo json_encode(['error' => 'no health report yet']);
    exit;
}

$configFile = __DIR__ . '/../config.php';
if (is_file($configFile)) {
    require_once $configFile;
}
require_once __DIR__ . '/../cron/auth.php';

if (azadi_cron_is_authorized()) {
    // Full report: internal issue text, never cached by shared proxies.
    header('Cache-Control: private, no-store');
    readfile($file);
    exit;
}

$report = json_decode((string)@file_get_contents($file), true);
if (!is_array($report)) {
    http_response_code(503);
    echo json_encode(['error' => 'health report unreadable']);
    exit;
}

$public = [
    'status'   => !empty($report['ok']) ? 'ok' : 'issues',
    'at'       => $report['at'] ?? null,
    'cities'   => (int)($report['cities'] ?? 0),
    'protests' => (int)($report['protests'] ?? 0),
    'counts'   => [
        'issues'   => is_array($report['issues'] ?? null) ? count($report['issues']) : 0,
        'repaired' => is_array($report['repaired'] ?? null) ? count($report['repaired']) : 0,
        'dormant'  => (int)($report['dormant'] ?? 0),
        'archived' => (int)($report['archived'] ?? 0),
        'purged'   => (int)($report['purged'] ?? 0),
        'flagged'  => (int)($report['flagged'] ?? 0),
    ],
];

echo json_encode($public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
