<?php

namespace Tests\Feature\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Models\Tenant;
use App\Models\Transport\TransportTrip;
use App\Services\Transport\DriverEligibilityService;
use App\Services\Transport\VehicleEligibilityService;
use App\Support\Transport\EligibilityVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * One shape for a blocker, everywhere — D-150.
 *
 * Driver blockers were plain strings while driver WARNINGS were Fleet's
 * `{code, why, owner}` objects. The same response carried two shapes for one
 * idea, and the first component that had to read both got it wrong: D-147's
 * `reason()` rendered the object and returned EMPTY for the string, so an
 * ineligible driver appeared on the picker with no reason at all.
 *
 * The fix was not a third branch in the component. A component that absorbs
 * two shapes is how a third one appears. This asserts the service emits one.
 *
 * ── AND WHAT THE OLD TESTS WERE REALLY PROTECTING ────────────────────────
 * Twenty tests asserted `assertCount(5, $verdict['checks'])` — the pre-D-134
 * driver contract. That collapsed to two on purpose, because Transport must
 * not re-implement licence, medical and lifecycle rules Fleet owns; two
 * applications deciding one driver's fitness is D-300.
 *
 * Rewriting them as `assertCount(2)` would assert nothing a dispatcher cares
 * about — it would pass on "Fit to drive: no" with no reason and no owner.
 * What they were protecting is that a blocked driver produces an ACTIONABLE
 * answer, so that is what is asserted here instead.
 */
class EligibilityVerdictShapeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function trip(): TransportTrip
    {
        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return TransportTrip::withoutGlobalScopes()->find($id);
    }

    /** A driver Fleet will not clear: no licence on file. */
    private function unfitDriver(): DriverProfile
    {
        return $this->fleetDriver([
            'name' => 'Unfit Driver',
            'licence_number' => null,
            'licence_expiry' => null,
        ], self::COMPANY);
    }

    private function assertIsReason(mixed $r, string $where): void
    {
        $this->assertIsArray($r,
            "{$where} is not the one shape — it is a ".gettype($r).". Blockers and warnings are "
            .'`{code, why, owner}` everywhere. A string here renders as EMPTY on the allocation '
            .'panel, which is D-147 arriving by the other door.');

        foreach (['code', 'why', 'owner'] as $key) {
            $this->assertArrayHasKey($key, $r, "{$where} has no `{$key}`.");
        }
    }

    public function test_a_blocked_driver_reports_the_reason_itself_not_that_one_exists(): void
    {
        $verdict = app(DriverEligibilityService::class)
            ->evaluate($this->unfitDriver(), $this->trip(), self::COMPANY);

        $this->assertFalse($verdict['eligible']);
        $this->assertNotEmpty($verdict['blockers'], 'nothing was blocked, so this proves nothing');

        $blocker = $verdict['blockers'][0];
        $this->assertIsReason($blocker, 'A driver blocker');

        // The sentence, not a label. This is what a dispatcher reads.
        $this->assertStringContainsString('licence', strtolower($blocker['why']),
            'The blocker does not say what is wrong. "Blocked" on its own sends somebody hunting.');
    }

    public function test_it_names_the_desk_that_can_clear_it(): void
    {
        $verdict = app(DriverEligibilityService::class)
            ->evaluate($this->unfitDriver(), $this->trip(), self::COMPANY);

        $this->assertSame('Fleet compliance desk', $verdict['blockers'][0]['owner'],
            'The owner did not survive. Before D-134 the reason was ours and obvious; now it is '
            .'another module\'s, and naming the desk is the whole value of the blocker.');
    }

    /**
     * The one rule still ours, and it must say so.
     *
     * Everything else a driver can be refused for now belongs to Fleet. The
     * assignment clash does not — Fleet cannot see trip_assignments (D-146) —
     * so if this stopped being reported, nothing anywhere would report it.
     */
    public function test_the_assignment_clash_is_refused_and_says_which_trip(): void
    {
        $driver = $this->fleetDriver([], self::COMPANY);
        $first = $this->trip();
        $second = $this->trip();

        DB::table('trip_assignments')->insert([
            'tenant_id' => self::COMPANY, 'trip_id' => $first->id,
            'driver_id' => $driver->id, 'status' => 'assigned', 'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $verdict = app(DriverEligibilityService::class)->evaluate($driver, $second, self::COMPANY);

        $this->assertFalse($verdict['eligible'], 'A driver on another live trip was offered.');

        $clash = collect($verdict['blockers'])->first(fn ($b) => str_contains(strtolower($b['why']), 'assigned'));

        $this->assertNotNull($clash, 'The clash was not reported at all.');
        $this->assertStringContainsString('#'.$first->id, $clash['why'],
            'The refusal does not name the trip to release, so a dispatcher cannot act on it.');
    }

    public function test_a_vehicle_blocker_is_the_same_shape_as_a_drivers(): void
    {
        $vehicle = $this->fleetVehicle([], self::COMPANY);
        $vehicle->update(['status' => 'UNDER_MAINTENANCE']);

        $verdict = app(VehicleEligibilityService::class)
            ->evaluate($vehicle->fresh(), $this->trip(), self::COMPANY);

        $this->assertFalse($verdict['eligible']);
        $this->assertNotEmpty($verdict['blockers'], 'nothing was blocked, so this proves nothing');
        $this->assertIsReason($verdict['blockers'][0], 'A vehicle blocker');
    }

    /** Warnings too — they were already objects, and must stay the same one. */
    public function test_warnings_carry_the_same_shape(): void
    {
        $verdict = app(DriverEligibilityService::class)
            ->evaluate($this->fleetDriver([], self::COMPANY), $this->trip(), self::COMPANY);

        $this->assertNotEmpty($verdict['warnings'],
            'no warning was produced, so this proves nothing — the demo drivers have no medical on file');

        $this->assertIsReason($verdict['warnings'][0], 'A driver warning');
    }

    public function test_the_helper_itself_never_emits_a_bare_string(): void
    {
        $verdict = EligibilityVerdict::make(['id' => 1], [
            EligibilityVerdict::check('k', 'Label', true, false, 'It is wrong.', 'Somebody'),
            EligibilityVerdict::check('w', 'Advisory', false, false, 'Worth knowing.'),
        ]);

        $this->assertIsReason($verdict['blockers'][0], 'EligibilityVerdict::make blocker');
        $this->assertIsReason($verdict['warnings'][0], 'EligibilityVerdict::make warning');
        $this->assertSame('Somebody', $verdict['blockers'][0]['owner']);
        $this->assertNull($verdict['warnings'][0]['owner'], 'an ownerless rule must say null, not invent a desk');
    }
}
