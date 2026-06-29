<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== ALL USERS ===\n";
$users = App\Models\User::all();
foreach ($users as $u) {
    echo "ID: {$u->id} | Email: {$u->email} | Tenant: {$u->tenant_id} | Role: {$u->role}\n";
}

echo "\n=== TENANT 2 HR DATA ===\n";
echo "Job Postings: " . App\Models\HrJobPosting::where('tenant_id', 2)->count() . "\n";
echo "Candidates: " . App\Models\HrCandidate::where('tenant_id', 2)->count() . "\n";
echo "Active Candidates: " . App\Models\HrCandidate::where('tenant_id', 2)->whereNotIn('stage', ['Hired','Rejected'])->count() . "\n";
echo "Active Job Postings: " . App\Models\HrJobPosting::where('tenant_id', 2)->where('status', 'Active')->count() . "\n";

echo "\n=== CHECKING admin@demo.com ===\n";
$admin = App\Models\User::where('email', 'admin@demo.com')->first();
if ($admin) {
    echo "Found! Tenant ID: {$admin->tenant_id}\n";
    echo "Testing dashboard for this user...\n";
    auth()->login($admin);
    
    $controller = new App\Http\Controllers\Api\Hr\HRDashboardController();
    $response = $controller->index();
    $data = json_decode($response->getContent(), true);
    
    echo "Dashboard KPIs:\n";
    echo "  - Open Positions: {$data['kpis']['open_positions']}\n";
    echo "  - Active Candidates: {$data['kpis']['active_candidates']}\n";
    echo "  - Pipeline count: " . count($data['pipeline']) . "\n";
} else {
    echo "admin@demo.com NOT FOUND!\n";
}
