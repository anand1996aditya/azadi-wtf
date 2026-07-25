<?php
/**
 * File push endpoint — deploys files to public_html.
 * Accepts: POST filename + content (text) OR content_base64 (binary).
 * Protected by CRON_SECRET. Used by deploy.sh and GitHub Actions.
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('POST only'); }
if (($_POST['secret'] ?? '') !== CRON_SECRET) { http_response_code(403); die('Unauthorized'); }

$fn = basename($_POST['file'] ?? '');
$content = $_POST['content'] ?? '';
$contentBase64 = $_POST['content_base64'] ?? '';

// Use base64 content if provided (for binary files like PDFs)
if ($contentBase64) {
    $content = base64_decode($contentBase64);
    if ($content === false) { http_response_code(400); die('Invalid base64'); }
}

// Only allow specific file types in public_html
$allowedRoot = ['guide.html','index.html','style.css','city.html','script.js','cities.html',
            'Protest Safely.pdf','guide.pdf','guide-print.html','state.html','data.js','history.html'];
$allowedCron = ['update.php'];

$path = null;
if (in_array($fn, $allowedRoot, true)) {
    $path = dirname(__DIR__) . '/' . $fn;
} elseif (in_array($fn, $allowedCron, true)) {
    $path = dirname(__DIR__) . '/cron/' . $fn;
} else {
    http_response_code(400); die('Invalid filename: ' . $fn);
}
$flags = in_array(pathinfo($fn, PATHINFO_EXTENSION), ['pdf']) ? 0 : 0;
$result = file_put_contents($path, $content, LOCK_EX);

if ($result !== false) {
    echo 'OK: ' . $result . ' bytes -> ' . $fn;
} else {
    http_response_code(500); die('Write failed');
}
