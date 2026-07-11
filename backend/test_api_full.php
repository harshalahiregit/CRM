<?php
$base = 'http://127.0.0.1:8000/api';

function apiCall($url, $method = 'GET', $data = null, $token = null) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err, 'code' => 0];
    return ['code' => $code, 'body' => json_decode($res, true)];
}

// 1. Login
echo "=== TEST 1: Login ===\n";
$r = apiCall("$base/auth/login", 'POST', ['email'=>'admin@demo.com','password'=>'password','role'=>'admin']);
echo "HTTP: {$r['code']}\n";
if ($r['code'] == 200) {
    $tok = $r['body']['data']['access_token'];
    echo "Token: " . substr($tok, 0, 20) . "...\n";
    echo "User: " . $r['body']['data']['user']['name'] . "\n\n";
} else {
    echo "FAIL: " . json_encode($r['body']) . "\n";
    exit;
}

// 2. Manpower list
echo "=== TEST 2: Manpower Requests ===\n";
$r2 = apiCall("$base/hr/manpower-requests", 'GET', null, $tok);
echo "HTTP: {$r2['code']}\n";
echo "Result: " . ($r2['code'] == 200 ? 'OK ✅' : 'FAIL ❌') . "\n";
if ($r2['code'] == 200) {
    $data = $r2['body']['data'] ?? $r2['body'];
    echo "Count: " . (is_array($data) ? count($data) : 'N/A') . " records\n\n";
} else { echo json_encode($r2['body']) . "\n\n"; }

// 3. Stats
echo "=== TEST 3: Manpower Stats ===\n";
$r3 = apiCall("$base/hr/manpower-requests/stats", 'GET', null, $tok);
echo "HTTP: {$r3['code']}\n";
echo "Result: " . ($r3['code'] == 200 ? 'OK ✅' : 'FAIL ❌') . "\n";
if ($r3['code'] == 200) {
    $d = $r3['body']['data'] ?? $r3['body'];
    echo "Stats: Total={$d['total']}, Approved={$d['approved']}, Pending_L1={$d['pending_l1']}, Pending_L2={$d['pending_l2']}\n\n";
} else { echo json_encode($r3['body']) . "\n\n"; }

// 4. HR Dashboard
echo "=== TEST 4: HR Dashboard ===\n";
$r4 = apiCall("$base/hr/dashboard", 'GET', null, $tok);
echo "HTTP: {$r4['code']}\n";
echo "Result: " . ($r4['code'] == 200 ? 'OK ✅' : 'FAIL ❌') . "\n\n";

// 5. Create test request
echo "=== TEST 5: Create Manpower Request ===\n";
$r5 = apiCall("$base/hr/manpower-requests", 'POST', [
    'department' => 'Engineering',
    'position_title' => 'Senior PHP Developer',
    'number_of_posts' => 2,
    'priority' => 'High',
    'job_type' => 'Full-time',
    'justification' => 'Need extra developers for Q3 project'
], $tok);
echo "HTTP: {$r5['code']}\n";
if ($r5['code'] == 201) {
    $d = $r5['body']['data'];
    echo "Created ID: {$d['id']}, Status: {$d['status']} ✅\n\n";
} else { echo "FAIL: " . json_encode($r5['body']) . "\n\n"; }

echo "=== ALL TESTS DONE ===\n";
