<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "=== Updating HR User Passwords ===\n\n";

// Update Admin
$admin = User::where('email', 'admin@demo.com')->first();
if ($admin) {
    $admin->update(['password' => Hash::make('password123')]);
    echo "✅ Admin updated\n";
    echo "   Email: admin@demo.com\n";
    echo "   Password: password123\n";
    echo "   Role: {$admin->role}\n\n";
} else {
    echo "❌ Admin not found\n\n";
}

// Update HR Executive
$hr = User::where('email', 'hr@demo.com')->first();
if ($hr) {
    $hr->update(['password' => Hash::make('password123')]);
    echo "✅ HR Executive updated\n";
    echo "   Email: hr@demo.com\n";
    echo "   Password: password123\n";
    echo "   Role: {$hr->role}\n\n";
} else {
    echo "❌ HR Executive not found\n\n";
}

// Update Hiring Manager
$manager = User::where('email', 'manager@demo.com')->first();
if ($manager) {
    $manager->update(['password' => Hash::make('password123')]);
    echo "✅ Hiring Manager updated\n";
    echo "   Email: manager@demo.com\n";
    echo "   Password: password123\n";
    echo "   Role: {$manager->role}\n\n";
} else {
    echo "❌ Hiring Manager not found\n\n";
}

echo "=== Summary ===\n";
echo "All users now have password: password123\n";
echo "Login page now supports HR roles!\n";
