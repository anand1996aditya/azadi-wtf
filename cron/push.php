<?php
/**
 * File push endpoint — deploys static public files to public_html.
 *
 * Accepts: POST filename + content (text) OR content_base64 (binary).
 *
 * Security (audit F5, 23 Oct 2026):
 *   - Only the static assets in $allowedFiles can be written. PHP files are
 *     deliberately absent: writing PHP through a web endpoint would turn a
 *     leaked shared secret into code execution on the host. Server-side code
 *     (cron/*.php, admin/*.php, api/*.php) is deployed by SFTP/SSH or the host's
 *     file manager, never by this endpoint.
 *   - Auth comes from the Authorization: Bearer <CRON_SECRET> or
 *     X-Cron-Secret: <CRON_SECRET> header (cron/auth.php). The secret is never
 *     read from the URL, so it cannot land in access logs.
 *   - $_POST['secret'] is accepted for one transition release so a deploy keeps
 *     working while older callers catch up. Remove it once the workflow and
 *     deploy.sh that send the header are live on the host.
 *   - Writes are size-capped and use LOCK_EX.
 *
 * Used by deploy.sh and the GitHub Actions deploy workflow.
 * Rotate the secret as described in cron/auth.php if it ever leaks.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('POST only'); }
azadi_cron_require_auth(true);

// Static files only. Keys are the exact request values; values are exact paths
// relative to the site root. No dynamic path building, so traversal cannot occur.
$allowedFiles = [
    // Public pages
    'index.html'           => 'index.html',
    'guide.html'           => 'guide.html',
    'guide-print.html'     => 'guide-print.html',
    'city.html'            => 'city.html',
    'cities.html'          => 'cities.html',
    'state.html'           => 'state.html',
    'history.html'         => 'history.html',
    'checklist.html'       => 'checklist.html',
    'trend-demo.html'      => 'trend-demo.html',
    // Frontend assets
    'style.css'            => 'style.css',
    'script.js'            => 'script.js',
    'data.js'              => 'data.js',
    'assets/d3.v7.min.js'            => 'assets/d3.v7.min.js',
    'assets/topojson-client.min.js'  => 'assets/topojson-client.min.js',
    // Downloads (binary, sent as content_base64)
    'Protest Safely.pdf'   => 'Protest Safely.pdf',
    'guide.pdf'            => 'guide.pdf',
    // Data served through api/protests.php
    'data/protests.json'   => 'data/protests.json',
];

$requested = isset($_POST['file']) ? str_replace('\\', '/', (string)$_POST['file']) : '';
if (strpos($requested, "\0") !== false || !isset($allowedFiles[$requested])) {
    http_response_code(400);
    die('Invalid filename');
}

$path = dirname(__DIR__) . '/' . $allowedFiles[$requested];

// Defense in depth: the allowlist cannot contain PHP, but refuse one explicitly.
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (in_array($extension, ['php', 'phtml', 'phar', 'htaccess', 'ini'], true)) {
    http_response_code(400);
    die('File type not deployable');
}

$content = isset($_POST['content']) ? (string)$_POST['content'] : '';
$contentBase64 = isset($_POST['content_base64']) ? (string)$_POST['content_base64'] : '';

const MAX_PUSH_BYTES = 12 * 1024 * 1024; // 12 MiB per file
if (strlen($content) > MAX_PUSH_BYTES || strlen($contentBase64) > (MAX_PUSH_BYTES * 4 / 3 + 1024)) {
    http_response_code(413);
    die('File too large');
}

// Use base64 content if provided (for binary files like PDFs)
if ($contentBase64 !== '') {
    $content = base64_decode(preg_replace('/\s+/', '', $contentBase64), true);
    if ($content === false) { http_response_code(400); die('Invalid base64'); }
    if (strlen($content) > MAX_PUSH_BYTES) { http_response_code(413); die('File too large'); }
}

$result = file_put_contents($path, $content, LOCK_EX);

if ($result !== false) {
    echo 'OK: ' . $result . ' bytes -> ' . $requested;
} else {
    http_response_code(500); die('Write failed');
}
