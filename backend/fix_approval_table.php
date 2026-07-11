<?php
// Fix hr_approval_history table — add new columns if missing, migrate data
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Checking hr_approval_history columns ===\n";
$cols = $db->query('PRAGMA table_info(hr_approval_history)')->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
echo "Existing: " . implode(', ', $colNames) . "\n\n";

// Add missing columns safely
$toAdd = [
    'request_id'  => 'INTEGER',
    'level'       => "VARCHAR(20) DEFAULT 'General'",
    'actor_id'    => 'INTEGER',
    'remarks'     => 'TEXT',
];

foreach ($toAdd as $col => $type) {
    if (!in_array($col, $colNames)) {
        $db->exec("ALTER TABLE hr_approval_history ADD COLUMN $col $type");
        echo "Added column: $col\n";
    } else {
        echo "Column already exists: $col\n";
    }
}

// Migrate data from old columns to new columns
echo "\n=== Migrating data ===\n";

// request_id from manpower_request_id
if (in_array('manpower_request_id', $colNames)) {
    $db->exec("UPDATE hr_approval_history SET request_id = manpower_request_id WHERE request_id IS NULL AND manpower_request_id IS NOT NULL");
    echo "Migrated manpower_request_id -> request_id\n";
}

// actor_id from user_id
if (in_array('user_id', $colNames)) {
    $db->exec("UPDATE hr_approval_history SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL");
    echo "Migrated user_id -> actor_id\n";
}

// remarks from comment
if (in_array('comment', $colNames)) {
    $db->exec("UPDATE hr_approval_history SET remarks = comment WHERE remarks IS NULL AND comment IS NOT NULL");
    echo "Migrated comment -> remarks\n";
}

// Check final state
echo "\n=== Final columns ===\n";
$cols = $db->query('PRAGMA table_info(hr_approval_history)')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  - {$c['name']} ({$c['type']})\n";

$count = $db->query('SELECT COUNT(*) FROM hr_approval_history')->fetchColumn();
echo "\nTotal rows: $count\n";
echo "\n✅ Done! hr_approval_history is fixed.\n";
