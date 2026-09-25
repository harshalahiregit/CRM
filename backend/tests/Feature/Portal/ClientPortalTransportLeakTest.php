<?php

namespace Tests\Feature\Portal;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Support\Transport\ClientVisibleFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nothing internal reaches a customer — STOS-CLP §3 and §23.
 *
 * ── WHY THIS IS NOT A LIST OF ENDPOINTS ──────────────────────────────────
 * It asks the ROUTER which portal transport endpoints exist and calls all of
 * them. A guard that names today's endpoint has to be remembered tomorrow, and
 * the one thing certain about this feature is that more endpoints are coming —
 * the journey view, documents, the timeline. Each of those is covered the
 * moment it is registered, by somebody who never read this file.
 *
 * ── WHY IT READS ClientVisibleFields ─────────────────────────────────────
 * The deny list lives beside the allowed columns, in one file. Written here as
 * well it would drift, and the day it drifts is the day the guard agrees with
 * the bug.
 *
 * ── THE CASE IT EXISTS FOR ───────────────────────────────────────────────
 * Not the obvious one. A denied column added to a select is visible in a diff:
 * the forbidden word is right there. The dangerous one is a JOIN that pulls a
 * field in under an alias —
 *
 *     ->join('transport_drivers as d', ...)->get([..., 'd.licence_number as licence'])
 *
 * — because the reviewer's eye is looking for `licence_number` on the response
 * side and the response says `licence`. This asserts on the KEYS the customer
 * receives, which is the only place both cases look the same.
 */
class ClientPortalTransportLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Values that exist nowhere else in the database.
     *
     * The key check below cannot catch a field renamed on the way out —
     * `d.licence_number as driver_contact` puts a real licence on a customer's
     * screen under a key no deny list would ever contain. That was proved, not
     * assumed: the first version of this guard passed while the response said
     * `"driver_contact":"MH12 2020 0033445"`.
     *
     * A value travels even when the name does not. So the internal fields are
     * seeded with strings nothing else could produce, and the guard asserts
     * they never appear in a response at any depth, under any key.
     */
    private const SENTINELS = [
        'licence'   => 'SENTINEL-LICENCE-8F2A',
        'closure'   => 'SENTINEL-CLOSURE-REASON-8F2A',
        'rejection' => 'SENTINEL-REJECTION-8F2A',
        'freight'   => 987654.31,

        // The journey view reads `trip_events`, and a key-name guard would not
        // have stopped `->addSelect('e.detail as note')`. The detail is the
        // dispatcher's own words and the actor is our own staff member, so both
        // get a value nothing else could produce.
        'detail'    => 'SENTINEL-EVENT-DETAIL-8F2A',
        'actor'     => 'SENTINEL-ACTOR-NAME-8F2A',

        // Fleet's tables — D-142. The deny list gained `driver_profiles`,
        // `vehicles` and `stos_drivers` because that is where vehicle and
        // driver data lives after the repoint. A deny-list entry for a table
        // this fixture never populates is a tick with nothing behind it, so
        // these rows are seeded and the values are what the guard hunts for.
        'fleetlicence' => 'SENTINEL-FLEET-LICENCE-8F2A',
        'chassis'      => 'SENTINEL-CHASSIS-8F2A',
        'gps'          => 'SENTINEL-GPS-DEVICE-8F2A',
    ];

    private ClientContact $contact;

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Leakcheck', 'slug' => 'leakcheck', 'subdomain' => 'leakcheck',
            'plan' => 'professional', 'status' => 'active',
        ]);

        $client = Client::create(['tenant_id' => $tenant->id, 'company' => 'Widget Ltd', 'active' => true]);

        $order = TransportOrder::create([
            'tenant_id' => $tenant->id, 'order_number' => 'TO-'.Str::upper(Str::random(8)),
            'customer_id' => $client->id,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDay(), 'service_type' => 'Container Haulage',
        ]);

        // A driver whose licence is a SENTINEL — a value that exists nowhere
        // else, so finding it in a response proves it came from here however it
        // was renamed on the way out.
        $driver = TransportDriver::create([
            'tenant_id' => $tenant->id, 'name' => 'Leakcheck Driver',
            'licence_number' => self::SENTINELS['licence'],
            'licence_class' => 'HMV', 'licence_valid_until' => now()->addYear()->toDateString(),
        ]);

        // A trip carrying every kind of thing a customer must not see: a price,
        // a closure reason, and a crew.
        $trip = TransportTrip::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'customer_id' => $client->id,
            'trip_number' => 'TRP-LEAKCHECK-1',
        ]);
        $this->tripId = $trip->id;

        // ── Fleet's rows, which is where this data lives now — D-142 ─────
        // The trip points at THESE, not at the legacy pair above, exactly as a
        // repointed trip does. The legacy driver stays seeded so the old
        // sentinels still have a row to live in.
        $fleetVehicleId = DB::table('vehicles')->insertGetId([
            'company_id' => $tenant->id, 'registration_number' => 'MH01LEAK01',
            'registration_normalized' => 'MH01LEAK01',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned', 'status' => 'AVAILABLE',
            'chassis_number' => self::SENTINELS['chassis'],
            'gps_device_id' => self::SENTINELS['gps'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $stosDriverId = DB::table('stos_drivers')->insertGetId([
            'company_id' => $tenant->id, 'name' => 'Leakcheck Driver',
            'phone' => '9820000000', 'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $fleetDriverId = DB::table('driver_profiles')->insertGetId([
            'company_id' => $tenant->id, 'source' => 'stos', 'source_id' => $stosDriverId,
            'licence_number' => self::SENTINELS['fleetlicence'],
            'licence_normalized' => self::SENTINELS['fleetlicence'],
            'licence_class' => 'HMV', 'licence_expiry' => now()->addYear()->toDateString(),
            'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip->forceFill([
            'approved_freight' => self::SENTINELS['freight'],
            'closure_reason'   => self::SENTINELS['closure'],
            'rejection_reason' => self::SENTINELS['rejection'],
            'route'            => 'Mundra → Pune',
            // Fleet ids, because that is what a repointed trip carries.
            'vehicle_id'       => $fleetVehicleId,
            'driver_id'        => $fleetDriverId,
        ])->save();

        // Moments on the timeline: two a customer may see, and two they may not.
        // The internal pair is the point — without them the allow-list is never
        // exercised and the vocabulary guard would pass by having nothing to
        // reject.
        foreach ([
            ['vehicle.allocated', '-3 hours'],
            ['trip.delivered',    '-1 hour'],
            ['trip.submitted',    '-4 hours'],   // internal workflow
            ['crew.released',     '-30 minutes'], // fleet housekeeping
        ] as [$type, $when]) {
            DB::table('trip_events')->insert([
                'tenant_id'   => $tenant->id,
                'trip_id'     => $this->tripId,
                'event_type'  => $type,
                'category'    => 'operational',
                'source'      => 'user',
                'summary'     => $type,
                // Seeded on EVERY moment, the two a customer may see included:
                // a break that exposes only the visible rows' detail is still a
                // break, and seeding the internal pair alone would miss it.
                'detail'      => self::SENTINELS['detail'],
                'actor_name'  => self::SENTINELS['actor'],
                'actor_role'  => 'dispatcher',
                'occurred_at' => now()->modify($when),
                'recorded_at' => now(),
                'created_at'  => now(),
            ]);
        }

        $this->contact = ClientContact::create([
            'tenant_id' => $tenant->id, 'client_id' => $client->id,
            'first_name' => 'Anil', 'email' => 'anil@widget.test', 'active' => true,
            'portal_status' => 'active', 'password' => Hash::make('secret123'),
            // Everything granted. A guard must run against the widest access a
            // customer can hold, not the narrowest.
            'permissions' => ClientContact::MODULES,
        ]);

        Sanctum::actingAs($this->contact, ['*']);
    }

    /** Every registered portal transport endpoint, discovered rather than listed. */
    public static function portalTransportRoutes(): array
    {
        // The route list is not loaded when a data provider runs, so this is
        // resolved inside the test instead and the provider only names the one
        // case. See test_no_portal_transport_endpoint_returns_an_internal_field.
        return [['all']];
    }

    public function test_the_deny_list_is_not_empty(): void
    {
        // A guard whose list is empty passes everything. This has happened on
        // this project before, in a different shape.
        $this->assertNotEmpty(ClientVisibleFields::deniedKeys());
        $this->assertContains('approved_freight', ClientVisibleFields::deniedKeys());
        $this->assertContains('licence_number', ClientVisibleFields::deniedKeys());
    }

    public function test_there_are_portal_transport_routes_to_check(): void
    {
        $routes = $this->transportRoutes();

        $this->assertNotEmpty($routes,
            'No portal transport routes found — this guard would pass by checking nothing.');

        // Every registered portal transport route must be reachable by this
        // guard. A route it cannot build a URL for is a route it silently skips.
        $registered = collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true)
                && str_starts_with($r->uri(), 'api/portal/client/transport'))
            ->count();

        $this->assertCount($registered, $routes,
            'Some portal transport routes could not be turned into a URL and were skipped. '
            .'Add their parameter to the substitution in transportRoutes().');
    }

    public function test_no_portal_transport_endpoint_returns_an_internal_field(): void
    {
        $denied = ClientVisibleFields::deniedKeys();
        $problems = [];
        $inspected = 0;
        $keysSeen = 0;

        foreach ($this->transportRoutes() as $uri) {
            $response = $this->getJson('/'.$uri);

            // A 403 is a correct answer for a section this contact lacks; it is
            // not a leak. Anything else must be inspected.
            if ($response->status() === 403) {
                continue;
            }

            $response->assertOk();
            $inspected++;

            // THE VALUE CHECK — the one that survives a rename.
            $body = json_encode($response->json());

            foreach (self::SENTINELS as $what => $value) {
                if (str_contains($body, (string) $value)) {
                    $problems[] = sprintf(
                        '%s leaked an internal %s. The VALUE reached the customer, so renaming the '
                        .'field did not help — check every join and alias in '
                        .'ClientPortalController, not just the response keys.',
                        $uri, $what
                    );
                }
            }

            foreach ($this->keysIn($response->json()) as $key) {
                $keysSeen++;

                if (in_array($key, $denied, true)) {
                    $problems[] = sprintf(
                        '%s returned "%s" — an internal field. Remove it from the select, or if it '
                        .'is genuinely client-visible, take it off ClientVisibleFields::DENIED and '
                        .'say why in the comment there.',
                        $uri, $key
                    );
                }
            }
        }

        $this->assertSame([], $problems, "\n  ".implode("\n  ", $problems)."\n");

        // The guard must have READ something. An endpoint answering 403 to every
        // call, or returning an empty list, would make every assertion above
        // vacuously true — which is how a guard passes for a year while
        // protecting nothing. Four of the five blind guards found on this
        // project failed in exactly this shape.
        $this->assertGreaterThan(0, $inspected,
            'Every portal transport endpoint refused this contact — the leak check read nothing.');
        $this->assertGreaterThan(0, $keysSeen,
            'Portal transport endpoints answered, but with no fields at all — the leak check read nothing.');
    }

    /** Every portal route whose URI names transport. */
    private function transportRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true)
                && str_starts_with($r->uri(), 'api/portal/client/transport'))
            ->map(fn ($r) => $r->uri())
            // A route with a parameter is given the FIXTURE'S OWN subject, so it
            // answers 200 and is really inspected.
            //
            // This used to reject parameterised routes with a note saying none
            // existed yet. One did, three days later — the journey view — and it
            // would have been silently unguarded: the guard would still have
            // passed, still have reported inspecting an endpoint, and never have
            // looked at the one with the joins. A guard that skips what it does
            // not recognise is a guard with a blind spot it announces to nobody.
            ->map(fn ($uri) => str_replace(['{id}', '{trip}'], (string) $this->tripId, $uri))
            ->reject(fn ($uri) => str_contains($uri, '{'))
            ->values()->all();
    }

    /**
     * Every key at every depth.
     *
     * Recursive on purpose. A flat select cannot nest today, and the portal's
     * convention is that it never will — but a guard that only reads the top
     * level would be trusting that convention instead of enforcing it, and the
     * first person to add a nested payload would not find out from this test.
     *
     * @return list<string>
     */
    private function keysIn(mixed $payload): array
    {
        $keys = [];

        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                if (is_string($key)) {
                    $keys[] = $key;
                }

                foreach ($this->keysIn($value) as $nested) {
                    $keys[] = $nested;
                }
            }
        }

        return $keys;
    }

    /**
     * A customer never sees one of our event names, or a moment we did not mean
     * to show them.
     *
     * The leak check above guards FIELDS and VALUES. It does not guard
     * VOCABULARY — found by breaking it: deleting the allow-list from the
     * journey query left the guard green while the response carried
     * `trip.submitted` and `crew.released`, which are our internal workflow in
     * our own tokens. Both failures CLP §27 rules out, neither a denied field.
     *
     * So this asserts the other direction: every moment a customer is shown must
     * be one of the phrases we chose. A raw type reaching the screen is a
     * missing allow-list; an unrecognised phrase is somebody adding a moment
     * without deciding what to call it.
     */
    public function test_a_customer_only_ever_sees_the_agreed_journey_words(): void
    {
        $allowed = array_values(ClientVisibleFields::CLIENT_EVENTS);
        $problems = [];
        $moments = 0;

        foreach ($this->transportRoutes() as $uri) {
            $response = $this->getJson('/'.$uri);

            if ($response->status() !== 200) {
                continue;
            }

            foreach ((array) ($response->json('journey') ?? []) as $moment) {
                $moments++;
                $what = $moment['what'] ?? null;

                if (! in_array($what, $allowed, true)) {
                    $problems[] = sprintf(
                        '%s showed a customer "%s". That is not one of the phrases in '
                        .'ClientVisibleFields::CLIENT_EVENTS — either the allow-list is missing '
                        .'from the query, or a moment was added without deciding what to call it '
                        .'in the customer\'s language.',
                        $uri, is_string($what) ? $what : gettype($what)
                    );
                }
            }
        }

        $this->assertSame([], $problems, "\n  ".implode("\n  ", $problems)."\n");
        $this->assertGreaterThan(0, $moments,
            'No journey moments were inspected — this check read nothing.');
    }
}
