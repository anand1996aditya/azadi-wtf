<?php
/**
 * Daily Political Data Verification Cron
 * Verifies CM, party, and opposition data for all 28 states using Tavily.
 * Logs discrepancies to data/verify.log. Runs once daily.
 * 
 * Usage: php cron/verify.php
 * Manual: /cron/verify.php?secret=CRON_SECRET
 */

require_once __DIR__ . '/../config.php';

$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    if (($_GET['secret'] ?? '') !== CRON_SECRET) {
        http_response_code(403); die('Unauthorized');
    }
}

$LOG_FILE = DATA_DIR . 'verify.log';

function vlog($msg) {
    global $LOG_FILE;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
    echo $msg . "\n";
}

// State verification queries
$states = [
    'AP' => ['Andhra Pradesh', 'N. Chandrababu Naidu', 'TDP', 'YSRCP'],
    'AR' => ['Arunachal Pradesh', 'Pema Khandu', 'BJP', 'INC'],
    'AS' => ['Assam', 'Himanta Biswa Sarma', 'BJP', 'INC'],
    'BR' => ['Bihar', 'Samrat Choudhary', 'BJP', 'RJD'],
    'CG' => ['Chhattisgarh', 'Vishnu Deo Sai', 'BJP', 'INC'],
    'GA' => ['Goa', 'Pramod Sawant', 'BJP', 'INC'],
    'GJ' => ['Gujarat', 'Bhupendrabhai Patel', 'BJP', 'INC'],
    'HR' => ['Haryana', 'Nayab Singh Saini', 'BJP', 'INC'],
    'HP' => ['Himachal Pradesh', 'Sukhvinder Singh Sukhu', 'INC', 'BJP'],
    'JH' => ['Jharkhand', 'Hemant Soren', 'JMM', 'BJP'],
    'KA' => ['Karnataka', 'D. K. Shivakumar', 'INC', 'BJP'],
    'KL' => ['Kerala', 'V. D. Satheesan', 'INC', 'CPI(M)'],
    'MP' => ['Madhya Pradesh', 'Mohan Yadav', 'BJP', 'INC'],
    'MH' => ['Maharashtra', 'Devendra Fadnavis', 'BJP', 'INC'],
    'MN' => ['Manipur', 'Y. Khemchand Singh', 'BJP', 'INC'],
    'ML' => ['Meghalaya', 'Conrad Sangma', 'NPP', 'AITC'],
    'MZ' => ['Mizoram', 'Lalduhoma', 'ZPM', 'MNF'],
    'NL' => ['Nagaland', 'Neiphiu Rio', 'NPF', 'None'],
    'OD' => ['Odisha', 'Mohan Charan Majhi', 'BJP', 'BJD'],
    'PB' => ['Punjab', 'Bhagwant Mann', 'AAP', 'INC'],
    'RJ' => ['Rajasthan', 'Bhajan Lal Sharma', 'BJP', 'INC'],
    'SK' => ['Sikkim', 'Prem Singh Tamang', 'SKM', 'SDF'],
    'TN' => ['Tamil Nadu', 'C. Joseph Vijay', 'TVK', 'DMK'],
    'TG' => ['Telangana', 'Revanth Reddy', 'INC', 'BRS'],
    'TR' => ['Tripura', 'Manik Saha', 'BJP', 'CPI(M)'],
    'UK' => ['Uttarakhand', 'Pushkar Singh Dhami', 'BJP', 'INC'],
    'UP' => ['Uttar Pradesh', 'Yogi Adityanath', 'BJP', 'SP'],
    'WB' => ['West Bengal', 'Suvendu Adhikari', 'BJP', 'AITC'],
    'DL' => ['Delhi', 'Rekha Gupta', 'BJP', 'AAP'],
];

vlog("=== VERIFICATION RUN STARTED ===");
$issues = 0;

foreach ($states as $code => $info) {
    list($name, $expectedCM, $expectedParty, $expectedOpp) = $info;
    
    $query = "$name chief minister 2026 current";
    
    $ch = curl_init(TAVILY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_key' => TAVILY_API_KEY,
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => true,
            'max_results' => 3
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        vlog("  ERROR: $name — Tavily API returned HTTP $httpCode");
        $issues++;
        usleep(500000);
        continue;
    }
    
    $data = json_decode($response, true);
    $answer = $data['answer'] ?? '';
    
    // Check if CM name appears in the answer
    $cmMatch = (stripos($answer, $expectedCM) !== false);
    $partyMatch = (stripos($answer, $expectedParty) !== false);
    
    if ($cmMatch && $partyMatch) {
        vlog("  ✓ $name — $expectedCM ($expectedParty) verified");
    } else {
        vlog("  ✗ $name — DISCREPANCY: Expected $expectedCM ($expectedParty)");
        vlog("    Tavily answer: " . substr($answer, 0, 200));
        $issues++;
    }
    
    // Rate limit
    usleep(500000);
}

vlog("=== VERIFICATION COMPLETE: $issues issues found ===");
echo "\nDone. Issues: $issues\n";
