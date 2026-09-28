<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\FleetUtilisationService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — idle vehicles and utilisation for the executive tower (T-49).
 *
 * "Working" is an active trip assignment, never the status flag — the dispatch
 * gateway that sets the flag is allowed to fail, so the assignment is the only
 * honest signal. Utilisation is working time over time-in-fleet, and the report
 * returns the components, not just the percentage, so the basis is visible.
 */
class FleetUtilisationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();
    }

    private function service(): FleetUtilisationService
    {
        return app(FleetUtilisationService::class);
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Exec', 'role' => 'admin',
            'email' => 'exec-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A vehicle that has been in the fleet for a good while (predates any window). */
    private function vehicle(string $status = Vehicle::STATUS_AVAILABLE): Vehicle
    {
        $v = Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'truck', 'ownership_type' => 'OWNED', 'status' => $status,
            'compliance_status' => 'compliant',
        ]);

        DB::table('vehicles')->where('id', $v->id)->update(['created_at' => Carbon::now()->subDays(90)]);

        return $v->fresh();
    }

    private function assign(int $vehicleId, Carbon $assignedAt, ?Carbon $releasedAt = null): void
    {
        DB::table('trip_assignments')->insert([
            'tenant_id' => self::COMPANY,
            'trip_id' => random_int(100000, 999999),
            'vehicle_id' => $vehicleId,
            'status' => $releasedAt ? 'released' : 'active',
            'assigned_at' => $assignedAt,
            'released_at' => $releasedAt,
            'created_at' => $assignedAt, 'updated_at' => now(),
        ]);
    }

    /* ── Idle now ────────────────────────────────────────────────── */

    public function test_the_idle_snapshot_separates_working_idle_and_unavailable(): void
    {
        $free      = $this->vehicle();                                   // idle, never used
        $onTrip    = $this->vehicle();                                   // working
        $workshop  = $this->vehicle(Vehicle::STATUS_UNDER_MAINTENANCE);  // unavailable
        $wasOnTrip = $this->vehicle();                                   // idle, came off 5 days ago

        $this->assign($onTrip->id, Carbon::now()->subDay());                       // still active
        $this->assign($wasOnTrip->id, Carbon::now()->subDays(12), Carbon::now()->subDays(5));

        $report = $this->service()->idleNow(self::COMPANY);

        $this->assertSame(4, $report['tiles']['total']);
        $this->assertSame(1, $report['tiles']['working']);
        $this->assertSame(2, $report['tiles']['idle']);
        $this->assertSame(1, $report['tiles']['unavailable']);

        $ids = array_column($report['idle'], 'id');
        $this->assertContains($free->id, $ids);
        $this->assertContains($wasOnTrip->id, $ids);
        $this->assertNotContains($onTrip->id, $ids);
        $this->assertNotContains($workshop->id, $ids);
    }

    public function test_idle_since_is_the_last_release_or_the_onboarding_date(): void
    {
        $used   = $this->vehicle();
        $unused = $this->vehicle();
        $this->assign($used->id, Carbon::now()->subDays(20), Carbon::now()->subDays(5));

        $report = $this->service()->idleNow(self::COMPANY);
        $byId = collect($report['idle'])->keyBy('id');

        // Came off a trip 5 days ago.
        $this->assertTrue($byId[$used->id]['ever_used']);
        $this->assertEqualsWithDelta(5, $byId[$used->id]['days_idle'], 1);

        // Never on a trip: idle since it was onboarded (90 days ago).
        $this->assertFalse($byId[$unused->id]['ever_used']);
        $this->assertEqualsWithDelta(90, $byId[$unused->id]['days_idle'], 1);

        // Longest-idle first.
        $this->assertSame($unused->id, $report['idle'][0]['id']);
    }

    public function test_the_idle_endpoint_answers_for_an_admin(): void
    {
        $this->vehicle();

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/reports/idle')
            ->assertOk()->json('data');

        $this->assertArrayHasKey('tiles', $data);
        $this->assertArrayHasKey('idle', $data);
    }

    public function test_a_portal_login_cannot_read_the_reports(): void
    {
        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $u = User::create([
                'tenant_id' => self::COMPANY, 'name' => ucfirst($role), 'role' => $role,
                'email' => $role.'-'.Str::random(6).'@test.local',
                'password' => bcrypt('x'), 'status' => 'active',
            ]);
            $this->actingAs($u)->getJson('/api/v1/fleet/reports/idle')->assertForbidden();
            $this->actingAs($u)->getJson('/api/v1/fleet/reports/utilisation')->assertForbidden();
        }
    }

    /* ── Utilisation over a window ────────────────────────────────── */

    public function test_utilisation_measures_working_time_against_time_in_fleet(): void
    {
        $from = Carbon::now()->subDays(10)->startOfDay();
        $to   = Carbon::now();

        $busy = $this->vehicle();   // on a trip the whole window
        $half = $this->vehicle();   // worked 5 of the 10 days
        $idle = $this->vehicle();   // never worked

        // Spans the whole window: assigned before it, still active.
        $this->assign($busy->id, Carbon::now()->subDays(15));
        // Five days inside the window.
        $this->assign($half->id, Carbon::now()->subDays(10), Carbon::now()->subDays(5));

        $report = $this->service()->utilisation(self::COMPANY, $from->toDateString(), $to->toDateString());
        $byId = collect($report['vehicles'])->keyBy('id');

        $this->assertEqualsWithDelta(100, $byId[$busy->id]['utilisation_pct'], 2);
        $this->assertEqualsWithDelta(50, $byId[$half->id]['utilisation_pct'], 8);
        $this->assertSame(0.0, $byId[$idle->id]['utilisation_pct']);

        // Worst first, and the summary counts the idle one.
        $this->assertSame($idle->id, $report['vehicles'][0]['id']);
        $this->assertSame(1, $report['summary']['idle_through_window']);
        $this->assertSame(3, $report['summary']['vehicles_measured']);
    }

    public function test_an_open_trip_is_counted_only_up_to_now(): void
    {
        // Window ends today; a still-running trip that began 2 days ago is worked
        // for 2 days, not to the end of the window.
        $v = $this->vehicle();
        $this->assign($v->id, Carbon::now()->subDays(2));

        $report = $this->service()->utilisation(
            self::COMPANY, Carbon::now()->subDays(10)->toDateString(), Carbon::now()->toDateString()
        );
        $row = collect($report['vehicles'])->firstWhere('id', $v->id);

        $this->assertEqualsWithDelta(2, $row['working_days'], 1);
    }

    public function test_a_vehicle_onboarded_after_the_window_is_not_measured(): void
    {
        $v = $this->vehicle();
        // Onboarded two days ago; the window is a fortnight last month.
        DB::table('vehicles')->where('id', $v->id)->update(['created_at' => Carbon::now()->subDays(2)]);

        $report = $this->service()->utilisation(
            self::COMPANY,
            Carbon::now()->subDays(45)->toDateString(),
            Carbon::now()->subDays(31)->toDateString()
        );
        $row = collect($report['vehicles'])->firstWhere('id', $v->id);

        $this->assertNull($row['utilisation_pct']);
        $this->assertFalse($row['in_fleet_for_window']);
    }

    public function test_the_utilisation_endpoint_rejects_a_backwards_window(): void
    {
        $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/reports/utilisation?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    /* ── Degrades without the trip module ─────────────────────────── */

    public function test_reporting_degrades_when_there_is_no_trip_module(): void
    {
        $this->vehicle();
        $this->vehicle();
        Schema::dropIfExists('trip_assignments');

        // No trips means nothing is committed: everything idle, nothing crashes.
        $idle = $this->service()->idleNow(self::COMPANY);
        $this->assertSame(2, $idle['tiles']['idle']);
        $this->assertSame(0, $idle['tiles']['working']);

        $util = $this->service()->utilisation(self::COMPANY);
        $this->assertSame(0.0, $util['summary']['average_utilisation_pct']);
    }
}
