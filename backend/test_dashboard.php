<?php
// Quick test script to debug dashboard API
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Set fake authenticated user
    $user = App\Models\User::first();
    if (!$user) {
        die("No users in database\n");
    }
    
    Auth::login($user);
    echo "Logged in as: {$user->email} (tenant_id: {$user->tenant_id})\n\n";
    
    // Call dashboard controller
    $controller = new App\Http\Controllers\Api\Hr\HRDashboardController();
    $response = $controller->index();
    
    echo "SUCCESS! Response:\n";
    echo $response->getContent();
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
}
