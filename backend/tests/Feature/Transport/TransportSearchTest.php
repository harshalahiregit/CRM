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

    /**
     * Each key finds ITS OWN record when there is no container to go on to.
     *
     * This test predates the §4 follow-through and still passes, which is worth
     * explaining rather than leaving as a coincidence: `chain()` deliberately
     * does not attach a container, so every key here falls into the
     * "this consignment has no container" branch and stops at the record it
     * named. That is the correct behaviour for loose cargo (§8).
     *
     * So this pins the WITHOUT-a-container half, and
     * test_every_key_lands_on_the_same_container_passport pins the with-one
     * half. Neither alone says what the resolver does.
     */
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

    /* ══════════ CTD §4 — every path ends at the same passport ══════════ */

    /**
     * §4's closing sentence, which the build did not meet until 2026-09-19:
     * "All relevant search paths must ultimately lead to the same Digital
     * Passport."
     *
     * Four different keys, one destination. This is the whole requirement in
     * one assertion, and it is written against the PATH rather than against a
     * flag, because the path is what the palette and the landing page actually
     * navigate to — a `passport` key that is present while `path` still points
     * at a list would pass a weaker test and ship the old behaviour.
     */
    public function test_every_key_lands_on_the_same_container_passport(): void
    {
        $c = $this->chain();
        app(ContainerService::class)->attach($c['container'], $c['consignment'], self::TENANT_A, $c['actor']);

        $expected = '/app/transport/containers/'.$c['container']->id;

        foreach ([
            'container number'   => $c['container']->container_number,
            'transport order'    => $c['order']->order_number,
            'consignment number' => $c['consignment']->consignment_number,
            'customer reference' => $c['consignment']->customer_reference,
        ] as $key => $term) {
            $hit = $this->search->resolve($term, self::TENANT_A);

            $this->assertNotNull($hit, "$key did not resolve at all");
            $this->assertSame($expected, $hit['path'],
                "$key resolved but did not lead to the container passport");
        }
    }

    /**
     * The one deliberate departure from §4's letter — ruled 2026-09-19, D-117.
     *
     * A trip number keeps going to the trip page, because somebody typing it is
     * a dispatcher who wants the working screen. Pinned as a test so the next
     * person to read §4 does not "fix" it: an undocumented divergence is
     * indistinguishable from an oversight.
     */
    public function test_a_trip_number_deliberately_goes_to_the_trip_and_not_the_passport(): void
    {
        $c = $this->chain();
        app(ContainerService::class)->attach($c['container'], $c['consignment'], self::TENANT_A, $c['actor']);

        $hit = $this->search->resolve($c['trip']->trip_number, self::TENANT_A);

        $this->assertSame('trip', $hit['type']);
        $this->assertSame('/app/transport/trips/'.$c['trip']->id, $hit['path']);
        $this->assertArrayNotHasKey('passport', $hit);
    }

    /**
     * A vehicle reaches the passport of what it is carrying.
     *
     * This was assumed to be blocked on the Fleet repoint and is not:
     * `transport_trips.vehicle_id` and `TransportVehicle.id` are one id space,
     * so the walk resolves inside our own tables.
     */
    public function test_a_vehicle_registration_reaches_the_passport_of_what_it_carries(): void
    {
        $c = $this->chain();
        app(ContainerService::class)->attach($c['container'], $c['consignment'], self::TENANT_A, $c['actor']);

        $v = TransportVehicle::create([
            'tenant_id' => self::TENANT_A, 'registration_number' => 'MH 12 AB '.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer', 'status' => 'available',
        ]);
        $c['trip']->forceFill(['vehicle_id' => $v->id])->save();

        $hit = $this->search->resolve($v->registration_number, self::TENANT_A);

        $this->assertSame('vehicle', $hit['type']);
        $this->assertSame('/app/transport/containers/'.$c['container']->id, $hit['path']);
        $this->assertSame($c['container']->container_number, $hit['passport']['container_number']);
        $this->assertContains($c['trip']->trip_number, $hit['via'],
            'the chain it walked should be shown, not hidden');
    }

    /**
     * Several journeys — a truck that has run more than one trip.
     *
     * Going straight through here would pick one journey out of many, which is
     * guessing dressed as an answer. The list is returned instead, and every
     * row still offers a passport so it stays a set of routes to §4's
     * destination rather than a dead end with extra steps.
     */
    public function test_a_vehicle_with_several_trips_returns_the_list_rather_than_guessing(): void
    {
        $one = $this->chain();
        $two = $this->chain();
        app(ContainerService::class)->attach($one['container'], $one['consignment'], self::TENANT_A, $one['actor']);
        app(ContainerService::class)->attach($two['container'], $two['consignment'], self::TENANT_A, $two['actor']);

        $v = TransportVehicle::create([
            'tenant_id' => self::TENANT_A, 'registration_number' => 'MH 14 CD '.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer', 'status' => 'available',
        ]);
        $one['trip']->forceFill(['vehicle_id' => $v->id])->save();
        $two['trip']->forceFill(['vehicle_id' => $v->id])->save();

        $hit = $this->search->resolve($v->registration_number, self::TENANT_A);

        $this->assertArrayHasKey('options', $hit);
        $this->assertCount(2, $hit['options']['items']);
        $this->assertArrayNotHasKey('passport', $hit, 'it must not pick one of the two');

        foreach ($hit['options']['items'] as $row) {
            $this->assertStringStartsWith('/app/transport/containers/', $row['path'],
                'each journey should still offer its own passport');
        }
    }

    /** A truck that has never run. Says so, and does not pretend to trace. */
    public function test_a_vehicle_with_no_trips_says_so_instead_of_failing_silently(): void
    {
        $this->chain();

        $v = TransportVehicle::create([
            'tenant_id' => self::TENANT_A, 'registration_number' => 'MH 16 EF '.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer', 'status' => 'available',
        ]);

        $hit = $this->search->resolve($v->registration_number, self::TENANT_A);

        $this->assertArrayNotHasKey('passport', $hit);
        $this->assertStringContainsString('No trip has run on this vehicle', $hit['note']);
    }

    /**
     * Loose cargo — §8 allows a consignment with "other cargo references" and
     * no container. There is genuinely no passport, so the walk stops at the
     * consignment and names the hop that ran out.
     */
    public function test_a_consignment_with_no_container_explains_itself(): void
    {
        $c = $this->chain();    // deliberately not attached

        $hit = $this->search->resolve($c['consignment']->consignment_number, self::TENANT_A);

        $this->assertArrayNotHasKey('passport', $hit);
        $this->assertStringContainsString('no container on it', $hit['note']);
        $this->assertStringContainsString('/app/transport/consignments', $hit['path'],
            'the last real record stays reachable');
    }
}
