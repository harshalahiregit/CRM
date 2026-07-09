<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$users = [
    ['id' => 5,  'email' => 'admin@demo.com',   'role' => 'admin',  'name' => 'Admin User'],
    ['id' => 6,  'email' => 'hr@demo.com',       'role' => 'staff',  'name' => 'HR Executive'],
    ['id' => 7,  'email' => 'manager@demo.com',  'role' => 'staff',  'name' => 'Hiring Manager'],
    ['id' => 1,  'email' => 'admin@mlacrm.com',  'role' => 'admin',  'name' => 'MLA Admin'],
];

foreach ($users as $u) {
    $user = App\Models\User::find($u['id']);
    if ($user) {
        $user->password = Illuminate\Support\Facades\Hash::make('password');
        $user->status = 'active';
        $user->save();
        echo "✅ {$u['email']} => password: password | role: {$u['role']}\n";
    }
}
echo "\nAll passwords reset to: password\n";
