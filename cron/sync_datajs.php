<?php
/**
 * Sync fresh protest data from protests.json → data.js
 * Called automatically at the end of update.php
 * 
 * Now supports ALL 36 regions and handles ADD/UPDATE/REMOVE of protests.
 */
function syncDataJs() {
    $protestsFile = __DIR__ . '/../data/protests.json';
    $dataJsFile   = __DIR__ . '/../data.js';
    
    if (!file_exists($protestsFile) || !file_exists($dataJsFile)) return false;
    
    $protests = json_decode(file_get_contents($protestsFile), true);
    $dataJs   = file_get_contents($dataJsFile);
    if (!$protests || !$dataJs) return false;
    
    // Full city-to-state mapping (37 cities → 36 states, Pune + Mumbai both → MH)
    $cityToState = defined('CITY_TO_STATE') ? CITY_TO_STATE : [
        'delhi'                  => 'DL', 'mumbai'       => 'MH',
        'pune'                   => 'MH', 'bangalore'    => 'KA',
        'lucknow'                => 'UP', 'kolkata'      => 'WB',
        'bhopal'                 => 'MP', 'hyderabad'    => 'TG',
        'chennai'                => 'TN', 'jaipur'       => 'RJ',
        'ahmedabad'              => 'GJ', 'patna'        => 'BR',
        'amaravati'              => 'AP', 'bhubaneswar'  => 'OD',
        'thiruvananthapuram'     => 'KL', 'chandigarh-pb'=> 'PB',
        'chandigarh-hr'          => 'HR', 'guwahati'     => 'AS',
        'srinagar'               => 'JK', 'ranchi'       => 'JH',
        'raipur'                 => 'CG', 'shimla'       => 'HP',
        'dehradun'               => 'UK', 'panaji'       => 'GA',
        'agartala'               => 'TR', 'imphal'       => 'MN',
        'shillong'               => 'ML', 'aizawl'       => 'MZ',
        'itanagar'               => 'AR', 'kohima'       => 'NL',
        'gangtok'                => 'SK', 'puducherry'   => 'PY',
        'leh'                    => 'LD', 'chandigarh-ut'=> 'CH',
        'port-blair'             => 'AN', 'kavaratti'    => 'LDK',
        'silvassa'               => 'DD'
    ];
    
    // Collect all protests per state from protests.json
    $stateProtests = []; // stateCode → [protests from all mapped cities]
    foreach ($protests['cities'] as $slug => $city) {
        if (!isset($cityToState[$slug])) continue;
        $sc = $cityToState[$slug];
        if (!isset($stateProtests[$sc])) $stateProtests[$sc] = [];
        foreach ($city['protests'] as $p) {
            $stateProtests[$sc][] = $p;
        }
    }
    
    $updated = 0;
    $replacements = []; // collect: [start, oldLen, newStr]
    
    // Iterate EVERY known state, not only the ones present now. A state whose
    // protests have gone must be emptied, or data.js keeps ghosts forever. (10 Oct 2026)
    $stateCodes = array_values(array_unique(array_merge(
        array_keys($stateProtests),
        array_values($cityToState)
    )));
    foreach ($stateCodes as $stateCode) {
        $protests = $stateProtests[$stateCode] ?? [];
        
        // Build the new protests array in data.js JSON format (double-quoted keys)
        $protestBlocks = [];
        foreach ($protests as $p) {
            $protestBlocks[] = formatProtestForDataJs($p);
        }
        $newProtestsJson = "[\n" . implode(",\n", $protestBlocks) . "\n    ]";
        
        // Find the state code entry
        $stateKey = '"' . $stateCode . '":';
        $statePos = strpos($dataJs, $stateKey);
        if ($statePos === false) continue;
        
        // Find "protests": [ after the state key
        $protestsKeyword = '"protests":';
        $protestsPos = strpos($dataJs, $protestsKeyword, $statePos);
        if ($protestsPos === false) continue;
        
        // Find the opening [ after "protests":
        $bracketStart = strpos($dataJs, '[', $protestsPos);
        if ($bracketStart === false) continue;
        
        // Count brackets to find matching ]
        $depth = 1;
        $pos = $bracketStart + 1;
        $len = strlen($dataJs);
        while ($pos < $len && $depth > 0) {
            $ch = $dataJs[$pos];
            if ($ch === '[') $depth++;
            elseif ($ch === ']') $depth--;
            $pos++;
        }
        if ($depth !== 0) continue;
        $bracketEnd = $pos;
        
        // Store replacement: inner content of brackets
        $oldInner = substr($dataJs, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
        $newInner = substr($newProtestsJson, 1); // skip opening [
        $replacements[] = ['start' => $bracketStart + 1, 'oldLen' => strlen($oldInner), 'newStr' => $newInner];
        
        // Also update totalProtests count for this state
        $totalPos = strpos($dataJs, '"totalProtests":', $statePos);
        if ($totalPos !== false && $totalPos < $protestsPos) {
            $numStart = $totalPos + strlen('"totalProtests":');
            while ($numStart < $len && ($dataJs[$numStart] === ' ' || $dataJs[$numStart] === "\t")) $numStart++;
            $numEnd = $numStart;
            while ($numEnd < $len && ctype_digit($dataJs[$numEnd])) $numEnd++;
            $replacements[] = ['start' => $numStart, 'oldLen' => $numEnd - $numStart, 'newStr' => (string) count($protests)];
        }
        
        $updated += count($protests);
    }
    
    // Apply replacements from end to start (so earlier positions stay valid)
    usort($replacements, function($a, $b) { return $b['start'] - $a['start']; });
    foreach ($replacements as $r) {
        $dataJs = substr_replace($dataJs, $r['newStr'], $r['start'], $r['oldLen']);
    }
    
    if ($updated > 0) {
        file_put_contents($dataJsFile, $dataJs, LOCK_EX);
        if (function_exists('logUpdate')) {
            logUpdate("Synced $updated protests from protests.json → data.js across " . count($stateProtests) . " states");
        }
    }
    
    return $updated;
}

/**
 * Format a protest object from protests.json into the data.js JSON format.
 * data.js uses double-quoted JSON keys inside the JS object.
 */
function formatProtestForDataJs($p) {
    $detailStrs = [];
    foreach (($p['details'] ?? ['No details available']) as $d) {
        $detailStrs[] = '"' . addcslashes($d, '"\\' . "\n\r") . '"';
    }
    
    $trendHistory = isset($p['trendHistory']) ? json_encode($p['trendHistory'], JSON_UNESCAPED_UNICODE) : '[]';
    
    // Ensure double-quoted keys match data.js format
    $parts = [];
    $parts[] = '    "name": "'          . addcslashes($p['name'] ?? '', '"\\' . "\n\r") . '"';
    $parts[] = '    "location": "'      . addcslashes($p['location'] ?? '', '"\\' . "\n\r") . '"';
    $parts[] = '    "cause": "'         . addcslashes($p['cause'] ?? '', '"\\' . "\n\r") . '"';
    $parts[] = '    "dangerLevel": "'   . ($p['dangerLevel'] ?? 'low') . '"';
    $parts[] = '    "dangerReason": "'  . addcslashes($p['dangerReason'] ?? '', '"\\' . "\n\r") . '"';
    $parts[] = '    "status": "'         . addcslashes($p['status'] ?? '', '"\\' . "\n\r") . '"';
    $parts[] = '    "details": ['       . implode(', ', $detailStrs) . ']';
    $parts[] = '    "trendHistory": '  . $trendHistory;
    
    return "  {\n" . implode(",\n", $parts) . "\n  }";
}
