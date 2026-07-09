<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║           STAFF LOGIN SYSTEM - TEST VERIFICATION              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Test 1: Verify staff users exist
echo "TEST 1: Verify Staff Users\n";
echo str_repeat("-", 60) . "\n";

$staffUsers = User::where('role', 'staff')->get();
if ($staffUsers->count() > 0) {
    echo "✅ Found {$staffUsers->count()} staff member(s)\n\n";
    foreach ($staffUsers as $staff) {
        echo "  👤 {$staff->name}\n";
        echo "     Email: {$staff->email}\n";
        echo "     Internal Role: " . ($staff->internal_role ?? 'Not set') . "\n";
        echo "     Department: " . ($staff->department ?? 'Not set') . "\n\n";
    }
} else {
    echo "❌ No staff users found!\n\n";
}

// Test 2: Simulate login attempt
echo "\nTEST 2: Simulate Staff Login (hr@demo.com)\n";
echo str_repeat("-", 60) . "\n";

$loginEmail = 'hr@demo.com';
$loginPassword = 'password123';

// Simulate the login query (what happens when user selects "Staff / Employee")
$user = User::where('email', $loginEmail)
    ->where(function($query) {
        $query->where('role', 'staff')
              ->orWhereIn('role', ['hr_executive', 'hiring_manager']);
    })
    ->first();

if ($user) {
    echo "✅ User found: {$user->name}\n";
    echo "   Role: {$user->role}\n";
    echo "   Internal Role: " . ($user->internal_role ?? 'N/A') . "\n";
    
    if (Hash::check($loginPassword, $user->password)) {
        echo "✅ Password verified\n";
        echo "✅ LOGIN WOULD SUCCEED\n\n";
        
        echo "   Response would include:\n";
        echo "   - Token: [JWT Token]\n";
        echo "   - User ID: {$user->id}\n";
        echo "   - Name: {$user->name}\n";
        echo "   - Role: {$user->role}\n";
        echo "   - Internal Role: " . ($user->internal_role ?? 'N/A') . "\n";
    } else {
        echo "❌ Password verification failed\n";
    }
} else {
    echo "❌ User not found with staff role\n";
}

// Test 3: Check role helper methods
echo "\nTEST 3: Role Helper Methods\n";
echo str_repeat("-", 60) . "\n";

$hrUser = User::where('email', 'hr@demo.com')->first();
$managerUser = User::where('email', 'manager@demo.com')->first();

if ($hrUser) {
    echo "HR Executive (hr@demo.com):\n";
    echo "  isStaff(): " . ($hrUser->isStaff() ? '✅ true' : '❌ false') . "\n";
    echo "  isHRExecutive(): " . ($hrUser->isHRExecutive() ? '✅ true' : '❌ false') . "\n";
    echo "  isHiringManager(): " . ($hrUser->isHiringManager() ? '✅ true' : '❌ false') . "\n";
    echo "  isAdmin(): " . ($hrUser->isAdmin() ? '✅ true' : '❌ false') . "\n\n";
}

if ($managerUser) {
    echo "Hiring Manager (manager@demo.com):\n";
    echo "  isStaff(): " . ($managerUser->isStaff() ? '✅ true' : '❌ false') . "\n";
    echo "  isHRExecutive(): " . ($managerUser->isHRExecutive() ? '✅ true' : '❌ false') . "\n";
    echo "  isHiringManager(): " . ($managerUser->isHiringManager() ? '✅ true' : '❌ false') . "\n";
    echo "  isAdmin(): " . ($managerUser->isAdmin() ? '✅ true' : '❌ false') . "\n\n";
}

// Test 4: Check backward compatibility
echo "\nTEST 4: Backward Compatibility Check\n";
echo str_repeat("-", 60) . "\n";

$legacyRoles = User::whereIn('role', ['hr_executive', 'hiring_manager'])->count();
if ($legacyRoles > 0) {
    echo "⚠️  Found {$legacyRoles} user(s) with legacy roles\n";
    echo "   These should be migrated to 'staff' role with internal_role\n";
} else {
    echo "✅ No legacy roles found - all migrated successfully\n";
}

// Test 5: Summary
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST SUMMARY                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$totalStaff = User::where('role', 'staff')->count();
$withInternalRole = User::where('role', 'staff')->whereNotNull('internal_role')->count();
$withDepartment = User::where('role', 'staff')->whereNotNull('department')->count();

echo "Total Staff Members: {$totalStaff}\n";
echo "With Internal Role: {$withInternalRole}\n";
echo "With Department: {$withDepartment}\n\n";

echo "Login Credentials for Testing:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. Admin:\n";
echo "   Role: Admin | Email: admin@demo.com | Password: password123\n\n";
echo "2. HR Executive:\n";
echo "   Role: Staff / Employee | Email: hr@demo.com | Password: password123\n\n";
echo "3. Hiring Manager:\n";
echo "   Role: Staff / Employee | Email: manager@demo.com | Password: password123\n\n";

echo "✅ System ready for testing!\n";
echo "📱 Open http://localhost:5173 to test login\n\n";
