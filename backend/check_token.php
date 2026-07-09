<?php
$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');

// Get admin@demo.com token
$tokens = $db->query("SELECT pat.token, pat.tokenable_id, u.email, u.role, u.tenant_id 
    FROM personal_access_tokens pat 
    JOIN users u ON u.id = pat.tokenable_id 
    WHERE u.email = 'admin@demo.com' 
    ORDER BY pat.created_at DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);

if (empty($tokens)) {
    echo "No token found for admin@demo.com - user needs to login first\n";
} else {
    $t = $tokens[0];
    echo "Token found for: {$t['email']} (tenant: {$t['tenant_id']})\n";
    echo "Token ID hash: " . substr($t['token'], 0, 20) . "...\n";
}

// Check the personal_access_tokens table
$allTokens = $db->query("SELECT id, tokenable_id, name, created_at FROM personal_access_tokens ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\nLatest tokens:\n";
foreach ($allTokens as $t) {
    echo "  ID:{$t['id']} user_id:{$t['tokenable_id']} name:{$t['name']} created:{$t['created_at']}\n";
}
