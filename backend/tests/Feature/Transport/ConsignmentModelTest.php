<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block 1 steps 2 and 3 — the consignment table and its model.
 *
 * Covers STOS-REQ-ORD-004, CTD-002 and CTD-003 at the persistence level. The
 * service-level behaviour (numbering, audit, refusals) arrives with the service
 * and is tested there.
 */
class ConsignmentModelTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }
    }

    private function order(int $tenantId = self::TENANT_A): TransportOrder
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        return $order;
    }

    private function consignment(int $tenantId = self::TENANT_A, array $attributes = []): TransportConsignment
    {
        return TransportConsignment::create(array_merge([
            'tenant_id'          => $tenantId,
            'consignment_number' => TransportConsignment::nextLocalNumber($tenantId),
            'order_id'           => $this->order($tenantId)->id,
            'customer_id'        => 1,
        ], $attributes));
    }

    /* ══════════ tenancy — the single most important rule ══════════ */

    public function test_the_model_carries_belongs_to_tenant(): void
    {
        $this->assertContains(
            \App\Models\Traits\BelongsToTenant::class,
            class_uses_recursive(TransportConsignment::class),
        );
    }

    public function test_for_tenant_is_the_only_thing_separating_two_tenants(): void
    {
        // Scoping is opt-in in this codebase — there is no global scope and no
        // middleware safety net (ARCHITECTURE-PRIMER §2). This proves the scope
        // works AND that its absence leaks, so nobody later assumes otherwise.
        $this->consignment(self::TENANT_A);
        $this->consignment(self::TENANT_B);

        $this->assertSame(1, TransportConsignment::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TransportConsignment::forTenant(self::TENANT_B)->count());
        $this->assertSame(2, TransportConsignment::count(), 'unscoped reads see every tenant');
    }

    public function test_another_tenants_consignment_is_not_reachable_by_id(): void
    {
        $a = $this->consignment(self::TENANT_A);

        $this->assertNull(
            TransportConsignment::forTenant(self::TENANT_B)->find($a->id),
            'a tenant must not reach another tenant\'s row even with its id',
        );
    }

    /* ══════════ numbering ══════════ */

    public function test_the_number_follows_the_modules_shape(): void
    {
        $this->assertMatchesRegularExpression(
            '/^CNM-\d{4}-\d{6}$/',
            TransportConsignment::nextLocalNumber(self::TENANT_A),
        );
    }

    public function test_numbering_increments_within_a_tenant(): void
    {
        $first  = $this->consignment(self::TENANT_A);
        $second = $this->consignment(self::TENANT_A);

        $this->assertNotSame($first->consignment_number, $second->consignment_number);
        $this->assertSame(
            (int) substr($first->consignment_number, -6) + 1,
            (int) substr($second->consignment_number, -6),
        );
    }

    public function test_numbering_is_per_tenant_not_global(): void
    {
        $this->consignment(self::TENANT_A);
        $this->consignment(self::TENANT_A);

        // Tenant B starts at 1, not 3 — its sequence is its own.
        $this->assertStringEndsWith('000001', TransportConsignment::nextLocalNumber(self::TENANT_B));
    }

    public function test_a_soft_deleted_number_is_not_reissued(): void
    {
        // MAX(number) over withTrashed(), not COUNT(*) — deleting a row must
        // not make the next one reuse a number that is still referenced.
        $first = $this->consignment(self::TENANT_A);
        $first->delete();

        $this->assertStringEndsWith('000002', TransportConsignment::nextLocalNumber(self::TENANT_A));
    }

    public function test_the_number_is_unique_within_a_tenant(): void
    {
        $existing = $this->consignment(self::TENANT_A);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TransportConsignment::create([
            'tenant_id'          => self::TENANT_A,
            'consignment_number' => $existing->consignment_number,
            'order_id'           => $this->order()->id,
        ]);
    }

    public function test_two_tenants_may_hold_the_same_number(): void
    {
        $a = $this->consignment(self::TENANT_A);

        $b = TransportConsignment::create([
            'tenant_id'          => self::TENANT_B,
            'consignment_number' => $a->consignment_number,
            'order_id'           => $this->order(self::TENANT_B)->id,
        ]);

        $this->assertSame($a->consignment_number, $b->consignment_number);
    }

    /* ══════════ relations — ORD-004, CTD-002, CTD-003 ══════════ */

    public function test_ord_004_the_consignment_belongs_to_an_order(): void
    {
        $order = $this->order();
        $c = $this->consignment(self::TENANT_A, ['order_id' => $order->id]);

        $this->assertSame($order->id, $c->order->id);
    }

    public function test_ctd_002_the_customer_is_reachable_without_joining_the_order(): void
    {
        $c = $this->consignment(self::TENANT_A, ['customer_id' => 42]);

        $this->assertSame(42, $c->customer_id);
        $this->assertSame(42, (int) TransportConsignment::forTenant(self::TENANT_A)
            ->forCustomer(42)->sole()->customer_id);
    }

    public function test_ctd_003_an_order_scope_finds_its_consignments(): void
    {
        $order = $this->order();
        $this->consignment(self::TENANT_A, ['order_id' => $order->id]);
        $this->consignment(self::TENANT_A);   // a different order

        $this->assertSame(1, TransportConsignment::forTenant(self::TENANT_A)->forOrder($order->id)->count());
    }

    public function test_ctd_008_a_consignment_may_be_carried_by_more_than_one_trip(): void
    {
        // STOS-CTD §8 allows one consignment across more than one movement,
        // which is why trips() is hasMany rather than hasOne.
        $c = $this->consignment();

        foreach (range(1, 2) as $i) {
            TransportTrip::create([
                'tenant_id'      => self::TENANT_A,
                'order_id'       => $c->order_id,
                'consignment_id' => $c->id,
                'customer_id'    => 1,
                'trip_number'    => 'TRP-'.Str::random(8),
            ]);
        }

        $this->assertCount(2, $c->fresh()->trips);
        $this->assertSame($c->id, TransportTrip::forTenant(self::TENANT_A)->first()->consignment->id);
    }

    public function test_a_trip_without_a_consignment_still_works(): void
    {
        // Trips shipped before consignments existed; the column is nullable and
        // the container is a search anchor, not a mandatory parent.
        $trip = TransportTrip::create([
            'tenant_id'   => self::TENANT_A,
            'order_id'    => $this->order()->id,
            'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(8),
        ]);

        $this->assertNull($trip->consignment_id);
        $this->assertNull($trip->consignment);
    }

    /* ══════════ the deliberate absences ══════════ */

    public function test_there_is_no_status_column_and_no_accessor_pretending_otherwise(): void
    {
        // D-44. STOS-CTD §11 puts status in a lifecycle engine that does not
        // exist, and its values span five lifecycles across two owners. A
        // method that guessed would be worse than the absence.
        $this->assertFalse(Schema::hasColumn('transport_consignments', 'status'));
        $this->assertFalse(method_exists(TransportConsignment::class, 'status'));
        $this->assertFalse(method_exists(TransportConsignment::class, 'statusLabel'));
        $this->assertNotContains('status', (new TransportConsignment())->getFillable());
    }

    public function test_a_consignment_with_no_container_is_valid(): void
    {
        // STOS-CTD §8's "other cargo references" — break-bulk is not an error.
        $c = $this->consignment(self::TENANT_A, ['cargo_description' => '48 drums, palletised']);

        $this->assertTrue($c->hasCargoDetail());
    }

    public function test_a_consignment_describing_nothing_says_so(): void
    {
        $this->assertFalse($this->consignment()->hasCargoDetail());
    }

    public function test_cargo_measures_keep_three_decimal_places(): void
    {
        $c = $this->consignment(self::TENANT_A, [
            'gross_weight_kg' => '1234.567',
            'volume_cbm'      => '28.125',
        ]);

        $this->assertSame('1234.567', (string) $c->fresh()->gross_weight_kg);
        $this->assertSame('28.125', (string) $c->fresh()->volume_cbm);
    }
}
