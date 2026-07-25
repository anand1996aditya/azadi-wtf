<?php
/**
 * Political Data + Protest Guide Verification Cron
 * Verifies CM/party data for all 28 states AND checks key guide references.
 * Uses Tavily for cross-verification. Logs to data/verify.log.
 * 
 * Schedule: Every 3 days (twice weekly)
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

// ==========================================
// GUIDE CONTENT VERIFICATION
// Checks key legal references, helplines, and government bodies
// mentioned in the protest guide for continued accuracy.
// ==========================================

vlog("");
vlog("--- GUIDE CONTENT VERIFICATION ---");

$guideChecks = [
    [
        'name' => 'NALSA Helpline (15100)',
        'query' => 'NALSA helpline number 15100 India legal services authority current 2026',
        'keywords' => ['15100', 'NALSA', 'legal services'],
        'critical' => true
    ],
    [
        'name' => 'NHRC Helpline (14433)',
        'query' => 'NHRC helpline number 14433 India human rights commission current',
        'keywords' => ['14433', 'NHRC', 'human rights'],
        'critical' => true
    ],
    [
        'name' => 'Article 19(1)(a) — Freedom of Speech',
        'query' => 'Article 19 Indian Constitution freedom of speech 2026 current valid',
        'keywords' => ['Article 19', 'freedom of speech', 'Constitution'],
        'critical' => true
    ],
    [
        'name' => 'BNSS Section 163 (formerly CrPC 144)',
        'query' => 'BNSS Section 163 prohibitory orders India 2026 replaced CrPC 144',
        'keywords' => ['Section 163', 'BNSS', 'prohibitory'],
        'critical' => true
    ],
    [
        'name' => 'DLSA — District Legal Services Authority',
        'query' => 'District Legal Services Authority DLSA India free legal aid 2026 current',
        'keywords' => ['DLSA', 'District Legal Services', 'free legal aid'],
        'critical' => true
    ],
    [
        'name' => 'Supreme Court Legal Services Committee',
        'query' => 'Supreme Court Legal Services Committee India contact number 2026',
        'keywords' => ['Supreme Court', 'Legal Services Committee'],
        'critical' => false
    ],
    [
        'name' => 'State Human Rights Commission (SHRC)',
        'query' => 'State Human Rights Commission India police misconduct complaint 2026',
        'keywords' => ['SHRC', 'State Human Rights Commission', 'police misconduct'],
        'critical' => false
    ]
];

foreach ($guideChecks as $check) {
    $ch = curl_init(TAVILY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_key' => TAVILY_API_KEY,
            'query' => $check['query'],
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
        vlog("  ERROR: {$check['name']} — Tavily API HTTP $httpCode");
        $issues++;
        usleep(500000);
        continue;
    }
    
    $data = json_decode($response, true);
    $answer = $data['answer'] ?? '';
    
    // Check if any keyword appears in the answer
    $found = false;
    foreach ($check['keywords'] as $kw) {
        if (stripos($answer, $kw) !== false) {
            $found = true;
            break;
        }
    }
    
    if ($found) {
        vlog("  ✓ {$check['name']} — verified");
    } else {
        $flag = $check['critical'] ? 'CRITICAL' : 'WARNING';
        vlog("  ✗ {$check['name']} — $flag: Reference may be outdated or changed");
        vlog("    Tavily: " . substr($answer, 0, 200));
        $issues++;
    }
    
    usleep(500000);
}

vlog("=== VERIFICATION COMPLETE: $issues issues found ===");
echo "\nDone. Issues: $issues\n";
