<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$u = App\Models\User::where('email','admin@demo.com')->first();
if (!$u) { echo "User not found\n"; exit; }

echo "Email: {$u->email}\n";
echo "Role: {$u->role}\n";
echo "Status: {$u->status}\n";
echo "tenant_id: {$u->tenant_id}\n\n";

// Reset password
$u->password = Illuminate\Support\Facades\Hash::make('password');
$u->status = 'active';
$u->save();
echo "Password reset to: password\n";

// Verify
$check = Illuminate\Support\Facades\Hash::check('password', $u->fresh()->password);
echo "Password verify: " . ($check ? 'OK ✅' : 'FAIL ❌') . "\n";
