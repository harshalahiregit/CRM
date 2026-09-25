<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Events\EmergencyFuelIssued;
use App\Domains\Fleet\Events\VehicleStatusChanged;
use App\Domains\Fleet\Models\FastagTransaction;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\TyreFitment;
use App\Domains\Fleet\Models\UreaTransaction;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\ComplianceService;
use App\Domains\Fleet\Services\FleetService;
use App\Domains\Integration\Events\TelemetryExcursionDetected;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-TM-001 — the integration surface, and the two asset registers that
 * complete Developer 2's nine tables.
 *
 * These are the tests that protect OTHER developers: a renamed method or a
 * silent event is not a bug in this module, it is a bug in theirs.
 */
class FleetContractsAndAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER   = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::COMPANY, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role = 'staff', int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = [], int $company = self::COMPANY): Vehicle
    {
        $vehicle = Vehicle::create(array_merge([
            'company_id'          => $company,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'        => 'reefer',
            'gps_device_id'       => 'DEV-'.Str::random(6),
            'status'              => 'AVAILABLE',
            'compliance_status'   => 'compliant',
        ], $over));

        // A fresh fix, so telemetry staleness never quietly excludes a vehicle
        // a test meant to be eligible.
        \App\Domains\Fleet\Models\VehicleLiveStatus::create([
            'vehicle_id' => $vehicle->id, 'company_id' => $company,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $vehicle;
    }

    /* ── Contract 1: Developer 1 calls this by name ─────────────── */

    public function test_get_eligible_vehicles_exists_under_its_contract_name(): void
    {
        $this->vehicle(['registration_number' => 'MH12AB0001', 'vehicle_type' => 'reefer']);
        $this->vehicle(['registration_number' => 'MH12AB0002', 'vehicle_type' => 'tipper']);

        $fleet = app(FleetService::class);

        // Exactly the documented two-argument call, with the spec's uppercase.
        $result = $fleet->getEligibleVehicles(self::COMPANY, 'REEFER');

        $this->assertArrayHasKey('eligible', $result);
        $this->assertArrayHasKey('excluded', $result);
        $this->assertSame(['MH12AB0001'], collect($result['eligible'])->pluck('registration_number')->all());
        // Every candidate carries the explanation the spec requires.
        $this->assertNotEmpty($result['eligible'][0]['reasons']);
        $this->assertIsNumeric($result['eligible'][0]['score']);
    }

    /* ── Contract 2: Developer 3 calls this by name ─────────────── */

    public function test_get_trip_operating_costs_sums_every_cost_attached_to_the_trip(): void
    {
        $vehicle = $this->vehicle();
        $tripId = 4242;

        FuelTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id, 'trip_id' => $tripId,
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => '9000.00',
        ]);
        UreaTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id, 'trip_id' => $tripId,
            'litres' => 10, 'amount' => '850.50',
        ]);
        FastagTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id, 'trip_id' => $tripId,
            'tag_id' => 'TAG-1', 'amount' => '1445.00', 'transaction_timestamp' => now(),
        ]);
        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id, 'trip_id' => $tripId,
            'job_card_number' => 'JC-BREAKDOWN', 'status' => 'COMPLETED', 'total_cost' => '5000.00',
        ]);
        // Routine servicing with NO trip: fleet overhead, not this trip's cost.
        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id,
            'job_card_number' => 'JC-ROUTINE', 'status' => 'COMPLETED', 'total_cost' => '99999.00',
        ]);

        $costs = app(FleetService::class)->getTripOperatingCosts($tripId, self::COMPANY);

        $this->assertSame('9000.00', $costs['lines']['fuel']);
        $this->assertSame('850.50', $costs['lines']['urea']);
        $this->assertSame('1445.00', $costs['lines']['tolls']);
        $this->assertSame('5000.00', $costs['lines']['maintenance']);
        $this->assertSame('16295.50', $costs['total']);
        // Money never arrives as a float.
        $this->assertIsString($costs['total']);
    }

    public function test_trip_costs_never_cross_a_company_boundary(): void
    {
        $tripId = 77;
        $mine = $this->vehicle();
        $theirs = $this->vehicle([], self::OTHER);

        FuelTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $mine->id, 'trip_id' => $tripId,
            'litres' => 10, 'rate_per_litre' => 90, 'amount' => '900.00',
        ]);
        FuelTransaction::create([
            'company_id' => self::OTHER, 'vehicle_id' => $theirs->id, 'trip_id' => $tripId,
            'litres' => 10, 'rate_per_litre' => 90, 'amount' => '5000.00',
        ]);

        $costs = app(FleetService::class)->getTripOperatingCosts($tripId, self::COMPANY);

        $this->assertSame('900.00', $costs['lines']['fuel'], 'Another company\'s spend leaked into this trip');
    }

    public function test_trip_costs_refuse_to_run_without_a_company_context(): void
    {
        // A trip id alone is not a tenancy boundary.
        $this->expectException(\App\Exceptions\BusinessException::class);
        app(FleetService::class)->getTripOperatingCosts(1);
    }

    /* ── Domain events under their contract names ───────────────── */

    public function test_every_published_event_fires_under_its_documented_string_name(): void
    {
        $heard = [];
        foreach ([
            TelemetryExcursionDetected::NAME,
            EmergencyFuelIssued::NAME,
            VehicleStatusChanged::NAME,
        ] as $name) {
            Event::listen($name, function ($payload) use ($name, &$heard) {
                $heard[$name] = $payload[0] ?? $payload;
            });
        }

        $vehicle = $this->vehicle();

        // 1. status change — raised by the observer, whatever moved the status.
        $vehicle->update(['status' => 'UNDER_MAINTENANCE']);

        // 2. emergency fuel
        EmergencyFuelIssued::dispatch($vehicle, 1, 5000.0, true, 'Ran dry');

        // 3. excursion
        TelemetryExcursionDetected::dispatch($vehicle, -9.0, -18.0, 'off', now()->toDateTimeString(), true, 1);

        $this->assertArrayHasKey('fleet.vehicle.status_changed', $heard);
        $this->assertArrayHasKey('fuel.emergency_issued', $heard);
        $this->assertArrayHasKey('telemetry.temperature_excursion.detected', $heard);

        // The payload is plain scalars — a consumer must not need our models.
        $status = $heard['fleet.vehicle.status_changed'];
        $this->assertSame('UNDER_MAINTENANCE', $status['status']);
        $this->assertSame('AVAILABLE', $status['previous_status']);
        $this->assertFalse($status['available']);
        $this->assertSame($vehicle->id, $status['vehicle_id']);
    }

    public function test_a_compliance_block_is_announced_as_a_status_change(): void
    {
        Event::fake([VehicleStatusChanged::class]);
        $vehicle = $this->vehicle();

        $vehicle->update(['compliance_status' => 'expired']);

        // Outside this domain, "grounded by paperwork" and "grounded by a
        // broken axle" are the same question: can it work right now?
        Event::assertDispatched(VehicleStatusChanged::class, fn ($e) => $e->isNowBlocked());
    }

    public function test_an_irrelevant_edit_announces_nothing(): void
    {
        Event::fake([VehicleStatusChanged::class]);
        $vehicle = $this->vehicle();

        $vehicle->update(['engine_number' => 'ENG-CORRECTED']);

        Event::assertNotDispatched(VehicleStatusChanged::class);
    }

    /* ── Compliance: the pre-dispatch gate ──────────────────────── */

    public function test_the_verdict_is_derived_from_the_document_dates(): void
    {
        $compliance = app(ComplianceService::class);

        $ok = $this->vehicle(['insurance_expiry' => now()->addYear()->toDateString()]);
        $compliance->refresh($ok);
        $this->assertSame('compliant', $ok->fresh()->compliance_status);

        $soon = $this->vehicle(['fitness_expiry' => now()->addDays(10)->toDateString()]);
        $compliance->refresh($soon);
        $this->assertSame('expiring', $soon->fresh()->compliance_status);

        $lapsed = $this->vehicle(['puc_expiry' => now()->subDay()->toDateString()]);
        $compliance->refresh($lapsed);
        $this->assertSame('expired', $lapsed->fresh()->compliance_status);
        $this->assertTrue($compliance->blocksDispatch($lapsed->fresh()));
    }

    public function test_the_health_message_names_the_document_that_lapsed(): void
    {
        $vehicle = $this->vehicle([
            'insurance_expiry' => now()->subWeek()->toDateString(),
            'permit_expiry'    => now()->subDay()->toDateString(),
        ]);
        app(ComplianceService::class)->refresh($vehicle);

        $row = collect(
            $this->actingAs($this->user())->getJson('/api/v1/fleet/vehicles')->json('data.vehicles')
        )->firstWhere('id', $vehicle->id);

        $issue = collect($row['health']['issues'])->firstWhere('code', 'compliance_blocked');

        // Not "papers not valid" — WHICH papers, so nobody hunts through five
        // certificates to find the one that lapsed.
        $this->assertStringContainsString('Insurance', $issue['missing']);
        $this->assertStringContainsString('Permit', $issue['missing']);
    }

    public function test_a_manual_hold_outranks_every_date_and_survives_the_sweep(): void
    {
        $vehicle = $this->vehicle([
            'insurance_expiry' => now()->addYear()->toDateString(),
            'compliance_hold'  => true,
            'compliance_hold_reason' => 'Under accident investigation',
        ]);

        app(ComplianceService::class)->refreshAll(self::COMPANY);

        // Every date is valid, but a person blocked this vehicle for a reason no
        // column knows about. A nightly job must never overrule them.
        $this->assertSame('blocked', $vehicle->fresh()->compliance_status);
    }

    public function test_the_nightly_sweep_grounds_a_vehicle_whose_papers_lapsed_overnight(): void
    {
        // Yesterday's date: a certificate is valid THROUGH its expiry day, so
        // this is the first morning the vehicle is genuinely illegal.
        $vehicle = $this->vehicle(['insurance_expiry' => now()->subDay()->toDateString()]);
        // Nobody has touched the record; the flag still says compliant.
        $this->assertSame('compliant', $vehicle->fresh()->compliance_status);

        $this->artisan('stos:refresh-compliance')->assertSuccessful();

        $this->assertSame('expired', $vehicle->fresh()->compliance_status);
    }

    public function test_compliance_status_cannot_be_typed_in_through_the_api(): void
    {
        $response = $this->actingAs($this->user())->postJson('/api/v1/fleet/vehicles', [
            'registration_number' => 'MH12ZZ0001',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned',
            'puc_expiry' => now()->subDay()->toDateString(),
            'compliance_status' => 'compliant',        // an attempt to override
        ])->assertStatus(201);

        // The dates decide, not the payload.
        $this->assertSame('expired', $response->json('data.compliance_status'));
    }

    /* ── Urea ───────────────────────────────────────────────────── */

    public function test_urea_consumption_is_measured_in_litres_per_hundred_km(): void
    {
        $vehicle = $this->vehicle();
        $url = "/api/v1/fleet/vehicles/{$vehicle->id}/urea";

        $this->actingAs($this->user())->postJson($url, [
            'litres' => 20, 'amount' => 1800, 'odometer' => 1000,
        ])->assertStatus(201)->assertJsonPath('data.litres_per_100km', null);

        // 15 L over 1000 km = 1.5 L/100km, a healthy figure.
        $this->actingAs($this->user())->postJson($url, [
            'litres' => 15, 'amount' => 1350, 'odometer' => 2000,
        ])->assertStatus(201)->assertJsonPath('data.litres_per_100km', '1.50');
    }

    public function test_urea_is_counted_separately_from_diesel(): void
    {
        $vehicle = $this->vehicle();

        $this->actingAs($this->user())->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/urea", [
            'litres' => 20, 'amount' => 1800,
        ])->assertStatus(201);

        // Mixing urea into fuel_transactions would corrupt every km/l figure.
        $this->assertSame(0, FuelTransaction::count());
        $this->assertSame(1, UreaTransaction::count());
    }

    public function test_a_urea_odometer_cannot_go_backwards(): void
    {
        $vehicle = $this->vehicle();
        $url = "/api/v1/fleet/vehicles/{$vehicle->id}/urea";

        $this->actingAs($this->user())->postJson($url, ['litres' => 10, 'amount' => 900, 'odometer' => 5000])->assertStatus(201);
        $this->actingAs($this->user())->postJson($url, ['litres' => 10, 'amount' => 900, 'odometer' => 4000])->assertStatus(422);
    }

    /* ── Tyres ──────────────────────────────────────────────────── */

    public function test_fitting_a_tyre_that_is_already_on_another_truck_moves_it(): void
    {
        $first = $this->vehicle();
        $second = $this->vehicle();

        $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
            'vehicle_id' => $first->id, 'tyre_id' => 'TY-001',
            'position' => 'front_left', 'tread_depth' => 14.5, 'odometer_at_fitment' => 1000,
        ])->assertStatus(201);

        $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
            'vehicle_id' => $second->id, 'tyre_id' => 'TY-001',
            'position' => 'rear_outer_right', 'odometer_at_fitment' => 5000,
        ])->assertStatus(201);

        // A casing cannot be on two axles at once — the old fitment is closed.
        $this->assertSame(1, TyreFitment::where('tyre_id', 'TY-001')->where('status', 'FITTED')->count());
        $this->assertSame(2, TyreFitment::where('tyre_id', 'TY-001')->count(), 'History must keep both fitments');
    }

    public function test_fitting_over_an_occupied_position_removes_the_incumbent(): void
    {
        $vehicle = $this->vehicle();

        foreach (['TY-OLD', 'TY-NEW'] as $tyre) {
            $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
                'vehicle_id' => $vehicle->id, 'tyre_id' => $tyre, 'position' => 'front_right',
            ])->assertStatus(201);
        }

        $this->assertSame('REMOVED', TyreFitment::where('tyre_id', 'TY-OLD')->first()->status);
        $this->assertSame('FITTED', TyreFitment::where('tyre_id', 'TY-NEW')->first()->status);
    }

    public function test_tread_depth_cannot_increase(): void
    {
        $vehicle = $this->vehicle();
        $fitment = $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
            'vehicle_id' => $vehicle->id, 'tyre_id' => 'TY-002', 'position' => 'front_left', 'tread_depth' => 12.0,
        ])->json('data');

        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/tyres/{$fitment['id']}/inspect", ['tread_depth' => 9.5])
            ->assertOk()->assertJsonPath('data.tread_depth', '9.50');

        // A deeper reading is a mis-keyed measurement or the wrong tyre.
        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/tyres/{$fitment['id']}/inspect", ['tread_depth' => 11.0])
            ->assertStatus(422);
    }

    public function test_a_removed_tyre_reports_the_distance_it_ran(): void
    {
        $vehicle = $this->vehicle();
        $fitment = $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
            'vehicle_id' => $vehicle->id, 'tyre_id' => 'TY-003',
            'position' => 'rear_inner_left', 'odometer_at_fitment' => 10000,
        ])->json('data');

        $this->actingAs($this->user())->putJson("/api/v1/fleet/tyres/{$fitment['id']}/remove", [
            'status' => TyreFitment::RETREADED, 'odometer_at_removal' => 92000,
        ])->assertOk();

        $row = TyreFitment::find($fitment['id']);
        $this->assertSame(TyreFitment::RETREADED, $row->status);
        // Cost per kilometre is only answerable because the distance is kept.
        $this->assertSame(82000.0, $row->kilometresRun());
    }

    public function test_the_tyre_board_flags_a_casing_below_the_legal_limit(): void
    {
        $vehicle = $this->vehicle();
        $fitment = $this->actingAs($this->user())->postJson('/api/v1/fleet/tyres/fit', [
            'vehicle_id' => $vehicle->id, 'tyre_id' => 'TY-004', 'position' => 'front_left', 'tread_depth' => 10,
        ])->json('data');

        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/tyres/{$fitment['id']}/inspect", ['tread_depth' => 1.5])->assertOk();

        $board = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/tyres")->json('data');

        $this->assertSame(1, $board['due_replacement']);
        $this->assertTrue($board['fitted'][0]['worn_out']);
    }

    public function test_the_passport_carries_compliance_urea_and_tyres(): void
    {
        $vehicle = $this->vehicle(['insurance_expiry' => now()->addMonths(6)->toDateString()]);

        $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'compliance' => ['status', 'documents', 'expired', 'expiring'],
                'urea'  => ['recent', 'total_litres', 'total_amount'],
                'tyres' => ['fitted', 'history', 'due_replacement', 'min_tread_mm'],
            ]]);
    }

    public function test_portal_logins_cannot_touch_urea_or_tyres(): void
    {
        $vehicle = $this->vehicle();

        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/urea", [
                'litres' => 10, 'amount' => 900,
            ])->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/tyres")->assertForbidden();
        }
    }
}
