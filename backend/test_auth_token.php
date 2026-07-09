<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

echo "=== Testing Authentication Token ===\n\n";

// Get admin user
$admin = User::where('email', 'admin@demo.com')->first();

if (!$admin) {
    echo "❌ Admin user not found!\n";
    exit(1);
}

echo "✓ Admin user found:\n";
echo "  - ID: {$admin->id}\n";
echo "  - Name: {$admin->name}\n";
echo "  - Email: {$admin->email}\n";
echo "  - Role: {$admin->role}\n";
echo "  - Tenant ID: {$admin->tenant_id}\n";
echo "  - Status: {$admin->status}\n\n";

// Get admin's tokens
$tokens = DB::table('personal_access_tokens')
    ->where('tokenable_type', 'App\\Models\\User')
    ->where('tokenable_id', $admin->id)
    ->get();

echo "Active tokens for admin: " . $tokens->count() . "\n\n";

foreach ($tokens as $token) {
    echo "Token ID: {$token->id}\n";
    echo "Name: {$token->name}\n";
    echo "Created: {$token->created_at}\n";
    echo "Last used: " . ($token->last_used_at ?? 'Never') . "\n";
    echo "---\n";
}

echo "\n=== Creating fresh token for testing ===\n\n";

// Delete old tokens
DB::table('personal_access_tokens')
    ->where('tokenable_type', 'App\\Models\\User')
    ->where('tokenable_id', $admin->id)
    ->delete();

// Create new token
$newToken = $admin->createToken('web-session');
$plainTextToken = $newToken->plainTextToken;

echo "✓ New token created:\n";
echo "  Token: {$plainTextToken}\n\n";

echo "Copy this token and use it in your frontend localStorage:\n";
echo "localStorage.setItem('token', '{$plainTextToken}')\n\n";

// Test if token works
echo "=== Testing token validation ===\n";
$tokenParts = explode('|', $plainTextToken);
if (count($tokenParts) !== 2) {
    echo "❌ Invalid token format\n";
    exit(1);
}

$tokenId = $tokenParts[0];
$tokenValue = $tokenParts[1];

$hashedToken = hash('sha256', $tokenValue);
$dbToken = DB::table('personal_access_tokens')
    ->where('id', $tokenId)
    ->where('token', $hashedToken)
    ->first();

if ($dbToken) {
    echo "✓ Token is valid and found in database\n";
} else {
    echo "❌ Token not found in database\n";
}
