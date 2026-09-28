<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consignment API — Block 1 steps 6, 7 and 8.
 *
 * Six endpoints, none in Step 11's API registry and none owned by a Step 12
 * ticket (D-38). Covers ORD-004, CTD-002 and CTD-003 end to end.
 *
 * The refusals carry most of the weight here. An endpoint that creates
 * correctly but answers for another tenant is a leak that every happy-path
 * assertion would pass.
 */
class ConsignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function order(int $tenantId = self::TENANT_A, int $customerId = 7): TransportOrder
    {
        return TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => $customerId,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
    }

    private function make(int $tenantId = self::TENANT_A): TransportConsignment
    {
        return TransportConsignment::create([
            'tenant_id'          => $tenantId,
            'consignment_number' => TransportConsignment::nextLocalNumber($tenantId),
            'order_id'           => $this->order($tenantId)->id,
            'customer_id'        => 7,
        ]);
    }

    /* ══════════ create ══════════ */

    public function test_creating_returns_201_with_an_allocated_number(): void
    {
        Sanctum::actingAs($this->user());
        $order = $this->order();

        $res = $this->postJson('/api/transport/consignments', [
            'order_id'           => $order->id,
            'customer_reference' => 'PO-8891',
            'cargo_description'  => '48 drums, palletised',
            'package_count'      => 48,
            'gross_weight_kg'    => 12450.5,
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^CNM-\d{4}-\d{6}$/', $res->json('data.consignment_number'));
        $this->assertSame($order->id, $res->json('data.order_id'));
        // CTD-002 — taken from the order, not the caller.
        $this->assertSame(7, $res->json('data.customer_id'));
    }

    public function test_the_number_and_customer_cannot_be_chosen_by_the_caller(): void
    {
        Sanctum::actingAs($this->user());

        $res = $this->postJson('/api/transport/consignments', [
            'order_id'           => $this->order()->id,
            'consignment_number' => 'CNM-1999-000001',
            'customer_id'        => 999,
        ])->assertCreated();

        $this->assertNotSame('CNM-1999-000001', $res->json('data.consignment_number'));
        $this->assertSame(7, $res->json('data.customer_id'));
    }

    public function test_an_order_is_required(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/transport/consignments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    public function test_another_tenants_order_fails_validation_not_creation(): void
    {
        // The tenant-scoped exists rule highlights the field rather than
        // letting the request reach the service and come back as a bare 422.
        Sanctum::actingAs($this->user(self::TENANT_A));
        $foreign = $this->order(self::TENANT_B);

        $this->postJson('/api/transport/consignments', ['order_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');

        $this->assertSame(0, TransportConsignment::count());
    }

    public function test_impossible_cargo_measures_are_refused(): void
    {
        Sanctum::actingAs($this->user());
        $order = $this->order();

        $this->postJson('/api/transport/consignments', [
            'order_id' => $order->id, 'package_count' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('package_count');

        $this->postJson('/api/transport/consignments', [
            'order_id' => $order->id, 'gross_weight_kg' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors('gross_weight_kg');
    }

    /* ══════════ read ══════════ */

    public function test_the_list_is_tenant_scoped(): void
    {
        $this->make(self::TENANT_A);
        $this->make(self::TENANT_B);

        Sanctum::actingAs($this->user(self::TENANT_A));
        $this->assertSame(1, $this->getJson('/api/transport/consignments')->assertOk()->json('data.total'));

        Sanctum::actingAs($this->user(self::TENANT_B));
        $this->assertSame(1, $this->getJson('/api/transport/consignments')->assertOk()->json('data.total'));
    }

    public function test_show_returns_the_consignment_its_trips_and_its_audit(): void
    {
        Sanctum::actingAs($this->user());
        $c = $this->make();

        TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $c->order_id, 'consignment_id' => $c->id,
            'customer_id' => 7, 'trip_number' => 'TRP-'.Str::random(8),
        ]);

        $res = $this->getJson("/api/transport/consignments/{$c->id}")->assertOk();

        $this->assertSame($c->consignment_number, $res->json('data.consignment.consignment_number'));
        $this->assertCount(1, $res->json('data.trips'));
        $this->assertIsArray($res->json('data.audit'));
    }

    public function test_ctd_003_an_orders_consignments_are_listed(): void
    {
        Sanctum::actingAs($this->user());
        $order = $this->order();

        foreach (range(1, 2) as $i) {
            TransportConsignment::create([
                'tenant_id' => self::TENANT_A,
                'consignment_number' => TransportConsignment::nextLocalNumber(self::TENANT_A),
                'order_id' => $order->id, 'customer_id' => 7,
            ]);
        }
        $this->make();   // a different order

        $res = $this->getJson("/api/transport/orders/{$order->id}/consignments")->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_a_status_filter_is_refused_rather_than_silently_ignored(): void
    {
        // D-44 — there is no status. Accepting the parameter and ignoring it
        // would let the caller believe the list had been filtered.
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/consignments?status=in_transit')
            ->assertStatus(422);
    }

    /* ══════════ update and delete ══════════ */

    public function test_updating_changes_only_writable_fields(): void
    {
        Sanctum::actingAs($this->user());
        $c = $this->make();
        $originalOrder = $c->order_id;

        $this->putJson("/api/transport/consignments/{$c->id}", [
            'customer_reference' => 'PO-NEW',
        ])->assertOk();

        $c->refresh();
        $this->assertSame('PO-NEW', $c->customer_reference);
        $this->assertSame($originalOrder, $c->order_id);
    }

    public function test_a_consignment_cannot_be_moved_to_another_order(): void
    {
        // order_id is absent from UpdateConsignmentRequest and from the
        // service's writable list — re-parenting would strand customer_id.
        Sanctum::actingAs($this->user());
        $c = $this->make();
        $other = $this->order();

        $this->putJson("/api/transport/consignments/{$c->id}", [
            'order_id' => $other->id, 'customer_reference' => 'X',
        ])->assertOk();

        $this->assertNotSame($other->id, $c->fresh()->order_id);
    }

    public function test_deleting_removes_an_uncarried_consignment(): void
    {
        Sanctum::actingAs($this->user());
        $c = $this->make();

        $this->deleteJson("/api/transport/consignments/{$c->id}")->assertOk();

        $this->assertSoftDeleted('transport_consignments', ['id' => $c->id]);
    }

    public function test_deleting_a_carried_consignment_is_refused(): void
    {
        Sanctum::actingAs($this->user());
        $c = $this->make();

        TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $c->order_id, 'consignment_id' => $c->id,
            'customer_id' => 7, 'trip_number' => 'TRP-'.Str::random(8),
        ]);

        $this->deleteJson("/api/transport/consignments/{$c->id}")->assertStatus(422);
        $this->assertDatabaseHas('transport_consignments', ['id' => $c->id, 'deleted_at' => null]);
    }

    /* ══════════ the refusals that matter ══════════ */

    public function test_another_tenants_consignment_is_a_404_not_a_403(): void
    {
        $c = $this->make(self::TENANT_A);

        Sanctum::actingAs($this->user(self::TENANT_B));

        $this->getJson("/api/transport/consignments/{$c->id}")->assertNotFound();
        $this->putJson("/api/transport/consignments/{$c->id}", ['customer_reference' => 'X'])->assertNotFound();
        $this->deleteJson("/api/transport/consignments/{$c->id}")->assertNotFound();
    }

    public function test_a_client_cannot_reach_any_consignment_endpoint(): void
    {
        // The coarse role:admin,staff door — the only thing standing between
        // D-46's unenforced scopes and a cross-customer read.
        $this->make(self::TENANT_A);
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/api/transport/consignments')->assertForbidden();
        $this->postJson('/api/transport/consignments', [])->assertForbidden();
    }

    public function test_accounts_may_read_but_not_write(): void
    {
        // Consignment mirrors Order: accounts holds view, not create/update.
        $c = $this->make(self::TENANT_A);
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $this->getJson('/api/transport/consignments')->assertOk();
        $this->postJson('/api/transport/consignments', ['order_id' => $c->order_id])->assertForbidden();
        $this->putJson("/api/transport/consignments/{$c->id}", ['customer_reference' => 'X'])->assertForbidden();
    }

    public function test_delete_is_narrower_than_update(): void
    {
        // Mirrors VEHICLE_DELETE — a dispatcher may edit a shipment record but
        // not remove one.
        $c = $this->make(self::TENANT_A);
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $this->putJson("/api/transport/consignments/{$c->id}", ['customer_reference' => 'X'])->assertOk();
        $this->deleteJson("/api/transport/consignments/{$c->id}")->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/transport/consignments')->assertUnauthorized();
    }
}
