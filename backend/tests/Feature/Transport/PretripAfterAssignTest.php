<?php

namespace Tests\Feature\Transport;

use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripResult;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * Assigning a crew re-runs a checklist that was evaluated without it.
 *
 * Seen live on TRP-RACE-53: checks evaluated at 05:53:02, driver assigned at
 * 05:53:16, and the panel said "No driver is assigned" until Refresh was
 * clicked. The rows are read straight back here — no generate() between the
 * assignment and the assertion.
 */
class PretripAfterAssignTest extends TestCase
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

    private function approvedTrip(): TransportTrip
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

        return $trip->fresh();
    }

    private function crew(): array
    {
        $v = $this->moveFleetVehicle($this->fleetVehicle([], self::TENANT_A, $this->actor), Vehicle::STATUS_AVAILABLE);
        $d = $this->fleetDriver(['medical_expiry' => now()->addYears(2)->toDateString()], self::TENANT_A, $this->actor);

        return [$v, $d];
    }

    private function keyed(TransportTrip $trip)
    {
        return $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)->keyBy('check_key');
    }

    public function test_assigning_a_driver_re_evaluates_the_crew_checks_without_a_refresh(): void
    {
        $trip = $this->approvedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $before = $this->keyed($trip);
        $this->assertStringContainsString('No driver is assigned', $before[PretripCheckKey::DRIVER_ASSIGNED]->detail,
            'precondition: the checklist was evaluated before the crew existed');
        $this->assertStringContainsString('No driver is assigned', $before[PretripCheckKey::DRIVER_DOCUMENTS]->detail);

        [$v, $d] = $this->crew();
        app(AllocationService::class)->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $after = $this->keyed($trip);

        $this->assertSame(PretripResult::PASS, $after[PretripCheckKey::DRIVER_ASSIGNED]->result,
            'driver.assigned still describes the trip before the assignment');
        $this->assertStringNotContainsString('No driver is assigned', $after[PretripCheckKey::DRIVER_DOCUMENTS]->detail,
            'driver.documents_valid was not re-evaluated');
        $this->assertSame(PretripResult::PASS, $after[PretripCheckKey::DRIVER_DOCUMENTS]->result);
        $this->assertSame(PretripResult::PASS, $after[PretripCheckKey::VEHICLE_ASSIGNED]->result);
    }

    /**
     * The other half of the rule: a release INVALIDATES, and a re-crewed trip
     * must earn its checklist again. Assigning must not quietly regenerate it.
     */
    public function test_a_checklist_invalidated_by_a_release_is_not_regenerated_by_the_next_assignment(): void
    {
        $trip = $this->approvedTrip();
        [$v, $d] = $this->crew();
        $alloc = app(AllocationService::class);
        $result = $alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);

        $alloc->release($result['assignment'], self::TENANT_A, $this->actor, 'Breakdown');
        [$v2, $d2] = $this->crew();
        app(AllocationService::class)->assign($trip->fresh(), $v2->id, $d2->id, self::TENANT_A, $this->actor);

        foreach ($this->keyed($trip) as $check) {
            $this->assertSame(PretripResult::PENDING, $check->result, $check->check_key.' was regenerated automatically');
        }
    }
}
