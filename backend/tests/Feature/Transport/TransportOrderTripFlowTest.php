<?php

namespace Tests\Feature\Transport;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Support\Transport\OrderPriority;
use App\Support\Transport\OrderSource;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * SNG-TRN-006 + SNG-TRN-007 — Order creation and Order → Trip conversion.
 *
 * Covers the acceptance criteria of both tickets, the business rules they cite,
 * and the end-to-end tenant isolation proof: tenant A creates an order and a
 * trip; tenant B can neither see nor touch either.
 */
class TransportOrderTripFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha Transport', self::TENANT_B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $tenantId, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => ucfirst($role).' T'.$tenantId,
            'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
    }

    private function customer(int $tenantId, string $company = 'Aditya Infra'): Client
    {
        return Client::create(['tenant_id' => $tenantId, 'company' => $company]);
    }

    /** @return array{0:User,1:Client} */
    private function actorWithCustomer(int $tenantId): array
    {
        $actor = $this->user($tenantId);
        Sanctum::actingAs($actor);

        return [$actor, $this->customer($tenantId)];
    }

    private function orderPayload(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'customer_id'        => $customerId,
            'customer_reference' => 'PO-88213',
            'pickup_location'    => ['address' => 'JNPT Terminal Gate 3', 'city' => 'Navi Mumbai', 'state' => 'Maharashtra'],
            'delivery_location'  => ['address' => 'Plot 14, MIDC', 'city' => 'Pune', 'state' => 'Maharashtra'],
            'required_at'        => '2026-10-04 08:30:00',
            'service_type'       => 'Container Haulage',
            'priority'           => OrderPriority::URGENT,
            'source'             => OrderSource::MANUAL,
            'route'              => 'JNPT → Pune',
        ], $overrides);
    }

    /** Create an order via the API and return its id. */
    private function createOrder(int $customerId, array $overrides = []): int
    {
        $res = $this->postJson('/api/transport/orders', $this->orderPayload($customerId, $overrides));
        $res->assertStatus(201);

        return (int) $res->json('data.id');
    }

    private function approve(int $orderId): void
    {
        $this->patchJson("/api/transport/orders/{$orderId}/status", ['status' => OrderStatus::SUBMITTED])->assertOk();
        $this->patchJson("/api/transport/orders/{$orderId}/status", ['status' => OrderStatus::APPROVED])->assertOk();
    }

    /* ── SNG-TRN-006 — Order creation ─────────────────────────────────── */

    public function test_an_order_is_created_in_draft_with_an_allocated_number(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);

        $res = $this->postJson('/api/transport/orders', $this->orderPayload($customer->id));

        $res->assertStatus(201)
            ->assertJsonPath('data.order_status', OrderStatus::DRAFT)
            ->assertJsonPath('data.priority', OrderPriority::URGENT)
            ->assertJsonPath('data.source', OrderSource::MANUAL);

        $order = TransportOrder::find($res->json('data.id'));
        $this->assertSame(self::TENANT_A, (int) $order->tenant_id);
        // STOS-DB §161 — numbered per organization by the shared engine.
        $this->assertMatchesRegularExpression('/^TO-\d{4}-\d{6}$/', $order->order_number);
    }

    public function test_order_numbers_are_unique_and_sequential_within_a_tenant(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);

        $first  = TransportOrder::find($this->createOrder($customer->id));
        $second = TransportOrder::find($this->createOrder($customer->id));

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertSame(2, TransportOrder::forTenant(self::TENANT_A)->count());
    }

    public function test_order_creation_is_rejected_without_the_mandatory_fields(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);

        // BRW-016 minimum set + OPS §9/BRW-018 mandatory priority + OPS §6 source.
        $this->postJson('/api/transport/orders', ['customer_id' => $customer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'pickup_location', 'delivery_location', 'required_at',
                'service_type', 'priority', 'source',
            ]);
    }

    public function test_an_order_cannot_name_another_tenants_customer(): void
    {
        $this->actorWithCustomer(self::TENANT_A);
        $foreign = $this->customer(self::TENANT_B, 'Bravo Customer');

        // CTR-001 — "exists in tenant… No cross-tenant IDs."
        $this->postJson('/api/transport/orders', $this->orderPayload($foreign->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_priority_outside_the_four_values_is_rejected(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);

        $this->postJson('/api/transport/orders', $this->orderPayload($customer->id, ['priority' => 'Whenever']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    /* ── SM-ORD transitions ───────────────────────────────────────────── */

    public function test_an_order_moves_draft_to_submitted_to_approved(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $id = $this->createOrder($customer->id);

        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::SUBMITTED])
            ->assertOk()->assertJsonPath('data.order_status', OrderStatus::SUBMITTED);

        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::APPROVED])
            ->assertOk()->assertJsonPath('data.order_status', OrderStatus::APPROVED);
    }

    public function test_an_undeclared_transition_is_refused(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $id = $this->createOrder($customer->id);

        // draft → approved is not in SM-ORD; only draft → submitted is.
        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::APPROVED])
            ->assertStatus(422);
    }

    public function test_rejecting_an_order_requires_a_reason(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $id = $this->createOrder($customer->id);
        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::SUBMITTED])->assertOk();

        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::REJECTED])
            ->assertStatus(422);

        $this->patchJson("/api/transport/orders/{$id}/status", [
            'status' => OrderStatus::REJECTED, 'reason' => 'Rate not agreed.',
        ])->assertOk()->assertJsonPath('data.order_status', OrderStatus::REJECTED);
    }

    public function test_a_submitted_order_can_no_longer_be_edited(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $id = $this->createOrder($customer->id);
        $this->patchJson("/api/transport/orders/{$id}/status", ['status' => OrderStatus::SUBMITTED])->assertOk();

        $this->putJson("/api/transport/orders/{$id}", ['service_type' => 'Changed'])
            ->assertStatus(422);
    }

    /* ── SNG-TRN-007 — Order → Trip ───────────────────────────────────── */

    public function test_an_approved_order_becomes_a_trip_in_draft(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);

        $res = $this->postJson('/api/transport/trips', [
            'order_id' => $orderId, 'approved_freight' => 86000, 'currency' => 'INR',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', TripStatus::DRAFT)
            ->assertJsonPath('data.order_id', $orderId);

        $trip = TransportTrip::find($res->json('data.id'));
        $this->assertSame(self::TENANT_A, (int) $trip->tenant_id);
        // BR-P0-001 — unique within company/year.
        $this->assertMatchesRegularExpression('/^TRP-\d{4}-\d{6}$/', $trip->trip_number);
        // OPS §37 — the trip carries its customer.
        $this->assertSame($customer->id, (int) $trip->customer_id);
    }

    public function test_a_trip_cannot_be_created_from_an_unapproved_order(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);   // still draft

        $this->postJson('/api/transport/trips', ['order_id' => $orderId])
            ->assertStatus(422);
    }

    public function test_an_order_cannot_have_two_active_trips(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);

        $this->postJson('/api/transport/trips', ['order_id' => $orderId])->assertStatus(201);

        // TRP-P0-001 — "no duplicate active trip against same shipment".
        $this->postJson('/api/transport/trips', ['order_id' => $orderId])->assertStatus(422);
    }

    public function test_a_trip_cannot_be_created_without_an_order(): void
    {
        $this->actorWithCustomer(self::TENANT_A);

        // CTR-004 — "Cannot create orphan trip."
        $this->postJson('/api/transport/trips', ['approved_freight' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_id']);
    }

    public function test_trip_number_is_immutable_once_issued(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);
        $tripId = $this->postJson('/api/transport/trips', ['order_id' => $orderId])->json('data.id');

        $trip = TransportTrip::find($tripId);

        // Step 11 FLD-006 marks trip_number IMMUTABLE.
        $this->expectException(RuntimeException::class);
        $trip->update(['trip_number' => 'TRP-2026-999999']);
    }

    public function test_submit_for_viability_moves_draft_to_viability_pending(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);
        $tripId = $this->postJson('/api/transport/trips', [
            'order_id' => $orderId, 'approved_freight' => 86000,
        ])->json('data.id');

        // STT-001, the only transition this ticket owns.
        $this->patchJson("/api/transport/trips/{$tripId}/submit-viability")
            ->assertOk()
            ->assertJsonPath('data.status', TripStatus::VIABILITY_PENDING);
    }

    public function test_a_trip_without_approved_freight_cannot_be_submitted_for_viability(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);
        $tripId = $this->postJson('/api/transport/trips', ['order_id' => $orderId])->json('data.id');

        $this->patchJson("/api/transport/trips/{$tripId}/submit-viability")->assertStatus(422);
    }

    /* ── Audit trail — the foundation proving itself end to end ───────── */

    public function test_creating_an_order_and_a_trip_writes_real_audit_entries(): void
    {
        [$actor, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);
        $this->approve($orderId);
        $tripId = $this->postJson('/api/transport/trips', [
            'order_id' => $orderId, 'approved_freight' => 86000,
        ])->json('data.id');
        $this->patchJson("/api/transport/trips/{$tripId}/submit-viability")->assertOk();

        $actions = TransportAuditLog::forTenant(self::TENANT_A)->pluck('action')->all();

        $this->assertContains('transport.order.created', $actions);
        $this->assertContains('transport.order.status_changed', $actions);
        $this->assertContains('transport.trip.created', $actions);
        // The ORDER's own history shows it became a trip, without needing to
        // know the trip exists in order to look for it.
        $this->assertContains('transport.order.trip_created', $actions);
        $this->assertContains('transport.trip.status_changed', $actions);

        // Evidence names the actor and carries the before/after of the move.
        $transition = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('action', 'transport.trip.status_changed')->first();

        $this->assertSame($actor->id, $transition->actor_id);
        $this->assertSame($actor->name, $transition->actor_name);
        $this->assertSame(['status' => TripStatus::DRAFT], $transition->old_values);
        $this->assertSame(['status' => TripStatus::VIABILITY_PENDING], $transition->new_values);
    }

    public function test_the_detail_endpoint_returns_the_audit_trail(): void
    {
        [, $customer] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customer->id);

        $this->getJson("/api/transport/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonStructure(['data' => ['order', 'audit']]);
    }

    /* ── END-TO-END TENANT ISOLATION ──────────────────────────────────── */

    public function test_tenant_b_can_neither_see_nor_touch_tenant_a_records(): void
    {
        // ---- Tenant A: create an order, approve it, convert it to a trip ----
        [, $customerA] = $this->actorWithCustomer(self::TENANT_A);
        $orderId = $this->createOrder($customerA->id);
        $this->approve($orderId);
        $tripId = $this->postJson('/api/transport/trips', [
            'order_id' => $orderId, 'approved_freight' => 86000,
        ])->json('data.id');

        $this->assertNotNull($orderId);
        $this->assertNotNull($tripId);

        // ---- Tenant B, a legitimate admin of its own workspace ----
        Sanctum::actingAs($this->user(self::TENANT_B));

        // 1. Lists show nothing of tenant A's.
        $this->getJson('/api/transport/orders')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/transport/trips')->assertOk()->assertJsonCount(0, 'data.data');

        // 2. Direct reads by id are refused — and as "not found", never "not
        //    yours", so the record's existence is not confirmed.
        $this->getJson("/api/transport/orders/{$orderId}")->assertStatus(404);
        $this->getJson("/api/transport/trips/{$tripId}")->assertStatus(404);

        // 3. Writes are refused too — reading is not the only way to leak.
        $this->putJson("/api/transport/orders/{$orderId}", ['service_type' => 'Hijacked'])->assertStatus(404);
        $this->patchJson("/api/transport/orders/{$orderId}/status", ['status' => OrderStatus::SUBMITTED])->assertStatus(404);
        $this->putJson("/api/transport/trips/{$tripId}", ['approved_freight' => 1])->assertStatus(404);
        $this->patchJson("/api/transport/trips/{$tripId}/submit-viability")->assertStatus(404);

        // 4. Tenant B cannot build its own trip on tenant A's order.
        $this->postJson('/api/transport/trips', ['order_id' => $orderId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_id']);

        // 5. Nothing was mutated by any of the above.
        $this->assertSame(OrderStatus::APPROVED, TransportOrder::find($orderId)->order_status);
        $this->assertSame('86000.00', TransportTrip::find($tripId)->approved_freight);
        $this->assertSame(TripStatus::DRAFT, TransportTrip::find($tripId)->status);

        // 6. And tenant A's audit trail is invisible to tenant B.
        $this->assertSame(0, TransportAuditLog::forTenant(self::TENANT_B)->count());
        $this->assertGreaterThan(0, TransportAuditLog::forTenant(self::TENANT_A)->count());
    }

    public function test_a_client_role_cannot_reach_the_transport_surface(): void
    {
        // role:admin,staff is the coarse door — a portal identity never gets in,
        // regardless of what the permission matrix would say.
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/api/transport/orders')->assertStatus(403);
        $this->postJson('/api/transport/orders', [])->assertStatus(403);
        $this->getJson('/api/transport/trips')->assertStatus(403);
    }
}
