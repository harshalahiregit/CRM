<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "=== TENANT ID CHECK ===\n\n";

$admin = User::where('email', 'admin@demo.com')->first();
if ($admin) {
    echo "Admin User:\n";
    echo "  Email: {$admin->email}\n";
    echo "  Tenant ID: {$admin->tenant_id}\n\n";
} else {
    echo "Admin not found!\n\n";
}

echo "Staff Members:\n";
echo "------------------------------------\n";
$staff = User::where('role', 'staff')->get();
foreach ($staff as $s) {
    echo "  {$s->email}\n";
    echo "    Tenant ID: {$s->tenant_id}\n";
    echo "    Internal Role: {$s->internal_role}\n";
    echo "    Status: {$s->status}\n\n";
}

echo "------------------------------------\n";
echo "ISSUE: Admin and Staff must have SAME tenant_id!\n";
