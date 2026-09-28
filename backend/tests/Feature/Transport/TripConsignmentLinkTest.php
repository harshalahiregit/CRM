<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CTD-003 — a trip carries a consignment.
 *
 * The column existed from migration 000015 and NOTHING wrote it: `consignment_id`
 * was fillable on the model but no service set it, so the chain
 * order -> consignment -> trip could not be completed through the application at
 * all. Found while building the demo, whose whole point is that the chain reads
 * end to end. Recorded as D-55.
 */
class TripConsignmentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportTripService $trips;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }

        $this->trips = app(TransportTripService::class);
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function approvedOrder(int $tenantId = self::TENANT_A): TransportOrder
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 7,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        return $order->fresh();
    }

    private function consignmentOn(TransportOrder $order): TransportConsignment
    {
        return TransportConsignment::create([
            'tenant_id' => $order->tenant_id,
            'consignment_number' => TransportConsignment::nextLocalNumber((int) $order->tenant_id),
            'order_id' => $order->id, 'customer_id' => $order->customer_id,
        ]);
    }

    public function test_a_trip_can_carry_a_consignment_from_its_own_order(): void
    {
        $order       = $this->approvedOrder();
        $consignment = $this->consignmentOn($order);

        $trip = $this->trips->createFromOrder(
            $order->id, ['consignment_id' => $consignment->id], self::TENANT_A, $this->actor()
        );

        $this->assertSame($consignment->id, $trip->consignment_id);
    }

    public function test_the_consignment_is_optional(): void
    {
        // A trip may be raised before anyone has described the cargo.
        $order = $this->approvedOrder();

        $trip = $this->trips->createFromOrder($order->id, [], self::TENANT_A, $this->actor());

        $this->assertNull($trip->consignment_id);
    }

    public function test_a_consignment_from_a_different_order_is_refused(): void
    {
        // Visible to this caller, but wrong — so a 422 that says which, not a
        // 404 that pretends it does not exist.
        $order      = $this->approvedOrder();
        $otherOrder = $this->approvedOrder();
        $wrong      = $this->consignmentOn($otherOrder);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('is on a different order');

        $this->trips->createFromOrder(
            $order->id, ['consignment_id' => $wrong->id], self::TENANT_A, $this->actor()
        );
    }

    public function test_another_tenants_consignment_reads_as_not_found(): void
    {
        // 404, never "not yours" — that would confirm the row exists.
        $order   = $this->approvedOrder();
        $foreign = $this->consignmentOn($this->approvedOrder(self::TENANT_B));

        $this->expectException(ResourceNotFoundException::class);

        $this->trips->createFromOrder(
            $order->id, ['consignment_id' => $foreign->id], self::TENANT_A, $this->actor()
        );
    }

    public function test_a_refused_link_creates_no_trip_at_all(): void
    {
        $order = $this->approvedOrder();
        $wrong = $this->consignmentOn($this->approvedOrder());

        try {
            $this->trips->createFromOrder(
                $order->id, ['consignment_id' => $wrong->id], self::TENANT_A, $this->actor()
            );
        } catch (BusinessException) {
            // expected
        }

        $this->assertSame(0, \App\Models\Transport\TransportTrip::forTenant(self::TENANT_A)->count());
    }

    public function test_the_link_is_audited(): void
    {
        $order       = $this->approvedOrder();
        $consignment = $this->consignmentOn($order);

        $trip = $this->trips->createFromOrder(
            $order->id, ['consignment_id' => $consignment->id], self::TENANT_A, $this->actor()
        );

        $entry = \App\Models\Transport\TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(\App\Models\Transport\TransportTrip::class, $trip->id)
            ->where('action', 'transport.trip.created')->sole();

        $this->assertSame($consignment->id, $entry->new_values['consignment_id']);
    }
}
