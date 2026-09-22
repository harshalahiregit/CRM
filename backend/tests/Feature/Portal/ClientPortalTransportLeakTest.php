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
    ];

    private ClientContact $contact;

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
        TransportTrip::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'customer_id' => $client->id,
            'trip_number' => 'TRP-LEAKCHECK-1',
        ])->forceFill([
            'approved_freight' => self::SENTINELS['freight'],
            'closure_reason'   => self::SENTINELS['closure'],
            'rejection_reason' => self::SENTINELS['rejection'],
            'route'            => 'Mundra → Pune',
            'driver_id'        => $driver->id,
        ])->save();

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
        $this->assertNotEmpty($this->transportRoutes(),
            'No portal transport routes found — this guard would pass by checking nothing.');
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
            // A route with a parameter needs a subject; none exists yet, and
            // when one does it gets its own case rather than a guessed id.
            ->reject(fn ($r) => str_contains($r->uri(), '{'))
            ->map(fn ($r) => $r->uri())
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
}
