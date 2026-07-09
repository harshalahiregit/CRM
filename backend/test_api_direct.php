<?php

// Get the token from browser (paste it here)
echo "=== Direct API Test ===\n\n";
echo "Please open your browser console (F12) and run:\n";
echo "localStorage.getItem('token')\n\n";
echo "Then paste the token here and run this script with:\n";
echo "php test_api_direct.php YOUR_TOKEN_HERE\n\n";

if ($argc < 2) {
    echo "Error: No token provided\n";
    exit(1);
}

$token = $argv[1];
echo "Using token: {$token}\n\n";

// Test /stats endpoint
echo "=== Testing /api/admin/staff/stats ===\n";
$ch = curl_init('http://127.0.0.1:8000/api/admin/staff/stats');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'Content-Type: application/json',
    'Origin: http://localhost:5173'
]);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
if ($error) {
    echo "cURL Error: {$error}\n";
}
echo "Response: {$response}\n\n";

if ($httpCode !== 200) {
    echo "❌ API call failed!\n";
    
    // Try to decode error
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo "Error details:\n";
        print_r($decoded);
    }
} else {
    echo "✅ API call successful!\n";
}
