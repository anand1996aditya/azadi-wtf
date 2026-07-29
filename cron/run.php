<?php
/**
 * Master Cron — azadi.wtf
 * Runs sequentially: protest updates → verification → data.js sync
 * Single cron entry covers everything. Frees up a slot.
 * 
 * Schedule: every 4 hours
 * CLI: php cron/run.php
 */

// 1. Update protests (Tavily + DeepSeek)
echo "=== PROTEST UPDATES ===\n";
require __DIR__ . '/update.php';

echo "\n";

// 2. Verify news links (every 4h — Node.js script)
echo "=== NEWS LINK VERIFICATION ===\n";
$verifyJs = __DIR__ . '/verify_news.js';
if (file_exists($verifyJs)) {
    $output = shell_exec('node ' . escapeshellarg($verifyJs) . ' 2>&1');
    echo $output;
} else {
    echo "verify_news.js not found\n";
}

echo "\n";

// 3. Verify political data + guide content (once daily — only runs at midnight UTC)
$hour = (int) date('H');
if ($hour >= 0 && $hour <= 2) {
    echo "=== POLITICAL VERIFICATION ===\n";
    require __DIR__ . '/verify.php';
    echo "\n";
} else {
    echo "=== POLITICAL VERIFICATION SKIPPED (not midnight window) ===\n\n";
}

echo "All tasks complete.\n";
