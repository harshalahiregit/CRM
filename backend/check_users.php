<?php
$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$rows = $db->query('SELECT id, name, email, role, status, tenant_id FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo "Total users: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID:{$r['id']} | Role:{$r['role']} | Email:{$r['email']} | Tenant:{$r['tenant_id']} | Status:{$r['status']}\n";
}
$staff = array_filter($rows, fn($r) => $r['role'] === 'staff');
echo "\nStaff count: " . count($staff) . "\n";
$admin = array_filter($rows, fn($r) => $r['role'] === 'admin');
echo "Admin count: " . count($admin) . "\n";
