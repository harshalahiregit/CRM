<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;

echo "=== TESTING STAFF API ===\n\n";

// Login as admin
$admin = User::where('email', 'admin@demo.com')->first();
if (!$admin) {
    echo "❌ Admin not found!\n";
    exit(1);
}

echo "✅ Admin found: {$admin->email} (Tenant: {$admin->tenant_id})\n\n";

// Simulate API request
auth()->login($admin);
$request = Request::create('/api/admin/staff', 'GET');
$request->setUserResolver(function() use ($admin) {
    return $admin;
});

// Call controller
$controller = new App\Http\Controllers\Api\Admin\StaffManagementController();

try {
    echo "Calling index() method...\n";
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true);
    
    echo "\nResponse Status: " . $response->getStatusCode() . "\n";
    echo "\nResponse Data:\n";
    echo json_encode($data, JSON_PRETTY_PRINT);
    
    if (isset($data['data']['staff'])) {
        echo "\n\n✅ Staff Count: " . count($data['data']['staff']) . "\n";
    }
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n\n=== DIRECT DATABASE QUERY ===\n";
$staff = User::where('tenant_id', $admin->tenant_id)
    ->where('role', 'staff')
    ->get();

echo "Staff in tenant {$admin->tenant_id}: " . $staff->count() . "\n";
foreach ($staff as $s) {
    echo "  - {$s->name} ({$s->email})\n";
}
