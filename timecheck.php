<?php
echo "Server time: " . date('Y-m-d H:i:s T') . "\n";
echo "Unix time(): " . time() . "\n";
echo "strtotime('2026-07-25T22:15:00+05:30'): " . strtotime('2026-07-25T22:15:00+05:30') . "\n";
echo "Elapsed seconds: " . (time() - strtotime('2026-07-25T22:15:00+05:30')) . "\n";
echo "Critical interval: 3600\n";
echo "Needs update: " . ((time() - strtotime('2026-07-25T22:15:00+05:30')) >= 3540 ? 'YES' : 'NO') . "\n";
