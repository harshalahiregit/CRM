<?php
// Recreate hr_approval_history with correct schema
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Backing up existing data...\n";
$rows = $db->query('SELECT * FROM hr_approval_history')->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($rows) . " rows to preserve.\n";

echo "Dropping old table...\n";
$db->exec('DROP TABLE IF EXISTS hr_approval_history_old');
$db->exec('ALTER TABLE hr_approval_history RENAME TO hr_approval_history_old');

echo "Creating new table with correct schema...\n";
$db->exec("
CREATE TABLE hr_approval_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER,
    level VARCHAR(20) DEFAULT 'General',
    action VARCHAR(50),
    actor_id INTEGER,
    remarks TEXT,
    old_values TEXT,
    new_values TEXT,
    created_at DATETIME,
    updated_at DATETIME,
    FOREIGN KEY (request_id) REFERENCES hr_manpower_requests(id) ON DELETE CASCADE
)
");

echo "Migrating old data...\n";
$stmt = $db->prepare("
    INSERT INTO hr_approval_history (id, request_id, level, action, actor_id, remarks, old_values, new_values, created_at, updated_at)
    VALUES (?, ?, 'General', ?, ?, ?, ?, ?, ?, ?)
");

foreach ($rows as $row) {
    $stmt->execute([
        $row['id'],
        $row['manpower_request_id'] ?? $row['request_id'],
        $row['action'],
        $row['user_id'] ?? $row['actor_id'],
        $row['comment'] ?? $row['remarks'],
        $row['old_values'],
        $row['new_values'],
        $row['created_at'],
        $row['updated_at'],
    ]);
}

$count = $db->query('SELECT COUNT(*) FROM hr_approval_history')->fetchColumn();
echo "Migrated $count rows.\n";

$db->exec('DROP TABLE hr_approval_history_old');
echo "\n✅ hr_approval_history rebuilt successfully!\n";

// Verify
$cols = $db->query('PRAGMA table_info(hr_approval_history)')->fetchAll(PDO::FETCH_ASSOC);
echo "Columns: " . implode(', ', array_column($cols, 'name')) . "\n";
