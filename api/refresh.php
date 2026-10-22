<?php
/**
 * Manual cron trigger — bypasses cron/ directory restriction
 * URL: https://azadi.wtf/api/refresh.php
 *
 * This endpoint runs the whole paid update chain, so it needs the same secret
 * as the cron endpoints. Send it in a header, never in the URL:
 *   curl -H "Authorization: Bearer $AZADI_CRON_SECRET" https://azadi.wtf/api/refresh.php
 * api/.htaccess also blocks this file on the live host as defense in depth.
 */
header('Content-Type: text/plain');
header('X-Accel-Buffering: no');

// Load config first
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../cron/auth.php';

$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    azadi_cron_require_auth();
}

echo "=== STARTING DATA REFRESH ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// This file already authenticated the request, so update.php must not ask for a
// second secret. The constant is defined only here, in trusted server code.
define('AZADI_INTERNAL_CRON', true);

// Run update.php
echo "Step 1: Updating protests...\n";
require_once __DIR__ . '/../cron/update.php';

// Run sync
echo "\nStep 2: Syncing to data.js...\n";
require_once __DIR__ . '/../cron/sync_datajs.php';
$synced = syncDataJs();
echo "Synced $synced protests to data.js\n";

echo "\n=== REFRESH COMPLETE ===\n";
