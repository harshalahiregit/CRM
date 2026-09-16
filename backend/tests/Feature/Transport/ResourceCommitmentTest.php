<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\ResourceCommitmentService;
use App\Support\Transport\AssignmentStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Why is this vehicle busy, and when does it come free?"
 *
 * The sentences are the product here, so they are asserted literally. A test
 * that only checked a date existed would pass while the screen said something
 * misleading, and misleading is the one outcome this feature cannot have.
 */
class ResourceCommitmentTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private ResourceCommitmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }

        $this->service = app(ResourceCommitmentService::class);
    }

    private function trip(int $tenantId = self::TENANT_A, ?string $arrival = '+3 days'): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 7,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id, 'customer_id' => 7,
            'trip_number' => TransportTrip::nextLocalNumber($tenantId), 'route' => 'JNPT → Bhiwandi',
        ]);

        $trip->forceFill([
            'status'             => TripStatus::ALLOCATED,
            'planned_arrival_at' => $arrival ? now()->modify($arrival) : null,
        ])->save();

        return $trip->fresh();
    }

    private function vehicle(int $tenantId = self::TENANT_A): TransportVehicle
    {
        return TransportVehicle::create([
            'tenant_id' => $tenantId, 'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'capacity_tonnes' => 25,
        ]);
    }

    private function driver(int $tenantId = self::TENANT_A): TransportDriver
    {
        return TransportDriver::create([
            'tenant_id' => $tenantId, 'name' => 'Driver '.self::uniqueSeq(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
        ]);
    }

    private function assign(TransportTrip $trip, ?TransportVehicle $v, ?TransportDriver $d): TripAssignment
    {
        return TripAssignment::create([
            'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id,
            'vehicle_id' => $v?->id, 'driver_id' => $d?->id,
            'status' => AssignmentStatus::ASSIGNED, 'assigned_at' => now(),
        ]);
    }

    /* ══════════ the sentence ══════════ */

    public function test_a_committed_vehicle_reports_the_trip_and_when_it_is_back(): void
    {
        $trip    = $this->trip(arrival: '+3 days');
        $vehicle = $this->vehicle();
        $this->assign($trip, $vehicle, null);

        $c = $this->service->forTenant(self::TENANT_A)['vehicles'][$vehicle->id];

        $this->assertSame($trip->trip_number, $c['trip_number']);
        $this->assertSame(3, $c['free_in_days']);
        $this->assertSame(
            'On '.$trip->trip_number.' — back in 3 days, '.now()->addDays(3)->format('j M'),
            $c['sentence'],
        );
    }

    public function test_tomorrow_and_today_read_as_words_not_as_a_count(): void
    {
        $t1 = $this->trip(arrival: '+1 day');
        $v1 = $this->vehicle();
        $this->assign($t1, $v1, null);

        $t2 = $this->trip(arrival: 'now');
        $v2 = $this->vehicle();
        $this->assign($t2, $v2, null);

        $all = $this->service->forTenant(self::TENANT_A)['vehicles'];

        $this->assertStringContainsString('back tomorrow', $all[$v1->id]['sentence']);
        $this->assertStringContainsString('back today', $all[$v2->id]['sentence']);
    }

    public function test_an_overdue_trip_says_so_instead_of_reading_as_free(): void
    {
        // Clamping this to 0 would make an overdue trip look finished, which is
        // the opposite of what a dispatcher needs to see.
        $trip    = $this->trip(arrival: '-2 days');
        $vehicle = $this->vehicle();
        $this->assign($trip, $vehicle, null);

        $c = $this->service->forTenant(self::TENANT_A)['vehicles'][$vehicle->id];

        $this->assertSame(-2, $c['free_in_days']);
        $this->assertStringContainsString('was due back', $c['sentence']);
    }

    public function test_a_trip_with_no_arrival_date_says_so_rather_than_implying_one(): void
    {
        $trip    = $this->trip(arrival: null);
        $vehicle = $this->vehicle();
        $this->assign($trip, $vehicle, null);

        $c = $this->service->forTenant(self::TENANT_A)['vehicles'][$vehicle->id];

        $this->assertNull($c['free_in_days'], 'null, never 0 — 0 would read as "free today"');
        $this->assertStringContainsString('no planned arrival date recorded', $c['sentence']);
    }

    public function test_a_driver_is_reported_the_same_way_as_a_vehicle(): void
    {
        $trip   = $this->trip();
        $driver = $this->driver();
        $this->assign($trip, null, $driver);

        $this->assertArrayHasKey($driver->id, $this->service->forTenant(self::TENANT_A)['drivers']);
    }

    /* ══════════ what it must NOT report ══════════ */

    public function test_a_released_assignment_no_longer_holds_the_resource(): void
    {
        $trip       = $this->trip();
        $vehicle    = $this->vehicle();
        $assignment = $this->assign($trip, $vehicle, null);

        $assignment->forceFill(['status' => AssignmentStatus::RELEASED, 'released_at' => now()])->save();

        $this->assertSame([], $this->service->forTenant(self::TENANT_A)['vehicles']);
    }

    public function test_an_unassigned_vehicle_appears_nowhere(): void
    {
        // Silence means "no trip of mine holds it" — NOT "available". The
        // caller must not read availability into an absence.
        $this->vehicle();

        $this->assertSame([], $this->service->forTenant(self::TENANT_A)['vehicles']);
    }

    public function test_another_tenants_commitments_are_never_returned(): void
    {
        $trip    = $this->trip(self::TENANT_B);
        $vehicle = $this->vehicle(self::TENANT_B);
        $this->assign($trip, $vehicle, null);

        $this->assertSame([], $this->service->forTenant(self::TENANT_A)['vehicles']);
        $this->assertArrayHasKey($vehicle->id, $this->service->forTenant(self::TENANT_B)['vehicles']);
    }

    /* ══════════ the endpoint ══════════ */

    public function test_the_endpoint_returns_both_collections(): void
    {
        $trip = $this->trip();
        $this->assign($trip, $this->vehicle(), $this->driver());

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->getJson('/api/transport/resource-commitments')
            ->assertOk()
            ->assertJsonStructure(['data' => ['vehicles', 'drivers']]);
    }

    public function test_a_client_identity_cannot_read_commitments(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'C', 'role' => 'client',
            'email' => 'c-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->getJson('/api/transport/resource-commitments')->assertForbidden();
    }
}
