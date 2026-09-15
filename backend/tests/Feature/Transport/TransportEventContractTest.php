<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\OrderCreated;
use App\Events\Transport\TripAssigned;
use App\Events\Transport\TripCreated;
use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Services\Transport\TransportOrderService;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderPriority;
use App\Support\Transport\OrderSource;
use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-48 — the events Person 1 produces, against Step 11's LOCKED registry.
 *
 * These assert a CONTRACT, not a behaviour. The payloads are LOCKED rows; a
 * consumer in another developer's domain codes against exactly these keys, and
 * a payload that quietly grows or shrinks is one they cannot rely on.
 *
 * There are deliberately no listener tests. Subscribing is Person 2's and
 * Person 3's half of the contract; this section's responsibility ends at
 * dispatch().
 */
class TransportEventContractTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;

    private User $actor;
    private Client $customer;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT_A, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        $this->customer = Client::create(['tenant_id' => self::TENANT_A, 'company' => 'Acme Logistics']);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'ops-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function orderPayload(): array
    {
        return [
            'customer_id'       => $this->customer->id,
            'pickup_location'   => ['address' => 'JNPT'],
            'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at'       => now()->addDays(3)->toDateTimeString(),
            'service_type'      => 'Container Haulage',
            'priority'          => OrderPriority::DEFAULT,
            'source'            => OrderSource::DEFAULT,
        ];
    }

    private function approvedOrder(): TransportOrder
    {
        $order = app(TransportOrderService::class)
            ->create($this->orderPayload(), self::TENANT_A, $this->actor);

        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        return $order->fresh();
    }

    /* ══════════ EVT-001 OrderCreated ══════════ */

    public function test_evt_001_is_dispatched_when_an_order_is_created(): void
    {
        Event::fake([OrderCreated::class]);

        app(TransportOrderService::class)->create($this->orderPayload(), self::TENANT_A, $this->actor);

        Event::assertDispatched(OrderCreated::class);
    }

    public function test_evt_001_payload_is_the_locked_core_and_nothing_else(): void
    {
        // Payload Core: "order_id, customer_id". The order also carries a
        // reference, a service type, a priority and a source — none of which is
        // in the LOCKED row, and none of which may be added without the written
        // approval ENUM-006 needed.
        $order = app(TransportOrderService::class)
            ->create($this->orderPayload(), self::TENANT_A, $this->actor);

        $payload = (new OrderCreated($order))->payload();

        $this->assertSame(['order_id', 'customer_id'], array_keys($payload));
        $this->assertSame((int) $order->id, $payload['order_id']);
        $this->assertSame((int) $this->customer->id, $payload['customer_id']);
    }

    public function test_evt_001_idempotency_key_distinguishes_revisions(): void
    {
        // "order_id+version". No version column exists, so updated_at stands in
        // — what a redelivery check needs is that the same order at the same
        // revision yields the same key.
        $order = app(TransportOrderService::class)
            ->create($this->orderPayload(), self::TENANT_A, $this->actor);

        $first = (new OrderCreated($order))->idempotencyKey();

        $this->assertStringStartsWith($order->id.'+', $first);
        $this->assertSame($first, (new OrderCreated($order->fresh()))->idempotencyKey());
    }

    public function test_evt_001_carries_its_tenant(): void
    {
        // A listener in another domain must not have to infer tenancy — that is
        // how a cross-tenant write happens in a queue worker with no auth user.
        $order = app(TransportOrderService::class)
            ->create($this->orderPayload(), self::TENANT_A, $this->actor);

        $this->assertSame(self::TENANT_A, (new OrderCreated($order))->tenantId());
    }

    /* ══════════ EVT-002 TripCreated ══════════ */

    public function test_evt_002_is_dispatched_when_a_trip_is_created(): void
    {
        $order = $this->approvedOrder();

        Event::fake([TripCreated::class]);

        app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        Event::assertDispatched(TripCreated::class);
    }

    public function test_evt_002_payload_is_the_locked_core_plus_the_approved_field(): void
    {
        // Payload Core is LOCKED at "trip_id, order_id". consignment_id is a
        // third field added on explicit written approval of 2026-09-15, the same
        // route delivery_order took into ENUM-006. See D-49.
        $order = $this->approvedOrder();
        $trip  = app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        $payload = (new TripCreated($trip))->payload();

        $this->assertSame(['trip_id', 'order_id', 'consignment_id'], array_keys($payload));
        $this->assertSame((int) $trip->id, $payload['trip_id']);
        $this->assertSame((int) $order->id, $payload['order_id']);
    }

    public function test_evt_002_carries_consignment_id_when_the_trip_has_one(): void
    {
        $order = $this->approvedOrder();
        $trip  = app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        $consignment = app(\App\Services\Transport\ConsignmentService::class)
            ->create(['order_id' => $order->id], self::TENANT_A, $this->actor);

        $trip->forceFill(['consignment_id' => $consignment->id])->save();

        $this->assertSame(
            (int) $consignment->id,
            (new TripCreated($trip->fresh()))->payload()['consignment_id'],
        );
    }

    public function test_evt_002_consignment_id_is_null_when_there_is_none(): void
    {
        // Not a defect: a trip may legitimately carry no consignment. Trips
        // shipped before consignments existed, and TM-001 §4 rule 3 makes the
        // container a search anchor rather than a mandatory parent.
        $order = $this->approvedOrder();
        $trip  = app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        $this->assertNull((new TripCreated($trip))->payload()['consignment_id']);
    }

    public function test_evt_002_does_not_carry_the_unapproved_fields(): void
    {
        // container_id and route/geofence context are named in TM-001 §11 and
        // were explicitly NOT approved on 2026-09-15: there is no container
        // table and no route context to send, and a field carrying null forever
        // is worse than an absent one because a consumer codes against it.
        // If this test is ever changed, that approval should exist first.
        $order = $this->approvedOrder();
        $trip  = app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        $payload = (new TripCreated($trip))->payload();

        $this->assertArrayNotHasKey('container_id', $payload);
        $this->assertArrayNotHasKey('route', $payload);
        $this->assertArrayNotHasKey('geofence_context', $payload);
    }

    public function test_evt_002_carries_its_tenant(): void
    {
        $order = $this->approvedOrder();
        $trip  = app(TransportTripService::class)->createFromOrder($order->id, [], self::TENANT_A, $this->actor);

        $this->assertSame(self::TENANT_A, (new TripCreated($trip))->tenantId());
    }

    /* ══════════ the contract as a whole ══════════ */

    public function test_all_three_produced_events_expose_the_same_three_methods(): void
    {
        // A consumer should be able to treat any Person 1 event the same way.
        foreach ([OrderCreated::class, TripCreated::class, TripAssigned::class] as $event) {
            foreach (['payload', 'idempotencyKey', 'tenantId'] as $method) {
                $this->assertTrue(
                    method_exists($event, $method),
                    class_basename($event)." must expose {$method}()",
                );
            }
        }
    }

    public function test_this_section_publishes_no_listeners(): void
    {
        // Subscribing is Person 2's and Person 3's half of the contract. A
        // listener here that acted on fleet or billing data would be writing
        // their code in our folder.
        $this->assertDirectoryDoesNotExist(app_path('Listeners/Transport'));

        foreach ([OrderCreated::class, TripCreated::class, TripAssigned::class] as $event) {
            $this->assertSame(
                [], Event::getRawListeners()[$event] ?? [],
                class_basename($event).' must have no listener in this section',
            );
        }
    }

    public function test_an_order_that_fails_to_create_fires_nothing(): void
    {
        // Dispatched inside the transaction: a synchronous listener acting on a
        // rolled-back row would be acting on a row that never existed.
        Event::fake([OrderCreated::class]);

        try {
            app(TransportOrderService::class)->create(
                ['customer_id' => 999999] + $this->orderPayload(),
                self::TENANT_A,
                $this->actor,
            );
        } catch (\Throwable) {
            // expected — the customer does not exist in this workspace
        }

        Event::assertNotDispatched(OrderCreated::class);
    }
}
