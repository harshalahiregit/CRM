<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

// Create tenant if not exists
$tenant = Tenant::firstOrCreate(
    ['slug' => 'demo-company'],
    [
        'name' => 'Demo Company',
        'subdomain' => 'demo',
        'plan' => 'starter',
        'status' => 'active',
    ]
);

// Create admin user
$admin = User::updateOrCreate(
    ['email' => 'admin@demo.com'],
    [
        'tenant_id' => $tenant->id,
        'name' => 'Admin User',
        'password' => Hash::make('admin123'),
        'role' => 'admin',
        'status' => 'active',
    ]
);

// Create HR Executive
$hrExec = User::updateOrCreate(
    ['email' => 'hr@demo.com'],
    [
        'tenant_id' => $tenant->id,
        'name' => 'HR Executive',
        'password' => Hash::make('hr123'),
        'role' => 'hr_executive',
        'status' => 'active',
    ]
);

// Create Hiring Manager
$manager = User::updateOrCreate(
    ['email' => 'manager@demo.com'],
    [
        'tenant_id' => $tenant->id,
        'name' => 'Hiring Manager',
        'password' => Hash::make('manager123'),
        'role' => 'hiring_manager',
        'status' => 'active',
    ]
);

echo "\n✅ Users created successfully!\n\n";
echo "=== Login Credentials ===\n\n";
echo "Admin:\n";
echo "  Email: admin@demo.com\n";
echo "  Password: admin123\n";
echo "  Role: admin\n\n";

echo "HR Executive:\n";
echo "  Email: hr@demo.com\n";
echo "  Password: hr123\n";
echo "  Role: hr_executive\n\n";

echo "Hiring Manager:\n";
echo "  Email: manager@demo.com\n";
echo "  Password: manager123\n";
echo "  Role: hiring_manager\n\n";

echo "Tenant: {$tenant->name} (ID: {$tenant->id})\n\n";
