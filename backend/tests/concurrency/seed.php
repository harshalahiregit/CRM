<?php
/**
 * Seed the race fixture THROUGH THE REAL SERVICES — D-137.
 *
 * The first version of this harness went in as raw INSERT/UPDATE against the
 * shared dev database: past the services, past the audit trail, past the events,
 * past Fleet's own status observer, and one statement wrote directly into P2's
 * `vehicles` table. It left a truck reading AVAILABLE while a live assignment
 * held it — the exact inconsistency we log as a defect when we find it.
 *
 * So: a throwaway container, and every row created the way the product creates
 * it. Nothing here touches a table directly.
 */
require '/home/mohammad-raza/Desktop/sangoe_crm/backend/vendor/autoload.php';
$app = require_once '/home/mohammad-raza/Desktop/sangoe_crm/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domains\Fleet\Services\VehicleService;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Transport\TransportOrderService;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;

$tenant = Tenant::create([
    'name' => 'Race', 'slug' => 'race', 'subdomain' => 'race',
    'plan' => 'professional', 'status' => 'active',
]);

$actor = User::create([
    'tenant_id' => $tenant->id, 'name' => 'Race Dispatcher', 'email' => 'race@x.test',
    'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
]);

$client = App\Models\Customer\Client::create([
    'tenant_id' => $tenant->id, 'company' => 'Race Ltd', 'active' => true,
]);

// Fleet's own service — so the status observer fires and the vehicle is the
// shape allocation actually meets.
$vehicle = app(VehicleService::class)->create([
    'registration_number' => 'MH01RACE01',
    'vehicle_type' => 'truck', 'ownership_type' => 'owned', 'capacity_tonnes' => 25,
], $tenant->id, $actor->id);

$orders = app(TransportOrderService::class);
$trips = app(TransportTripService::class);
$tripIds = [];

foreach (['A', 'B'] as $suffix) {
    $order = $orders->create([
        'customer_id' => $client->id,
        'pickup_location' => ['address' => 'Mundra'],
        'delivery_location' => ['address' => 'Pune'],
        'service_type' => 'Container Haulage',
        'required_at' => now()->addDays(2)->toDateTimeString(),
    ], $tenant->id, $actor);

    $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

    $trip = $trips->createFromOrder($order->id, [], $tenant->id, $actor);
    $trip->forceFill(['status' => TripStatus::APPROVED])->save();

    $tripIds[] = $trip->id;
}

echo json_encode([
    'tenant' => $tenant->id, 'actor' => $actor->id,
    'vehicle' => $vehicle->id, 'trips' => $tripIds,
])."\n";
