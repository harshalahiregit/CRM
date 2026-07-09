<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Show which DB is being used
echo "DB path: " . config('database.connections.sqlite.database') . "\n";
echo "Env: " . app()->environment() . "\n\n";

// Login simulation
$email = 'admin@demo.com';
$pass = 'password';
$u = App\Models\User::where('email', $email)->where('role', 'admin')->first();
if (!$u) { echo "User not found!\n"; } 
else {
    echo "Found user: {$u->name}\n";
    echo "Hash check: " . (Illuminate\Support\Facades\Hash::check($pass, $u->password) ? 'PASS ✅' : 'FAIL ❌') . "\n";
    echo "Status: {$u->status}\n";
    
    // Force reset
    $u->password = Illuminate\Support\Facades\Hash::make($pass);
    $u->save();
    echo "Re-saved. New check: " . (Illuminate\Support\Facades\Hash::check($pass, $u->fresh()->password) ? 'PASS ✅' : 'FAIL ❌') . "\n";
}
