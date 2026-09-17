<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Events\EmergencyFuelIssued;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS M2 — allocation readiness, fuel variance, and the job-card workflow.
 *
 * These lock down the rules that cost money when they are wrong: a vehicle
 * recommended when it should be blocked, an odometer that goes backwards, and
 * a truck released from the workshop while something still holds it.
 */
class FleetOperationsTest extends TestCase
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
        $v = Vehicle::create(array_merge([
            'company_id'          => $company,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'        => 'reefer',
            'gps_device_id'       => 'DEV-'.Str::random(6),
            'status'              => 'active',
            'compliance_status'   => 'compliant',
        ], $over));

        // A fresh fix, so telemetry staleness never accidentally blocks a
        // vehicle a test meant to be eligible.
        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => $company,
            'latitude' => $over['lat'] ?? '19.07609500',
            'longitude' => $over['lng'] ?? '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $v;
    }

    /* ── Feature 1: allocation readiness ────────────────────────── */

    public function test_eligible_excludes_blocked_vehicles_and_says_why(): void
    {
        $ok = $this->vehicle(['registration_number' => 'MH12AB0001']);
        $blocked = $this->vehicle(['registration_number' => 'MH12AB0002', 'compliance_status' => 'expired']);

        $safety = $this->vehicle(['registration_number' => 'MH12AB0003']);
        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $safety->id,
            'job_card_number' => 'JC-S1', 'status' => 'open', 'is_safety_critical' => true,
        ]);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible')->assertOk()->json('data');

        $eligibleIds = collect($data['eligible'])->pluck('id');
        $this->assertTrue($eligibleIds->contains($ok->id));
        $this->assertFalse($eligibleIds->contains($blocked->id));
        $this->assertFalse($eligibleIds->contains($safety->id), 'A safety-critical job must block allocation');

        // Excluded vehicles are returned WITH a reason — a planner asking "why
        // isn't MH-03 on the list" gets an answer from the API, not from memory.
        $excluded = collect($data['excluded'])->firstWhere('id', $safety->id);
        $this->assertSame('safety_job_open', $excluded['blockers'][0]['code']);
        $this->assertNotEmpty($excluded['blockers'][0]['owner']);
    }

    public function test_a_non_safety_job_does_not_block_allocation(): void
    {
        $v = $this->vehicle();
        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $v->id,
            'job_card_number' => 'JC-MINOR', 'status' => 'open', 'is_safety_critical' => false,
        ]);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible')->json('data');

        // A broken cabin light is not brakes. It shows, carrying its open count.
        $row = collect($data['eligible'])->firstWhere('id', $v->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['open_jobs']);
    }

    public function test_the_nearest_vehicle_is_recommended_and_the_reason_matches_the_ranking(): void
    {
        // Mumbai vs Pune, with the pickup in Mumbai.
        $near = $this->vehicle(['registration_number' => 'MH12NEAR', 'lat' => '19.07600000', 'lng' => '72.87700000']);
        $far  = $this->vehicle(['registration_number' => 'MH12FAR1', 'lat' => '18.52040000', 'lng' => '73.85670000']);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible?pickup_lat=19.0760&pickup_lng=72.8777')
            ->assertOk()->json('data');

        $top = $data['eligible'][0];
        $this->assertSame($near->id, $top['id']);
        $this->assertTrue($top['recommended']);
        $this->assertStringContainsString('MH12NEAR', $top['recommendation']);
        // The headline is built from the same reasons that produced the score.
        $this->assertStringContainsString('near the pickup', strtolower($top['recommendation']));

        $farRow = collect($data['eligible'])->firstWhere('id', $far->id);
        $this->assertGreaterThan($farRow['score'], $top['score']);
    }

    public function test_filtering_by_type_narrows_the_list(): void
    {
        $this->vehicle(['vehicle_type' => 'reefer']);
        $tipper = $this->vehicle(['vehicle_type' => 'tipper']);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible?vehicle_type=REEFER')->json('data');

        $this->assertFalse(collect($data['eligible'])->pluck('id')->contains($tipper->id));
    }

    public function test_a_company_id_in_the_query_string_cannot_read_another_fleet(): void
    {
        $this->vehicle([], self::OTHER);

        // The M2 brief passes ?company_id=. Trusting it would be a cross-tenant
        // read for anyone who can edit a URL — a mismatch is refused outright.
        $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible?company_id='.self::OTHER)
            ->assertForbidden();
    }

    /* ── Feature 3: fuel ────────────────────────────────────────── */

    private function fill(Vehicle $v, array $over = [])
    {
        return $this->actingAs($this->user())->postJson("/api/v1/fleet/vehicles/{$v->id}/fuel", array_merge([
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000,
            'odometer' => 1000, 'station_vendor' => 'HP Nashik',
        ], $over));
    }

    public function test_the_first_fill_has_nothing_to_measure_against(): void
    {
        $v = $this->vehicle();

        $this->fill($v)->assertStatus(201)
            ->assertJsonPath('data.efficiency_kmpl', null)
            ->assertJsonPath('data.fuel_exception', false);
    }

    public function test_consumption_is_calculated_from_the_odometer_gap(): void
    {
        $v = $this->vehicle(['vehicle_type' => 'reefer']);   // benchmark 2.8 km/l

        $this->fill($v, ['odometer' => 1000])->assertStatus(201);
        // 300 km on 100 litres = 3.0 km/l, above the 2.8 benchmark.
        $second = $this->fill($v, ['odometer' => 1300, 'litres' => 100])->assertStatus(201);

        $this->assertSame('300.0', $second->json('data.km_driven'));
        $this->assertSame('3.00', $second->json('data.efficiency_kmpl'));
        $this->assertFalse($second->json('data.fuel_exception'));
    }

    public function test_a_thirsty_fill_is_flagged_with_an_explanation(): void
    {
        $v = $this->vehicle(['vehicle_type' => 'reefer']);

        $this->fill($v, ['odometer' => 1000])->assertStatus(201);
        // 150 km on 100 litres = 1.5 km/l against a 2.8 benchmark.
        $bad = $this->fill($v, ['odometer' => 1150, 'litres' => 100])->assertStatus(201);

        $this->assertTrue($bad->json('data.fuel_exception'));
        $this->assertStringContainsString('benchmark', $bad->json('data.variance_note'));
        // And it reaches the exception register without a second flag being set.
        $register = $this->actingAs($this->user())->getJson('/api/v1/fleet/fuel/exceptions')->json('data');
        $this->assertSame(1, collect($register)->where('fuel_exception', true)->count());
    }

    public function test_an_odometer_that_goes_backwards_is_refused(): void
    {
        $v = $this->vehicle();

        $this->fill($v, ['odometer' => 5000])->assertStatus(201);
        $this->fill($v, ['odometer' => 4000])->assertStatus(422);
        // Equal is not forward either.
        $this->fill($v, ['odometer' => 5000])->assertStatus(422);

        $this->assertSame(1, FuelTransaction::count());
    }

    public function test_an_emergency_fill_needs_a_reason_and_raises_the_billing_event(): void
    {
        Event::fake([EmergencyFuelIssued::class]);
        $v = $this->vehicle();

        // A reason is mandatory — somebody has to answer for it.
        $this->fill($v, ['is_emergency' => true])->assertStatus(422);

        $response = $this->fill($v, [
            'is_emergency' => true,
            'emergency_reason' => 'Ran dry on NH-48, no card accepted',
            'customer_recoverable' => true,
        ])->assertStatus(201);

        // Recoverable goes to Developer 3's billing engine as 'billable'.
        $this->assertSame('billable', $response->json('data.recovery_status'));

        Event::assertDispatched(EmergencyFuelIssued::class, function (EmergencyFuelIssued $e) use ($v) {
            return $e->vehicle->is($v) && $e->customerRecoverable === true;
        });
    }

    public function test_an_undecided_emergency_is_not_billed_to_the_customer(): void
    {
        $v = $this->vehicle();

        $response = $this->fill($v, [
            'is_emergency' => true,
            'emergency_reason' => 'Pump card reader down',
        ])->assertStatus(201);

        // "Nobody has decided yet" must not default into an invoice.
        $this->assertSame('pending', $response->json('data.recovery_status'));
        $this->assertNull($response->json('data.customer_recoverable'));
    }

    public function test_a_receipt_is_stored_privately_and_served_through_the_api(): void
    {
        Storage::fake('local');
        $v = $this->vehicle();

        $response = $this->actingAs($this->user())->post("/api/v1/fleet/vehicles/{$v->id}/fuel", [
            'litres' => 50, 'rate_per_litre' => 90, 'amount' => 4500,
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertStatus(201);

        $path = $response->json('data.receipt_path');
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        // Never a public URL: a fuel receipt carries a card trail.
        $this->assertStringNotContainsString('public', $path);
    }

    /* ── Feature 4: job cards ───────────────────────────────────── */

    public function test_opening_a_job_card_takes_the_vehicle_off_the_road(): void
    {
        $v = $this->vehicle();

        $response = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id,
            'complaint'  => 'Brake judder under load',
            'is_safety_critical' => true,
        ])->assertStatus(201);

        // Numbered for you — nobody invents a scheme at the counter.
        $this->assertStringStartsWith('JC-'.now()->format('Y'), $response->json('data.job_card_number'));
        $this->assertSame('in_maintenance', $v->fresh()->status);
    }

    public function test_closing_a_job_card_totals_the_costs_and_releases_the_vehicle(): void
    {
        $v = $this->vehicle();
        $job = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Brake judder',
        ])->json('data');

        $response = $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", [
                'diagnosis' => 'Warped discs replaced',
                'parts_cost' => 18500, 'labour_cost' => 4200,
            ])->assertOk();

        $this->assertSame('completed', $response->json('data.job.status'));
        $this->assertSame('22700.00', $response->json('data.job.total_cost'));
        $this->assertTrue($response->json('data.release.released'));
        $this->assertSame('active', $v->fresh()->status);
    }

    public function test_a_vehicle_is_not_released_while_something_still_holds_it(): void
    {
        $v = $this->vehicle(['compliance_status' => 'expired']);

        $first = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Brakes',
        ])->json('data');

        // A second bay is still working on it.
        $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Gearbox',
        ])->assertStatus(201);

        $response = $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$first['id']}/close", ['parts_cost' => 100])
            ->assertOk();

        $this->assertFalse($response->json('data.release.released'));
        $this->assertSame('in_maintenance', $v->fresh()->status);

        // And it says WHAT holds it — both reasons, each with an owner.
        $codes = collect($response->json('data.release.holds'))->pluck('code');
        $this->assertTrue($codes->contains('other_jobs_open'));
        $this->assertTrue($codes->contains('compliance_blocked'));
    }

    public function test_a_failed_qc_keeps_the_vehicle_in_the_workshop(): void
    {
        $v = $this->vehicle();
        $job = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Brakes',
        ])->json('data');

        $response = $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", ['qc_passed' => false])
            ->assertOk();

        $this->assertFalse($response->json('data.release.released'));
        $this->assertSame('in_maintenance', $v->fresh()->status);
    }

    public function test_a_signed_total_overrides_parts_plus_labour(): void
    {
        $v = $this->vehicle();
        $job = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Service',
        ])->json('data');

        // A warranty credit: the card says 15,000 even though parts+labour is 22,700.
        $response = $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", [
                'parts_cost' => 18500, 'labour_cost' => 4200, 'total_cost' => 15000,
            ])->assertOk();

        $this->assertSame('15000.00', $response->json('data.job.total_cost'));
    }

    public function test_a_closed_card_cannot_be_closed_twice(): void
    {
        $v = $this->vehicle();
        $job = $this->actingAs($this->user())->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $v->id, 'complaint' => 'Service',
        ])->json('data');

        $this->actingAs($this->user())->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", [])->assertOk();
        $this->actingAs($this->user())->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", [])->assertStatus(422);
    }

    /* ── Feature 2: live status ─────────────────────────────────── */

    public function test_live_status_answers_for_a_vehicle_that_has_never_reported(): void
    {
        $v = Vehicle::create([
            'company_id' => self::COMPANY, 'registration_number' => 'MH12SILENT',
            'vehicle_type' => 'reefer', 'gps_device_id' => 'DEV-SILENT',
        ]);

        // 200 with a null reading, not a 404: the vehicle exists, the signal
        // does not, and a gauge should render "no signal" rather than an error.
        $this->actingAs($this->user())->getJson("/api/v1/fleet/vehicles/{$v->id}/live-status")
            ->assertOk()
            ->assertJsonPath('data.live', null)
            ->assertJsonPath('data.signal', 'offline');
    }

    public function test_signal_health_degrades_before_it_goes_offline(): void
    {
        $v = $this->vehicle();

        $this->actingAs($this->user())->getJson("/api/v1/fleet/vehicles/{$v->id}/live-status")
            ->assertJsonPath('data.signal', 'active');

        VehicleLiveStatus::where('vehicle_id', $v->id)->update(['last_ping_at' => now()->subMinutes(20)]);
        $this->actingAs($this->user())->getJson("/api/v1/fleet/vehicles/{$v->id}/live-status")
            ->assertJsonPath('data.signal', 'degraded');

        VehicleLiveStatus::where('vehicle_id', $v->id)->update(['last_ping_at' => now()->subHours(2)]);
        $this->actingAs($this->user())->getJson("/api/v1/fleet/vehicles/{$v->id}/live-status")
            ->assertJsonPath('data.signal', 'offline');
    }

    /* ── Feature 5: passport by plate ───────────────────────────── */

    public function test_the_passport_can_be_opened_from_a_number_plate(): void
    {
        $v = $this->vehicle(['registration_number' => 'MH12AB9999']);

        // However the plate is spaced on the paperwork.
        foreach (['MH12AB9999', 'mh12ab9999', 'MH 12 AB 9999'] as $typed) {
            $this->actingAs($this->user())
                ->getJson('/api/v1/fleet/vehicles/'.urlencode($typed).'/passport')
                ->assertOk()
                ->assertJsonPath('data.vehicle.id', $v->id);
        }
    }

    public function test_a_portal_login_cannot_reach_any_of_it(): void
    {
        $v = $this->vehicle();

        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/v1/fleet/vehicles/eligible')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/fleet/maintenance/job-cards')->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/fleet/vehicles/{$v->id}/fuel", [
                'litres' => 10, 'rate_per_litre' => 90, 'amount' => 900,
            ])->assertForbidden();
        }
    }

    public function test_one_company_cannot_fuel_or_service_anothers_vehicle(): void
    {
        $theirs = $this->vehicle([], self::OTHER);

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$theirs->id}/fuel", [
                'litres' => 10, 'rate_per_litre' => 90, 'amount' => 900,
            ])->assertNotFound();

        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/maintenance/job-cards', [
                'vehicle_id' => $theirs->id, 'complaint' => 'Nothing',
            ])->assertNotFound();

        $this->assertSame('active', $theirs->fresh()->status);
    }
}
