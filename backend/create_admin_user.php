<?php

/**
 * Create Admin User Script
 * Usage: php create_admin_user.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       Create Admin User                                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Check existing users
echo "📋 Checking existing users...\n";
$users = User::all();
echo "   Found " . $users->count() . " user(s)\n\n";

foreach ($users as $user) {
    echo "   • {$user->email} ({$user->role})\n";
}
echo "\n";

// Create admin user
$email = 'admin@demo.com';
$existingUser = User::where('email', $email)->first();

if ($existingUser) {
    echo "✓ Admin user already exists!\n";
    echo "  Email: {$existingUser->email}\n";
    echo "  Role: {$existingUser->role}\n\n";
    
    // Update password
    echo "🔄 Updating password to: password123\n";
    $existingUser->password = Hash::make('password123');
    $existingUser->save();
    echo "✓ Password updated!\n\n";
} else {
    echo "➕ Creating new admin user...\n";
    $user = User::create([
        'name' => 'Admin User',
        'email' => $email,
        'password' => Hash::make('password123'),
        'role' => 'admin',
        'tenant_id' => 1,
        'email_verified_at' => now(),
    ]);
    echo "✓ Admin user created!\n\n";
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       Login Credentials                                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";
echo "  Email:    admin@demo.com\n";
echo "  Password: password123\n";
echo "  Role:     admin\n\n";
echo "✅ You can now login at: http://localhost:5173\n\n";
