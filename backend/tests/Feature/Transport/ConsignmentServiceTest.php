<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\ConsignmentService;
use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block 1 step 5 — ConsignmentService.
 *
 * Covers ORD-004, CTD-002 and CTD-003 at the behavioural level, and every
 * refusal path. The refusals matter more than the happy path: a service that
 * creates correctly but accepts a cross-tenant order is a data leak that every
 * happy-path test would pass.
 */
class ConsignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private ConsignmentService $service;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->service = app(ConsignmentService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'ops-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function order(int $tenantId = self::TENANT_A, ?string $status = null, int $customerId = 7): TransportOrder
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => $customerId,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        if ($status) {
            $order->forceFill(['order_status' => $status])->save();
        }

        return $order->fresh();
    }

    /* ══════════ create — ORD-004, CTD-002, CTD-003 ══════════ */

    public function test_creating_allocates_a_number_and_links_the_order(): void
    {
        $order = $this->order();

        $c = $this->service->create(['order_id' => $order->id], self::TENANT_A, $this->actor);

        $this->assertMatchesRegularExpression('/^CNM-\d{4}-\d{6}$/', $c->consignment_number);
        $this->assertSame($order->id, $c->order_id);
        $this->assertSame(self::TENANT_A, (int) $c->tenant_id);
        $this->assertSame($this->actor->id, $c->created_by);
    }

    public function test_ctd_002_the_customer_is_taken_from_the_order_not_the_caller(): void
    {
        // Two sources for one fact is two chances to disagree. A caller that
        // supplies a different customer_id must not be able to detach the
        // consignment from the order's customer.
        $order = $this->order(customerId: 7);

        $c = $this->service->create(
            ['order_id' => $order->id, 'customer_id' => 999],
            self::TENANT_A, $this->actor,
        );

        $this->assertSame(7, (int) $c->customer_id);
    }

    public function test_only_writable_fields_are_accepted(): void
    {
        $order = $this->order();

        $c = $this->service->create([
            'order_id'           => $order->id,
            'customer_reference' => 'PO-8891',
            'cargo_description'  => '48 drums, palletised',
            'package_count'      => 48,
            'gross_weight_kg'    => '12450.500',
            // None of these may be set by a caller.
            'consignment_number' => 'CNM-1999-000001',
            'tenant_id'          => self::TENANT_B,
            'created_by'         => 424242,
        ], self::TENANT_A, $this->actor);

        $this->assertSame('PO-8891', $c->customer_reference);
        $this->assertSame(48, $c->package_count);
        $this->assertNotSame('CNM-1999-000001', $c->consignment_number);
        $this->assertSame(self::TENANT_A, (int) $c->tenant_id);
        $this->assertSame($this->actor->id, $c->created_by);
    }

    public function test_creation_is_audited_with_the_order_it_belongs_to(): void
    {
        $order = $this->order();
        $c = $this->service->create(['order_id' => $order->id], self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(TransportConsignment::class, $c->id)
            ->where('action', 'transport.consignment.created')
            ->sole();

        $this->assertSame($this->actor->id, $entry->actor_id);
        $this->assertSame($order->order_number, $entry->new_values['order_number']);
        $this->assertSame($c->consignment_number, $entry->new_values['consignment_number']);
    }

    /* ══════════ the refusals ══════════ */

    public function test_an_unknown_order_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('That order does not exist in this workspace.');

        $this->service->create(['order_id' => 999999], self::TENANT_A, $this->actor);
    }

    public function test_another_tenants_order_reads_as_no_such_order(): void
    {
        // Not "not yours" — that would confirm the order exists elsewhere.
        $foreign = $this->order(self::TENANT_B);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('That order does not exist in this workspace.');

        $this->service->create(['order_id' => $foreign->id], self::TENANT_A, $this->actor);
    }

    public function test_a_refused_create_writes_nothing(): void
    {
        try {
            $this->service->create(['order_id' => $this->order(self::TENANT_B)->id], self::TENANT_A, $this->actor);
        } catch (BusinessException) {
            // expected
        }

        $this->assertSame(0, TransportConsignment::count());
        $this->assertSame(0, TransportAuditLog::where('action', 'transport.consignment.created')->count());
    }

    public function test_finding_another_tenants_consignment_is_a_404_not_a_403(): void
    {
        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->find($c->id, self::TENANT_B);
    }

    public function test_updating_across_tenants_is_a_404_not_a_403(): void
    {
        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->update($c, ['customer_reference' => 'X'], self::TENANT_B, $this->actor);
    }

    public function test_listing_consignments_for_an_unknown_order_is_refused(): void
    {
        // Not an empty list — a confident empty answer about an order that does
        // not exist reads as "that order has no consignments".
        $this->expectException(BusinessException::class);

        $this->service->forOrder(999999, self::TENANT_A);
    }

    public function test_an_update_with_no_writable_field_is_refused(): void
    {
        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('No consignment field was supplied to update.');

        $this->service->update($c, ['tenant_id' => self::TENANT_B], self::TENANT_A, $this->actor);
    }

    /* ══════════ update ══════════ */

    public function test_updating_records_before_and_after(): void
    {
        $c = $this->service->create(
            ['order_id' => $this->order()->id, 'customer_reference' => 'PO-1'],
            self::TENANT_A, $this->actor,
        );

        $this->service->update($c, ['customer_reference' => 'PO-2'], self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('action', 'transport.consignment.updated')->sole();

        $this->assertSame('PO-1', $entry->old_values['customer_reference']);
        $this->assertSame('PO-2', $entry->new_values['customer_reference']);
        $this->assertSame(['customer_reference'], $entry->context['fields']);
    }

    public function test_a_no_op_update_writes_no_audit_row(): void
    {
        // A trail full of "changed nothing" makes a real change harder to find.
        $c = $this->service->create(
            ['order_id' => $this->order()->id, 'customer_reference' => 'PO-1'],
            self::TENANT_A, $this->actor,
        );

        $this->service->update($c, ['customer_reference' => 'PO-1'], self::TENANT_A, $this->actor);

        $this->assertSame(0, TransportAuditLog::where('action', 'transport.consignment.updated')->count());
    }

    /* ══════════ delete ══════════ */

    public function test_deleting_an_uncarried_consignment_soft_deletes_it(): void
    {
        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);

        $this->service->delete($c, self::TENANT_A, $this->actor);

        $this->assertSoftDeleted('transport_consignments', ['id' => $c->id]);
        $this->assertSame(1, TransportAuditLog::where('action', 'transport.consignment.deleted')->count());
    }

    public function test_a_consignment_a_trip_is_carrying_cannot_be_deleted(): void
    {
        // The trip's history would otherwise point at nothing — the referential
        // failure D-14 was raised for.
        $order = $this->order();
        $c = $this->service->create(['order_id' => $order->id], self::TENANT_A, $this->actor);

        TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $order->id,
            'consignment_id' => $c->id, 'customer_id' => 7,
            'trip_number' => 'TRP-'.Str::random(8),
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already carried by 1 trip');

        $this->service->delete($c->fresh(), self::TENANT_A, $this->actor);
    }

    public function test_a_deleted_number_is_not_reissued(): void
    {
        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);
        $number = $c->consignment_number;

        $this->service->delete($c, self::TENANT_A, $this->actor);

        $next = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);

        $this->assertNotSame($number, $next->consignment_number);
    }

    /* ══════════ what the service deliberately does NOT enforce ══════════ */

    public function test_an_order_in_any_state_may_be_described(): void
    {
        // Hard Rule 1. No document says an order must reach a state before its
        // cargo can be described, and in the ordinary case the consignment is
        // captured WITH the order at draft. Inventing that gate would be a
        // business rule with no source.
        foreach ([OrderStatus::INITIAL, OrderStatus::APPROVED] as $status) {
            $order = $this->order(status: $status);

            $c = $this->service->create(['order_id' => $order->id], self::TENANT_A, $this->actor);

            $this->assertSame($order->id, $c->order_id, "an order at {$status} must accept a consignment");
        }
    }

    public function test_the_service_never_computes_a_status(): void
    {
        // D-44 — the lifecycle engine does not exist.
        $this->assertFalse(method_exists(ConsignmentService::class, 'status'));
        $this->assertFalse(method_exists(ConsignmentService::class, 'statusFor'));

        $c = $this->service->create(['order_id' => $this->order()->id], self::TENANT_A, $this->actor);
        $this->assertArrayNotHasKey('status', $c->toArray());
    }

    /* ══════════ tenancy on the list ══════════ */

    public function test_the_list_never_crosses_tenants(): void
    {
        $this->service->create(['order_id' => $this->order(self::TENANT_A)->id], self::TENANT_A, $this->actor);
        $this->service->create(['order_id' => $this->order(self::TENANT_B)->id], self::TENANT_B, $this->actor);

        $this->assertSame(1, $this->service->list(self::TENANT_A)->total());
        $this->assertSame(1, $this->service->list(self::TENANT_B)->total());
    }

    public function test_the_page_size_is_clamped(): void
    {
        // A client must not be able to request an unbounded page.
        $this->assertSame(200, $this->service->list(self::TENANT_A, ['per_page' => 99999])->perPage());
        $this->assertSame(1, $this->service->list(self::TENANT_A, ['per_page' => 0])->perPage());
    }
}
