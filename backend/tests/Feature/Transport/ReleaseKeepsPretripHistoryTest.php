<?php

namespace Tests\Feature\Transport;

use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripResult;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * D-154 — a release keeps the pre-trip record of a trip that has left.
 *
 * TRP-2026-000034 closed on 19 Sep; its crew was released by hand on 21 Sep,
 * and release() invalidated its passed checklist into five pending rows.
 * Before departure the checklist is a gate and a new crew must earn it again;
 * after departure it is history and must stay as it was.
 */
class ReleaseKeepsPretripHistoryTest extends TestCase
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

    /** Allocated, checklist generated and confirmed, pre-trip passed. */
    private function passedTrip(): TransportTrip
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
        $d = $this->fleetDriver(['medical_expiry' => now()->addYears(2)->toDateString()], self::TENANT_A, $this->actor);
        app(AllocationService::class)->assign($trip->fresh(), $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip->fresh(), self::TENANT_A) as $c) {
            $this->pretrip->complete($c, self::TENANT_A, $this->actor);
        }

        return $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
    }

    /** @return array<string,string> check_key => "result|evaluated_at|completed_by|completed_at" */
    private function snapshot(TransportTrip $trip): array
    {
        return TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->orderBy('check_key')->get()
            ->mapWithKeys(fn (TripPretripCheck $c) => [$c->check_key => implode('|', [
                $c->result, $c->evaluated_at?->toIso8601String(), $c->completed_by, $c->completed_at?->toIso8601String(),
            ])])->all();
    }

    private function release(TransportTrip $trip): void
    {
        $assignment = TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->active()->firstOrFail();
        app(AllocationService::class)->release($assignment, self::TENANT_A, $this->actor, 'trip is done');
    }

    /** Before departure the checklist is a gate: releasing the crew resets it. Behaviour kept. */
    public function test_a_release_before_departure_still_resets_the_checklist(): void
    {
        $trip = $this->passedTrip();
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->status, 'precondition: pre-trip passed');

        $this->release($trip);

        foreach (TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get() as $check) {
            $this->assertSame(PretripResult::PENDING, $check->result, $check->check_key.' kept a stamp for a crew that is gone');
        }
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
    }

    public static function departedStatuses(): array
    {
        return [
            'dispatched' => [TripStatus::DISPATCHED],
            'in transit' => [TripStatus::IN_TRANSIT],
            'closed'     => [TripStatus::CLOSED],
        ];
    }

    #[DataProvider('departedStatuses')]
    public function test_a_release_after_departure_leaves_the_pretrip_record_as_it_was(string $status): void
    {
        $trip = $this->passedTrip();
        // The subject here is release, not the transitions, so the trip is
        // placed where it would stand after leaving.
        $trip->forceFill(['status' => $status])->save();

        $before = $this->snapshot($trip);
        $this->assertCount(5, $before, 'precondition: a full checklist exists');
        $this->assertNotContains(PretripResult::PENDING, array_map(fn ($s) => explode('|', $s)[0], $before),
            'precondition: the checklist was passed, not pending');

        $this->release($trip);

        $this->assertSame($before, $this->snapshot($trip), "a release rewrote the pre-trip record of a {$status} trip");
        $this->assertSame($status, $trip->fresh()->status, 'the trip itself must not move');
    }
}
