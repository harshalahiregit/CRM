<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "=== STAFF ROLE VERIFICATION ===\n\n";

echo "All Users:\n";
echo str_repeat("-", 100) . "\n";
printf("%-4s | %-25s | %-15s | %-20s | %-15s\n", "ID", "Email", "Role", "Internal Role", "Department");
echo str_repeat("-", 100) . "\n";

$users = User::all();
foreach ($users as $user) {
    printf(
        "%-4s | %-25s | %-15s | %-20s | %-15s\n",
        $user->id,
        $user->email,
        $user->role,
        $user->internal_role ?? 'N/A',
        $user->department ?? 'N/A'
    );
}

echo "\n" . str_repeat("=", 100) . "\n\n";

echo "Staff Members Only:\n";
echo str_repeat("-", 100) . "\n";

$staffMembers = User::where('role', 'staff')->get();
if ($staffMembers->isEmpty()) {
    echo "No staff members found.\n";
} else {
    foreach ($staffMembers as $staff) {
        echo "• {$staff->name} ({$staff->email})\n";
        echo "  - Internal Role: " . ($staff->internal_role ?? 'Not set') . "\n";
        echo "  - Department: " . ($staff->department ?? 'Not set') . "\n";
        echo "  - Designation: " . ($staff->designation ?? 'Not set') . "\n";
        echo "  - Status: {$staff->status}\n\n";
    }
}

echo "\n" . str_repeat("=", 100) . "\n\n";

echo "Login Test Summary:\n";
echo "To login as staff, use:\n";
echo "  Role: Staff / Employee (on login page)\n";
echo "  Email: hr@demo.com OR manager@demo.com\n";
echo "  Password: password123\n\n";

echo "Internal Roles Available:\n";
$internalRoles = User::whereNotNull('internal_role')->pluck('internal_role')->unique();
if ($internalRoles->isEmpty()) {
    echo "  - No internal roles assigned yet\n";
} else {
    foreach ($internalRoles as $role) {
        echo "  - {$role}\n";
    }
}
