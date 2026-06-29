<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simulate authenticated request
$request = Illuminate\Http\Request::create('/api/hr/dashboard', 'GET');

// Get token from database for admin@demo.com
$token = DB::table('personal_access_tokens')
    ->where('tokenable_id', 1)
    ->orderBy('created_at', 'desc')
    ->first();

if ($token) {
    $request->headers->set('Authorization', 'Bearer ' . $token->token);
}

$response = $kernel->handle($request);

echo "Status: " . $response->getStatusCode() . "\n";
echo "Response: " . $response->getContent() . "\n";

$kernel->terminate($request, $response);
