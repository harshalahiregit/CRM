<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

echo "=== Testing Sanctum Authentication ===\n\n";

// Get admin user
$admin = User::where('email', 'admin@demo.com')->first();

if (!$admin) {
    echo "❌ Admin user not found!\n";
    exit(1);
}

echo "✓ Admin user found: {$admin->name} (ID: {$admin->id})\n";
echo "  Role: {$admin->role}\n";
echo "  Tenant ID: {$admin->tenant_id}\n\n";

// Check existing tokens
$existingTokens = PersonalAccessToken::where('tokenable_type', 'App\\Models\\User')
    ->where('tokenable_id', $admin->id)
    ->get();

echo "Existing tokens: " . $existingTokens->count() . "\n";
foreach ($existingTokens as $token) {
    echo "  - Token ID {$token->id}: {$token->name} (created: {$token->created_at})\n";
}

echo "\n=== Creating New Test Token ===\n\n";

// Delete old tokens
PersonalAccessToken::where('tokenable_type', 'App\\Models\\User')
    ->where('tokenable_id', $admin->id)
    ->delete();

echo "✓ Old tokens deleted\n";

// Create new token
$tokenResult = $admin->createToken('browser-session');
$plainTextToken = $tokenResult->plainTextToken;
$tokenModel = $tokenResult->accessToken;

echo "✓ New token created:\n";
echo "  Token ID: {$tokenModel->id}\n";
echo "  Plain text: {$plainTextToken}\n\n";

// Verify token works
echo "=== Verifying Token ===\n\n";

[$id, $token] = explode('|', $plainTextToken, 2);
$hashedToken = hash('sha256', $token);

$foundToken = PersonalAccessToken::find($id);

if ($foundToken && hash_equals($foundToken->token, $hashedToken)) {
    echo "✓ Token verified successfully!\n";
    echo "  Belongs to user: {$foundToken->tokenable_id}\n";
    echo "  Token name: {$foundToken->name}\n\n";
    
    // Test authentication
    echo "=== Testing Authentication ===\n\n";
    
    $user = $foundToken->tokenable;
    if ($user) {
        echo "✓ User retrieved from token:\n";
        echo "  Name: {$user->name}\n";
        echo "  Email: {$user->email}\n";
        echo "  Role: {$user->role}\n";
        echo "  Tenant ID: {$user->tenant_id}\n\n";
        
        if ($user->role === 'admin') {
            echo "✓ User has admin role - can access Staff Management\n\n";
        } else {
            echo "❌ User is not admin - cannot access Staff Management\n\n";
        }
    } else {
        echo "❌ Could not retrieve user from token\n\n";
    }
} else {
    echo "❌ Token verification failed!\n\n";
}

echo "=== Test API Call ===\n\n";

// Make a real API call
$ch = curl_init('http://127.0.0.1:8000/api/admin/staff/stats');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $plainTextToken,
    'Accept: application/json',
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "API Call: GET /api/admin/staff/stats\n";
echo "HTTP Status: {$httpCode}\n";
echo "Response: {$response}\n\n";

if ($httpCode === 200) {
    echo "✅ API CALL SUCCESSFUL!\n\n";
    echo "==============================================\n";
    echo "USE THIS TOKEN IN YOUR BROWSER:\n";
    echo "==============================================\n\n";
    echo "1. Open browser console (F12)\n";
    echo "2. Run this command:\n\n";
    echo "localStorage.setItem('token', '{$plainTextToken}')\n";
    echo "window.location.reload()\n\n";
    echo "==============================================\n";
} else {
    echo "❌ API CALL FAILED!\n";
    echo "This indicates a server-side issue.\n\n";
}
