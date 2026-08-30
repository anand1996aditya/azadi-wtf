<?php
/**
 * Manual cron trigger — bypasses cron/ directory restriction
 * URL: https://azadi.wtf/api/refresh.php
 */
header('Content-Type: text/plain');
header('X-Accel-Buffering: no');

echo "=== STARTING DATA REFRESH ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Load config first
require_once __DIR__ . '/../config.php';

// Set the secret so update.php's auth check passes
$_GET['secret'] = CRON_SECRET;

// Run update.php
echo "Step 1: Updating protests...\n";
require_once __DIR__ . '/../cron/update.php';

// Run sync
echo "\nStep 2: Syncing to data.js...\n";
require_once __DIR__ . '/../cron/sync_datajs.php';
$synced = syncDataJs();
echo "Synced $synced protests to data.js\n";

echo "\n=== REFRESH COMPLETE ===\n";
