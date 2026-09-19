<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — the control tower's read API.
 *
 * The traffic light is the contract here: if red stops meaning "cannot be
 * dispatched", every screen built on it lies. These lock the four states down,
 * plus the two ways a fleet board leaks — the wrong role, and the wrong company.
 */
class FleetControlTowerTest extends TestCase
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

    private function user(string $role, int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = [], int $company = self::COMPANY): Vehicle
    {
        return Vehicle::create(array_merge([
            'company_id'          => $company,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'        => 'reefer',
            'gps_device_id'       => 'DEV-'.Str::random(6),
            'status'              => 'AVAILABLE',
            'compliance_status'   => 'compliant',
        ], $over));
    }

    private function live(Vehicle $v, array $over = []): VehicleLiveStatus
    {
        return VehicleLiveStatus::create(array_merge([
            'vehicle_id'       => $v->id,
            'company_id'       => $v->company_id,
            'latitude'         => '19.07609500',
            'longitude'        => '72.87765800',
            'speed'            => '46.50',
            'ignition'         => true,
            'generator_status' => 'on',
            'temperature'      => '-18.50',
            'last_ping_at'     => now()->subMinute(),
        ], $over));
    }

    /** @return array the one vehicle row the grid returned */
    private function gridRow(Vehicle $vehicle)
    {
        $response = $this->actingAs($this->user('staff'))->getJson('/api/v1/fleet/vehicles');
        $response->assertOk();

        return collect($response->json('data.vehicles'))->firstWhere('id', $vehicle->id);
    }

    public function test_a_healthy_vehicle_is_green_and_still_offers_an_action(): void
    {
        $v = $this->vehicle();
        $this->live($v);

        $row = $this->gridRow($v);

        $this->assertSame('green', $row['health']['tone']);
        $this->assertSame('moving', $row['health']['state']);
        $this->assertSame([], $row['health']['issues']);
        // No screen is a dead end: even a healthy vehicle has a next action.
        $this->assertNotEmpty($row['health']['next']['label']);
    }

    public function test_blocked_papers_are_red_and_say_why_what_who_and_next(): void
    {
        $v = $this->vehicle(['compliance_status' => 'blocked']);
        $this->live($v);

        $row = $this->gridRow($v);

        $this->assertSame('red', $row['health']['tone']);
        $this->assertSame('compliance_blocked', $row['health']['state']);

        $issue = collect($row['health']['issues'])->firstWhere('code', 'compliance_blocked');
        // The four questions a blocked user actually has — never a generic error.
        $this->assertNotEmpty($issue['why']);
        $this->assertNotEmpty($issue['missing']);
        $this->assertNotEmpty($issue['owner']);
        $this->assertNotEmpty($issue['next']['label']);
    }

    public function test_a_warming_load_with_the_genset_off_is_red(): void
    {
        $v = $this->vehicle();
        $this->live($v, ['generator_status' => 'off', 'temperature' => '-9.00']);

        $row = $this->gridRow($v);

        $this->assertSame('red', $row['health']['tone']);
        $this->assertNotNull(collect($row['health']['issues'])->firstWhere('code', 'temperature_excursion'));
    }

    public function test_a_silent_device_is_amber_and_offline(): void
    {
        $stale = $this->vehicle();
        $this->live($stale, ['last_ping_at' => now()->subHours(3)]);

        $row = $this->gridRow($stale);
        $this->assertSame('amber', $row['health']['tone']);
        $this->assertSame('offline', $row['health']['state']);
        $this->assertNotNull(collect($row['health']['issues'])->firstWhere('code', 'telemetry_stale'));

        // Never reported at all is its own case, not a crash on a missing row.
        $never = $this->vehicle();
        $neverRow = $this->gridRow($never);
        $this->assertSame('offline', $neverRow['health']['state']);
        $this->assertNotNull(collect($neverRow['health']['issues'])->firstWhere('code', 'never_reported'));
    }

    public function test_an_open_job_card_puts_the_vehicle_in_the_workshop(): void
    {
        $v = $this->vehicle();
        $this->live($v);

        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $v->id,
            'job_card_number' => 'JC-1', 'status' => 'awaiting_parts',
            'parts_cost' => '100.00', 'labour_cost' => '50.00', 'total_cost' => '150.00',
        ]);

        $row = $this->gridRow($v);

        $this->assertSame('maintenance', $row['health']['state']);
        $this->assertSame(1, $row['open_jobs']);
    }

    public function test_the_tiles_count_every_vehicle_exactly_once(): void
    {
        $this->live($this->vehicle());                                   // moving
        $this->vehicle(['compliance_status' => 'expired']);              // compliance_blocked
        $this->vehicle(['gps_device_id' => null]);                       // unmonitored
        $this->vehicle(['status' => 'retired']);                         // retired

        $tiles = $this->actingAs($this->user('staff'))
            ->getJson('/api/v1/fleet/vehicles')->json('data.tiles');

        $this->assertSame(4, $tiles['total']);
        $this->assertSame(4, array_sum($tiles['by_state']), 'Tiles double-count or drop a vehicle');
        $this->assertSame(4, $tiles['red'] + $tiles['amber'] + $tiles['green']);
    }

    public function test_a_dry_vehicle_never_reports_reefer_readings(): void
    {
        // Progressive disclosure: the API withholds the fields, so the UI cannot
        // render an empty -18C dial on a tipper.
        $tipper = $this->vehicle(['vehicle_type' => 'tipper']);
        $this->live($tipper, ['temperature' => '4.00', 'generator_status' => 'on']);

        $row = $this->gridRow($tipper);

        $this->assertFalse($row['live']['is_reefer']);
        $this->assertNull($row['live']['temperature']);
        $this->assertNull($row['live']['generator_status']);
        $this->assertNotNull($row['live']['speed']);
    }

    public function test_the_passport_answers_everything_about_one_asset(): void
    {
        $v = $this->vehicle();
        $this->live($v);

        $response = $this->actingAs($this->user('admin'))
            ->getJson("/api/v1/fleet/vehicles/{$v->id}/passport");

        $response->assertOk()
            ->assertJsonPath('data.vehicle.registration_number', $v->registration_number)
            ->assertJsonStructure(['data' => [
                'vehicle', 'health' => ['tone', 'state', 'issues', 'next'],
                'live', 'gensets', 'telemetry',
                'fuel' => ['recent', 'total_litres', 'total_amount'],
                'tolls' => ['recent', 'total_amount', 'unreconciled'],
                'workshop' => ['jobs', 'open_count', 'total_cost'],
            ]]);
    }

    public function test_a_portal_login_cannot_read_the_fleet(): void
    {
        $this->vehicle();

        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $this->actingAs($this->user($role))
                ->getJson('/api/v1/fleet/vehicles')
                ->assertForbidden();
        }
    }

    public function test_one_company_cannot_read_anothers_vehicles(): void
    {
        $mine = $this->vehicle();
        $theirs = $this->vehicle([], self::OTHER);

        $ids = collect(
            $this->actingAs($this->user('staff'))->getJson('/api/v1/fleet/vehicles')->json('data.vehicles')
        )->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'Cross-company leak on the fleet board');

        // And not by guessing the id either.
        $this->actingAs($this->user('staff'))
            ->getJson("/api/v1/fleet/vehicles/{$theirs->id}/passport")
            ->assertNotFound();
    }
}
