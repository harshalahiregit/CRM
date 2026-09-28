<?php

namespace Tests\Feature\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\DriverService;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripDriverDocuments;
use App\Support\Transport\PretripResult;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * D-151 — the pre-trip "driver documents" item checks a driver again.
 *
 * Since D-134 it borrowed two checks that no longer existed, matched nothing,
 * and passed every driver: a licence that lapsed after allocation was not
 * stopped at pre-trip or at dispatch. It now reads Fleet's reason codes —
 * exactly the five the owner ruled (option b) — and cannot pass on an empty
 * read.
 */
class PretripDriverDocumentsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const TENANT_A = 1;

    private PretripService $pretrip;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT_A, 'name' => 'Alpha Transport', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        $this->pretrip = app(PretripService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Supervisor', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 's-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** An approved trip with a vehicle and a clean driver allocated. */
    private function allocatedTrip(): array
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT_A, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        $v = $this->moveFleetVehicle($this->fleetVehicle([], self::TENANT_A, $this->actor), Vehicle::STATUS_AVAILABLE);
        $d = $this->fleetDriver([
            'licence_expiry' => now()->addYears(2)->toDateString(),
            'medical_expiry' => now()->addYears(2)->toDateString(),
        ], self::TENANT_A, $this->actor);

        // Resolved fresh: DriverEligibilityService caches the directory per
        // instance (D-152), and this driver did not exist a moment ago.
        app(AllocationService::class)->assign($trip->fresh(), $v->id, $d->id, self::TENANT_A, $this->actor);

        return [$trip->fresh(), $d->fresh()];
    }

    private function driverItem(TransportTrip $trip)
    {
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        return $this->pretrip->checksFor($trip, self::TENANT_A)->keyBy('check_key')[PretripCheckKey::DRIVER_DOCUMENTS];
    }

    /* ══════════ what a dispatcher needs ══════════ */

    public function test_a_licence_that_lapses_after_allocation_fails_pretrip_with_fleets_reason(): void
    {
        [$trip, $driver] = $this->allocatedTrip();
        $driver->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

        $item = $this->driverItem($trip);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $item->result);
        $this->assertTrue($item->blocks());
        $this->assertStringContainsString('Licence expired 1 day ago', $item->detail, "Fleet's own sentence, not a summary");
        $this->assertStringContainsString('(Fleet compliance desk)', $item->detail, 'the desk that can clear it');
    }

    public function test_an_expired_medical_fails_pretrip(): void
    {
        [$trip, $driver] = $this->allocatedTrip();
        $driver->forceFill(['medical_expiry' => now()->subDays(2)->toDateString()])->save();

        $item = $this->driverItem($trip);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $item->result);
        $this->assertStringContainsString('Medical expired', $item->detail);
    }

    public function test_an_expiring_licence_warns_without_blocking(): void
    {
        [$trip, $driver] = $this->allocatedTrip();
        $driver->forceFill(['licence_expiry' => now()->addDays(10)->toDateString()])->save();

        $item = $this->driverItem($trip);

        $this->assertSame(PretripResult::PASS_WARNING, $item->result);
        $this->assertFalse($item->blocks());
        $this->assertStringContainsString('Licence expires in 10 days', $item->detail);
        $this->assertStringContainsString('(Fleet compliance desk)', $item->detail);
    }

    /**
     * The trap. Allocation sets the driver ON_TRIP, and Fleet answers that with
     * `driver_unavailable`. Borrowing Fleet's verdict wholesale would fail
     * pre-trip for every allocated driver; this proves it is not borrowed.
     */
    public function test_an_allocated_driver_with_valid_documents_passes(): void
    {
        [$trip, $driver] = $this->allocatedTrip();

        $this->assertSame(DriverProfile::ON_TRIP, $driver->status, 'precondition: allocation took the driver');
        $row = collect(app(DriverService::class)->eligible(self::TENANT_A)['excluded'])
            ->firstWhere('profile.id', $driver->id);
        $this->assertContains('driver_unavailable', array_column($row['blockers'] ?? [], 'code'),
            'precondition: Fleet itself calls this driver unavailable — otherwise the trap is not being tested');

        $item = $this->driverItem($trip);

        $this->assertSame(PretripResult::PASS, $item->result, $item->detail);
    }

    /**
     * D-151(i), closed by Fleet on 26 Sep: a licence that has not started yet.
     * Fleet added `not_yet_valid` and `driver_license_not_yet_valid`; before
     * pre-trip learned the code, that driver passed.
     */
    public function test_a_licence_that_has_not_started_yet_fails_pretrip_with_fleets_reason(): void
    {
        [$trip, $driver] = $this->allocatedTrip();
        $driver->forceFill(['licence_valid_from' => now()->addDays(5)->toDateString()])->save();

        $item = $this->driverItem($trip);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $item->result);
        $this->assertStringContainsString('does not take effect until', $item->detail, "Fleet's own sentence");
        $this->assertStringContainsString('(Fleet compliance desk)', $item->detail);
    }

    /** Fail closed: a state Fleet invents tomorrow must stop the driver, not wave them through. */
    public function test_a_state_fleet_has_not_told_us_about_fails_closed(): void
    {
        $row = [
            'licence'  => ['state' => 'suspended_by_rto', 'message' => 'Suspended by the RTO.'],
            'medical'  => ['state' => 'valid', 'message' => 'Medical valid.'],
            'blockers' => [],
            'warnings' => [],
        ];

        [$result, $detail, $error] = PretripDriverDocuments::judge($row, 'Driver X', true);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $result, 'an unknown state must never grade PASS');
        $this->assertStringContainsString("unrecognised Fleet licence state 'suspended_by_rto'", $detail);
        $this->assertNotNull($error, 'the caller must get something to log');

        $row['licence']['state'] = 'valid';
        $row['medical']['state'] = 'lapsed';
        [$result, $detail] = PretripDriverDocuments::judge($row, 'Driver X', true);
        $this->assertSame(PretripResult::CRITICAL_FAIL, $result);
        $this->assertStringContainsString("unrecognised Fleet medical state 'lapsed'", $detail);
    }

    /* ══════════ an empty read can never pass ══════════ */

    public function test_a_fleet_row_without_the_verdicts_this_reads_fails_loudly(): void
    {
        [$result, $detail, $error] = PretripDriverDocuments::judge(
            ['blockers' => [], 'warnings' => []], 'Driver X', true,
        );

        $this->assertSame(PretripResult::CRITICAL_FAIL, $result, 'nothing to read must never grade PASS');
        $this->assertStringContainsString('could not be verified', $detail);
        $this->assertNotNull($error);
    }

    public function test_a_renamed_fleet_code_fails_loudly_rather_than_passing(): void
    {
        // Fleet says the licence is expired but names it with a code this check
        // does not know — the D-134 shape of drift, one level down.
        $row = [
            'licence'  => ['state' => 'expired', 'message' => 'Licence expired 3 days ago.'],
            'medical'  => ['state' => 'valid', 'message' => 'Medical valid.'],
            'blockers' => [['code' => 'licence_lapsed', 'why' => 'Licence expired 3 days ago.', 'owner' => 'Fleet compliance desk']],
        ];

        [$result, , $error] = PretripDriverDocuments::judge($row, 'Driver X', true);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $result);
        $this->assertStringContainsString('driver_license_expired', (string) $error);
    }

    public function test_a_driver_fleet_does_not_know_fails(): void
    {
        [$result, $detail] = PretripDriverDocuments::judge(null, 'Driver X', true);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $result);
        $this->assertStringContainsString('not in the fleet directory', $detail);
    }

    /** The same guard on the vehicle path's borrowed checks. */
    public function test_a_borrow_that_matches_no_check_cannot_pass(): void
    {
        $borrow = new \ReflectionMethod(PretripService::class, 'fromBorrowedChecks');

        [$result, $detail] = $borrow->invoke(
            $this->pretrip,
            ['checks' => [['key' => 'status', 'passed' => true, 'detail' => 'Available']]],
            ['licence', 'documents'],
            'Vehicle X', true, self::TENANT_A, [],
        );

        $this->assertSame(PretripResult::CRITICAL_FAIL, $result);
        $this->assertStringContainsString('could not be verified', $detail);
    }
}
