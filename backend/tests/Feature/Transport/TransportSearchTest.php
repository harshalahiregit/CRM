<?php

namespace Tests\Feature\Transport;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Services\Transport\ConsignmentService;
use App\Services\Transport\ContainerService;
use App\Services\Transport\TransportSearchService;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One box, any identifier — TM-001 §8, CTD §4.
 *
 * The point of these tests is the RESTRAINT as much as the matching: this must
 * resolve exact identifiers and find NOTHING otherwise. A search that
 * sometimes returns a nearly-right record is worse than one that returns none,
 * because a person acts on it.
 */
class TransportSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }

        $this->search = app(TransportSearchService::class);
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function chain(int $tenantId = self::TENANT_A): array
    {
        $actor    = $this->user($tenantId);
        $customer = Client::create(['tenant_id' => $tenantId, 'company' => 'Acme', 'status' => 'active']);

        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::upper(Str::random(8)), 'customer_id' => $customer->id,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $consignment = app(ConsignmentService::class)->create([
            'order_id' => $order->id, 'customer_reference' => 'PO-'.Str::upper(Str::random(6)),
        ], $tenantId, $actor);

        $trip = app(TransportTripService::class)->createFromOrder($order->id, [
            'consignment_id' => $consignment->id, 'approved_freight' => 40000,
        ], $tenantId, $actor);

        $container = app(ContainerService::class)->create(
            ['container_number' => 'SGOE'.self::uniqueSeq(7)], $tenantId, $actor
        );

        return compact('actor', 'order', 'consignment', 'trip', 'container');
    }

    /* ══════════ CTD-001 — the anchor, however it is typed ══════════ */

    public function test_a_container_is_found_however_the_number_is_typed(): void
    {
        $c = app(ContainerService::class)->create(
            ['container_number' => 'ABCD1234567'], self::TENANT_A, $this->user()
        );

        foreach (['ABCD1234567', 'abcd-123456-7', 'ABCD 1234 567', ' abcd1234567 '] as $typed) {
            $hit = $this->search->resolve($typed, self::TENANT_A);

            $this->assertNotNull($hit, "'{$typed}' found nothing");
            $this->assertSame('container', $hit['type']);
            $this->assertSame($c->id, $hit['id']);
            $this->assertSame('/app/transport/containers/'.$c->id, $hit['path']);
        }
    }

    public function test_a_container_hit_says_what_it_matched_on(): void
    {
        // The containers list already shows "matched as" — a normalised hit
        // should be explicable, not magic.
        app(ContainerService::class)->create(['container_number' => 'abcd-123456-7'], self::TENANT_A, $this->user());

        $hit = $this->search->resolve('ABCD 1234 567', self::TENANT_A);

        $this->assertSame('abcd-123456-7', $hit['label'], 'the label is what was ENTERED');
        $this->assertSame('ABCD1234567', $hit['matched_on'], 'and matched_on is what it MATCHED');
    }

    /* ══════════ the other identifiers — TM-001 §8 ══════════ */

    public function test_every_supported_identifier_resolves_to_its_own_record(): void
    {
        $c = $this->chain();

        $cases = [
            [$c['trip']->trip_number,               'trip',        '/app/transport/trips/'.$c['trip']->id],
            [$c['order']->order_number,             'order',       '/app/transport/orders/'.$c['order']->id],
            [$c['consignment']->consignment_number, 'consignment', '/app/transport/consignments?open='.$c['consignment']->id],
            [$c['consignment']->customer_reference, 'consignment', '/app/transport/consignments?open='.$c['consignment']->id],
            [$c['container']->container_number,     'container',   '/app/transport/containers/'.$c['container']->id],
        ];

        foreach ($cases as [$term, $type, $path]) {
            $hit = $this->search->resolve($term, self::TENANT_A);

            $this->assertNotNull($hit, "'{$term}' found nothing");
            $this->assertSame($type, $hit['type'], "'{$term}' resolved to the wrong kind");
            $this->assertSame($path, $hit['path']);
        }
    }

    public function test_a_vehicle_registration_resolves_however_it_is_spaced(): void
    {
        $v = TransportVehicle::create([
            'tenant_id' => self::TENANT_A, 'registration_number' => 'MH 12 AB 4455', 'capacity_tonnes' => 25,
        ]);

        foreach (['MH 12 AB 4455', 'mh12ab4455', 'MH-12-AB-4455'] as $typed) {
            $hit = $this->search->resolve($typed, self::TENANT_A);
            $this->assertNotNull($hit, "'{$typed}' found nothing");
            $this->assertSame('vehicle', $hit['type']);
            $this->assertSame($v->id, $hit['id']);
        }
    }

    public function test_a_driver_name_resolves(): void
    {
        $d = TransportDriver::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ramesh Kumar', 'licence_number' => 'RJ14'.self::uniqueSeq(6),
        ]);

        $hit = $this->search->resolve('Ramesh Kumar', self::TENANT_A);

        $this->assertSame('driver', $hit['type']);
        $this->assertSame($d->id, $hit['id']);
    }

    /* ══════════ the restraint ══════════ */

    public function test_nothing_fuzzy_matches(): void
    {
        // Exact identifiers only. A partial match that "helpfully" returns the
        // nearest record is the failure mode this design refuses.
        $c = $this->chain();

        foreach ([
            substr($c['trip']->trip_number, 0, 6),
            'TRP',
            'Ramesh',
            substr($c['order']->order_number, 3),
            'SGOE',
        ] as $partial) {
            $this->assertNull(
                $this->search->resolve($partial, self::TENANT_A),
                "'{$partial}' matched something — this search must be exact",
            );
        }
    }

    public function test_an_empty_or_unknown_term_finds_nothing(): void
    {
        $this->chain();

        foreach (['', '   ', 'NOT-A-REAL-IDENTIFIER'] as $term) {
            $this->assertNull($this->search->resolve($term, self::TENANT_A));
        }
    }

    /* ══════════ tenancy — CTD-022 ══════════ */

    public function test_another_tenants_identifiers_find_nothing_at_all(): void
    {
        // Not "you may not see this", which would confirm it exists.
        $foreign = $this->chain(self::TENANT_B);

        foreach ([
            $foreign['container']->container_number,
            $foreign['trip']->trip_number,
            $foreign['order']->order_number,
            $foreign['consignment']->consignment_number,
        ] as $term) {
            $this->assertNull(
                $this->search->resolve($term, self::TENANT_A),
                "'{$term}' leaked across tenants",
            );
        }
    }

    /* ══════════ the endpoint ══════════ */

    public function test_the_endpoint_returns_a_hit(): void
    {
        $c = $this->chain();
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/search?q='.urlencode($c['trip']->trip_number))
            ->assertOk()
            ->assertJsonPath('data.result.type', 'trip')
            ->assertJsonPath('data.result.id', $c['trip']->id);
    }

    public function test_a_miss_is_a_200_with_null_not_a_404(): void
    {
        // "Nothing matches that" is a legitimate answer to a search. A 404 would
        // make the client treat it as a broken request.
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/search?q=NOTHING-HERE')
            ->assertOk()
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('message', 'Nothing matches that identifier');
    }

    public function test_the_endpoint_requires_a_term(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/transport/search')->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_a_client_identity_cannot_search(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/api/transport/search?q=anything')->assertForbidden();
    }
}
