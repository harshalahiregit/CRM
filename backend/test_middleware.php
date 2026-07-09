<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

echo "=== TESTING MIDDLEWARE & AUTH ===\n\n";

// Get admin user
$admin = User::where('email', 'admin@demo.com')->first();
if (!$admin) {
    echo "❌ Admin not found!\n";
    exit(1);
}

echo "Admin User:\n";
echo "  ID: {$admin->id}\n";
echo "  Email: {$admin->email}\n";
echo "  Role: {$admin->role}\n";
echo "  Tenant ID: {$admin->tenant_id}\n\n";

// Check if admin passes role check
$allowedRoles = ['admin'];
$userRole = $admin->role;

if (in_array($userRole, $allowedRoles)) {
    echo "✅ Admin role check: PASS\n";
} else {
    echo "❌ Admin role check: FAIL\n";
}

// Check staff in same tenant
echo "\nStaff in same tenant ({$admin->tenant_id}):\n";
$staff = User::where('tenant_id', $admin->tenant_id)
    ->where('role', 'staff')
    ->get();

echo "Count: {$staff->count()}\n";
foreach ($staff as $s) {
    echo "  - {$s->name} ({$s->email})\n";
    echo "    Role: {$s->role}\n";
    echo "    Internal Role: {$s->internal_role}\n";
    echo "    Status: {$s->status}\n";
}

// Generate a token for testing
echo "\n=== GENERATE TEST TOKEN ===\n";
$admin->tokens()->delete(); // Clear old tokens
$token = $admin->createToken('test-token')->plainTextToken;
echo "Token: {$token}\n\n";

echo "Use this token to test API:\n";
echo "curl -H 'Authorization: Bearer {$token}' http://127.0.0.1:8000/api/admin/staff/stats\n";
