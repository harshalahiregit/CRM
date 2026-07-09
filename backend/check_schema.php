<?php
$db = new PDO('sqlite:database/database.sqlite');
echo "=== hr_manpower_requests columns ===\n";
$cols = $db->query('PRAGMA table_info(hr_manpower_requests)')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  - " . $c['name'] . " (" . $c['type'] . ")\n";

echo "\n=== existing AIR tables ===\n";
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%air%' OR name LIKE '%approval%' OR name LIKE '%assessment%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) echo "  - $t\n";
