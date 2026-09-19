<?php

namespace Tests\Feature\Transport;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\ConsignmentService;
use App\Services\Transport\ContainerPassportService;
use App\Services\Transport\ContainerService;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Container 360 — STOS-CTD's Digital Passport, MS-001 §14 steps 1–2.
 *
 * The passport's whole job is to JOIN records that live apart, so these tests
 * are about the joins holding and about what happens when a link is missing.
 * A passport that renders beautifully for a fully-populated container and
 * throws for a free one would fail on the first click of the demonstration.
 */
class ContainerPassportTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private ContainerPassportService $passports;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }

        $this->passports = app(ContainerPassportService::class);
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A full chain: customer → order → consignment → container, carried by a trip. */
    private function fullChain(int $tenantId = self::TENANT_A): array
    {
        $actor    = $this->user($tenantId);
        $customer = Client::create(['tenant_id' => $tenantId, 'company' => 'Acme Pharma', 'status' => 'active']);

        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => $customer->id,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $consignment = app(ConsignmentService::class)->create([
            'order_id' => $order->id, 'customer_reference' => 'PO-'.Str::random(5),
            'cargo_description' => 'Palletised pharma', 'package_count' => 12,
        ], $tenantId, $actor);

        $trip = app(TransportTripService::class)->createFromOrder($order->id, [
            'route' => 'JNPT → Bhiwandi', 'consignment_id' => $consignment->id, 'approved_freight' => 50000,
        ], $tenantId, $actor);

        $containers = app(ContainerService::class);
        $container  = $containers->create(['container_number' => 'sgoe-'.self::uniqueSeq(6).'-1'], $tenantId, $actor);
        $containers->attach($container, $consignment->fresh(), $tenantId, $actor);

        // The timeline reads `trip_events` now, and this fixture builds its
        // chain through services that do not all emit yet — so the events are
        // arranged here rather than assumed. Three sources on purpose: the
        // container's own, one of P2's and one of P3's, which is what makes the
        // "combines events from all connected systems" assertion mean anything.
        $rec = app(\App\Services\Transport\TripEventRecorder::class);
        $rec->record('container.created', tenantId: $tenantId, containerId: $container->id,
            actor: $actor, occurredAt: now()->subHours(4));
        $rec->record('trip.dispatched', trip: $trip->fresh(), actor: $actor, occurredAt: now()->subHours(3));
        $rec->record('genset.on', trip: $trip->fresh(), occurredAt: now()->subHours(2));
        $rec->record('pod.uploaded', trip: $trip->fresh(), occurredAt: now()->subHour());

        return compact('actor', 'customer', 'order', 'consignment', 'trip', 'container');
    }

    /* ══════════ the chain — CTD §71, CTD-002/003/006/007 ══════════ */

    public function test_the_passport_joins_container_to_customer_order_consignment_and_trip(): void
    {
        ['container' => $c, 'customer' => $cust, 'order' => $o, 'consignment' => $cn, 'trip' => $t] = $this->fullChain();

        $p = $this->passports->forContainer($c->id, self::TENANT_A);

        $this->assertSame('Acme Pharma', $p['chain']['customer']['name'], 'CTD-002');
        $this->assertSame($o->order_number, $p['chain']['order']['number'], 'CTD-003');
        $this->assertSame($cn->consignment_number, $p['chain']['consignment']['number']);
        $this->assertSame($t->trip_number, $p['chain']['trip']['number']);
    }

    public function test_the_passport_names_the_vehicle_and_driver_when_the_trip_has_them(): void
    {
        // CTD-006 / CTD-007. Read from P1's own tables — there is no read
        // contract to Fleet (D-100).
        $chain = $this->fullChain();

        $vehicle = \App\Models\Transport\TransportVehicle::create([
            'tenant_id' => self::TENANT_A, 'registration_number' => 'MH12AB'.self::uniqueSeq(4), 'capacity_tonnes' => 25,
        ]);
        $driver = \App\Models\Transport\TransportDriver::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ramesh Kumar', 'licence_number' => 'RJ14'.self::uniqueSeq(6),
        ]);
        $chain['trip']->forceFill(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id])->save();

        $p = $this->passports->forContainer($chain['container']->id, self::TENANT_A);

        $this->assertSame($vehicle->registration_number, $p['chain']['vehicle']['registration']);
        $this->assertSame('Ramesh Kumar', $p['chain']['driver']['name']);
    }

    /* ══════════ status explained — CTD §11/§12 ══════════ */

    public function test_the_status_is_explained_not_merely_shown(): void
    {
        // CTD §12 — "The UI must not merely show BILLING_BLOCKED. It should
        // explain." Every status carries a sentence and a next action.
        $chain = $this->fullChain();

        $p = $this->passports->forContainer($chain['container']->id, self::TENANT_A);

        $this->assertNotSame($p['status']['code'], $p['status']['label'], 'the label must be prose, not the enum');
        $this->assertNotEmpty($p['status']['explanation']);
        $this->assertNotEmpty($p['status']['next_action']);
        $this->assertStringContainsString($chain['trip']->trip_number, $p['status']['explanation']);
    }

    public function test_a_free_container_says_so_rather_than_failing(): void
    {
        // The first click of any demo is as likely to land on a free container
        // as a busy one. A passport that only works for a full chain is not a
        // passport.
        $container = app(ContainerService::class)->create(
            ['container_number' => 'SGOE0000001'], self::TENANT_A, $this->user()
        );

        $p = $this->passports->forContainer($container->id, self::TENANT_A);

        $this->assertSame('not_on_a_consignment', $p['status']['code']);
        $this->assertNull($p['chain']['trip']);
        $this->assertNull($p['chain']['customer']);
        $this->assertNull($p['readiness']);
        $this->assertNull($p['linked'], 'no linked records section for a container with no trip');
    }

    /* ══════════ lifecycle — CTD §76 ══════════ */

    public function test_previous_lifecycles_are_listed_separately_from_the_current_one(): void
    {
        // CTD §76 — "Historical Passport events must remain immutable and
        // separated by lifecycle instance."
        $chain      = $this->fullChain();
        $containers = app(ContainerService::class);

        $containers->detach($chain['container'], self::TENANT_A, $chain['actor']);

        $second = app(ConsignmentService::class)->create(
            ['order_id' => $chain['order']->id, 'customer_reference' => 'PO-SECOND'],
            self::TENANT_A, $chain['actor']
        );
        $containers->attach($chain['container']->fresh(), $second, self::TENANT_A, $chain['actor']);

        $p = $this->passports->forContainer($chain['container']->id, self::TENANT_A);

        $this->assertSame(2, $p['lifecycle']['times_used']);
        $this->assertNotNull($p['lifecycle']['current']);
        $this->assertCount(1, $p['lifecycle']['previous']);
        $this->assertSame(
            $second->id,
            $p['lifecycle']['current']->consignment_id,
            'the CURRENT attachment is the one shown, not the first',
        );
    }

    /* ══════════ timeline — CTD-021, §31–§33 ══════════ */

    public function test_the_timeline_merges_events_from_across_the_chain(): void
    {
        // WAS: asserted `source` was one of Container / Consignment / Trip —
        // which named the TABLE an audit row sat on, because that was all the
        // audit trail could tell us. The timeline reads `trip_events` now, so
        // `source` is CTD §33's answer to "who said this" (user, gps, sensor,
        // accounting…) and the chain question is answered by which records the
        // row is attached to.
        $chain = $this->fullChain();

        $p = $this->passports->forContainer($chain['container']->id, self::TENANT_A);

        $this->assertNotEmpty($p['timeline'], 'CTD-021 — one chronological view across the chain');

        foreach ($p['timeline'] as $row) {
            $this->assertContains($row['source'], \App\Support\Transport\TripEventType::SOURCES,
                "CTD §33 — '{$row['source']}' is not one of the ten declared sources");
            $this->assertContains($row['category'], \App\Support\Transport\TripEventType::CATEGORIES,
                "CTD §101 — '{$row['category']}' is not one of the nine branches of the stream");
        }
    }

    public function test_every_timeline_row_names_its_source_and_is_readable(): void
    {
        // CTD §33 — each event identifies its source. §12's principle applied to
        // the timeline: no dotted action names in front of a reader.
        $chain = $this->fullChain();

        $p = $this->passports->forContainer($chain['container']->id, self::TENANT_A);

        $this->assertNotEmpty($p['timeline']);
        foreach ($p['timeline'] as $row) {
            $this->assertNotEmpty($row['source']);
            $this->assertNotEmpty($row['label']);
            $this->assertStringNotContainsString('.', $row['label'], 'the label must be prose, not the event key');
            // An unregistered type still renders as English, and the row says
            // whether it has been declared rather than leaving a reader to guess.
            $this->assertArrayHasKey('registered', $row);
        }
    }

    public function test_the_timeline_is_newest_first(): void
    {
        $chain = $this->fullChain();

        $times = $this->passports->forContainer($chain['container']->id, self::TENANT_A)['timeline']
            ->pluck('at')->all();

        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    /* ══════════ tenancy — CTD-022 ══════════ */

    public function test_another_tenants_container_reads_as_no_such_container(): void
    {
        $foreign = $this->fullChain(self::TENANT_B);

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);

        $this->passports->forContainer($foreign['container']->id, self::TENANT_A);
    }

    /* ══════════ the endpoint ══════════ */

    public function test_the_passport_endpoint_returns_every_section(): void
    {
        $chain = $this->fullChain();
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/containers/'.$chain['container']->id.'/passport')
            ->assertOk()
            ->assertJsonStructure(['data' => ['container', 'lifecycle', 'chain', 'status', 'timeline']]);
    }

    public function test_a_client_identity_cannot_open_a_passport(): void
    {
        // CTD §70's customer-facing passport stays out until D-46's scope
        // narrowing exists. Shipping a container-keyed customer screen on an
        // unenforced scope is exactly the leak D-46 describes.
        $chain = $this->fullChain();
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/api/transport/containers/'.$chain['container']->id.'/passport')->assertForbidden();
    }

    public function test_an_unknown_container_is_a_404(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/containers/999999/passport')->assertNotFound();
    }
}
