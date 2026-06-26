<?php
// Quick API smoke test
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email'    => 'admin@mlacrm.com',
    'password' => 'Admin@12345',
    'role'     => 'admin',
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "cURL error: $err\n";
    exit(1);
}

echo "HTTP Status: $code\n";
$d = json_decode($resp, true);
echo "API Status:  " . ($d['status']  ?? 'unknown') . "\n";
echo "Message:     " . ($d['message'] ?? '') . "\n";

if (isset($d['data']['access_token'])) {
    echo "Token:       " . substr($d['data']['access_token'], 0, 25) . "...\n";
}
if (isset($d['data']['user'])) {
    $u = $d['data']['user'];
    echo "User:        {$u['name']} ({$u['role']}) [{$u['status']}]\n";
}
if (isset($d['data']['tenant'])) {
    echo "Tenant:      " . $d['data']['tenant']['name'] . "\n";
}
