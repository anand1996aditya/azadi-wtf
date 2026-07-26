<?php
/**
 * Sync fresh protest data from protests.json → data.js
 * Called automatically at the end of update.php
 */
function syncDataJs() {
    $protestsFile = __DIR__ . '/../data/protests.json';
    $dataJsFile = __DIR__ . '/../data.js';
    
    if (!file_exists($protestsFile) || !file_exists($dataJsFile)) return false;
    
    $protests = json_decode(file_get_contents($protestsFile), true);
    $dataJs = file_get_contents($dataJsFile);
    if (!$protests || !$dataJs) return false;
    
    // Map city slugs to state codes
    $cityToState = [
        'delhi' => 'DL', 'mumbai' => 'MH', 'bangalore' => 'KA',
        'pune' => 'MH', 'hyderabad' => 'TG', 'kolkata' => 'WB',
        'jaipur' => 'RJ', 'bhopal' => 'MP'
    ];
    
    $updated = 0;
    foreach ($protests['cities'] as $slug => $city) {
        if (!isset($cityToState[$slug])) continue;
        $stateCode = $cityToState[$slug];
        
        foreach ($city['protests'] as $protest) {
            // Find and update this protest in data.js
            $name = preg_quote($protest['name'], '/');
            
            // Update dangerLevel
            $pattern = '/("' . $stateCode . '":\s*\{.*?"' . $name . '".*?dangerLevel:)"[^"]*"/s';
            $dataJs = preg_replace($pattern, '$1"' . $protest['dangerLevel'] . '"', $dataJs, 1, $count);
            if ($count) $updated++;
            
            // Update status
            $status = addslashes($protest['status']);
            $pattern = '/("' . $stateCode . '":\s*\{.*?"' . $name . '".*?status:)"[^"]*"/s';
            $dataJs = preg_replace($pattern, '$1"' . $status . '"', $dataJs, 1);
            
            // Update dangerReason
            $reason = addslashes($protest['dangerReason']);
            $pattern = '/("' . $stateCode . '":\s*\{.*?"' . $name . '".*?dangerReason:)"[^"]*"/s';
            $dataJs = preg_replace($pattern, '$1"' . $reason . '"', $dataJs, 1);
        }
    }
    
    if ($updated > 0) {
        file_put_contents($dataJsFile, $dataJs, LOCK_EX);
        logUpdate("Synced $updated protests from protests.json → data.js");
    }
    
    return $updated;
}
