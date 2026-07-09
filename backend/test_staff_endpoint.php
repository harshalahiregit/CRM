<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;

echo "=== Testing Staff Management API Endpoint ===\n\n";

// Get admin user
$admin = User::where('email', 'admin@demo.com')->first();

if (!$admin) {
    echo "❌ Admin user not found!\n";
    exit(1);
}

// Create a new token
$token = $admin->createToken('test-api')->plainTextToken;
echo "✓ Created test token: {$token}\n\n";

// Make API call using curl
$url = 'http://127.0.0.1:8000/api/admin/staff/stats';
echo "Testing endpoint: {$url}\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status Code: {$httpCode}\n";
echo "Response:\n";
echo $response . "\n\n";

// Test /staff endpoint
$url2 = 'http://127.0.0.1:8000/api/admin/staff';
echo "Testing endpoint: {$url2}\n\n";

$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'Content-Type: application/json'
]);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Status Code: {$httpCode2}\n";
echo "Response:\n";
echo $response2 . "\n\n";

// Check staff in database
echo "=== Staff in Database ===\n";
$staffCount = User::where('tenant_id', $admin->tenant_id)
    ->where('role', 'staff')
    ->count();
    
echo "Total staff members: {$staffCount}\n";

$staffMembers = User::where('tenant_id', $admin->tenant_id)
    ->where('role', 'staff')
    ->get();

foreach ($staffMembers as $staff) {
    echo "  - {$staff->name} ({$staff->email}) - {$staff->internal_role}\n";
}
