<?php
$loginData = json_encode(['email' => 'admin@demo.com', 'password' => 'Admin@123', 'role' => 'admin']);
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $loginData,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$loginRes = json_decode(curl_exec($ch), true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login HTTP: $httpCode\n";
if (!isset($loginRes['data']['access_token'])) {
    echo "FAIL: " . json_encode($loginRes) . "\n";
    exit;
}
$token = $loginRes['data']['access_token'];
echo "OK: " . $loginRes['data']['user']['name'] . " (tenant: " . $loginRes['data']['user']['tenant_id'] . ")\n\n";

$endpoints = [
    '/api/admin/staff/stats',
    '/api/admin/staff/designations',
    '/api/admin/staff/departments',
    '/api/admin/staff',
    '/api/dashboard',
];
foreach ($endpoints as $ep) {
    $ch = curl_init('http://127.0.0.1:8000' . $ep);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $dec = json_decode($body, true);
    echo "[$code] $ep => " . ($dec['status'] ?? 'unknown') . "\n";
    if ($code !== 200) echo "  -> $body\n";
}
echo "\nDone!\n";
