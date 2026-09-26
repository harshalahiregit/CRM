<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\ClientPortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The one supported way for another module to ask "which customer is this?".
 *
 * Transport needs to scope its client-facing endpoints to the signed-in
 * customer (MS-001 v1.1 §4/§6). The obvious-looking route —
 * client_contacts.user_id → users.role='client' — is dead: that column is from
 * the July design, the portal was rebuilt on contact-side auth (migration
 * 2026_10_15_000002), and nothing has written user_id since.
 * EnsureClientPortalAccess refuses a User token outright, so there is no users
 * row to bridge from.
 *
 * ClientPortalService::currentContext() answers it instead, so the rules live in
 * one module rather than being copied into another and drifting.
 *
 * Every test here is about what must return NULL, because null is what stops a
 * caller in another module from reading a customer it should not.
 */
class PortalContextForOtherModulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    private function contactOn(array $clientAttrs = [], array $contactAttrs = []): ClientContact
    {
        $client = Client::create(array_merge([
            'tenant_id' => $this->t->id, 'company' => 'Northwind Ltd', 'active' => true,
        ], $clientAttrs));

        return ClientContact::create(array_merge([
            'tenant_id' => $this->t->id, 'client_id' => $client->id,
            'first_name' => 'Riya', 'last_name' => 'Shah', 'email' => 'riya@northwind.test',
            'password' => Hash::make('Secret@12345'), 'active' => true,
            'portal_status' => 'active', 'permissions' => ['invoice', 'transport'],
        ], $contactAttrs));
    }

    /** The service reads the request, so the subject goes on a real one. */
    private function contextFor(?object $subject): ?array
    {
        $request = Request::create('/api/portal/anything', 'GET');

        if ($subject) {
            $request->setUserResolver(fn () => $subject);
        }

        return app(ClientPortalService::class)->currentContext($request);
    }

    /* ── the happy answer ───────────────────────────────────────────────── */

    public function test_it_returns_the_contact_its_customer_and_its_tenant(): void
    {
        $contact = $this->contactOn();

        $ctx = $this->contextFor($contact);

        $this->assertNotNull($ctx);
        $this->assertSame($contact->id, $ctx['contact_id']);
        $this->assertSame($contact->client_id, $ctx['client_id']);
        $this->assertSame($this->t->id, $ctx['tenant_id']);
    }

    /**
     * There is no per-contact transport role — the portal's gate is one flag per
     * section, granted by whoever invited the contact. This reports that flag
     * and the full granted list, nothing resembling a staff capability.
     */
    public function test_it_reports_the_transport_flag_and_the_granted_list(): void
    {
        $ctx = $this->contextFor($this->contactOn());

        $this->assertTrue($ctx['can_transport']);
        $this->assertEqualsCanonicalizing(['invoice', 'transport'], $ctx['permissions']);
    }

    public function test_a_contact_without_the_transport_flag_still_gets_a_context(): void
    {
        $ctx = $this->contextFor($this->contactOn(contactAttrs: ['permissions' => ['invoice']]));

        $this->assertNotNull($ctx, 'They are a real customer — they just may not see shipments.');
        $this->assertFalse($ctx['can_transport']);
    }

    /**
     * A legacy row from before the portal existed has no permissions array.
     * Reading that as "everything" would hand out access nobody granted.
     */
    public function test_a_contact_with_no_permissions_array_grants_nothing(): void
    {
        $ctx = $this->contextFor($this->contactOn(contactAttrs: ['permissions' => null]));

        $this->assertSame([], $ctx['permissions']);
        $this->assertFalse($ctx['can_transport']);
    }

    /* ── everything that must be null ───────────────────────────────────── */

    public function test_no_token_is_not_a_portal_context(): void
    {
        $this->assertNull($this->contextFor(null));
    }

    /** The check that stops a staff token reading customer-scoped data. */
    public function test_a_staff_token_is_not_a_portal_context(): void
    {
        $staff = User::create([
            'tenant_id' => $this->t->id, 'name' => 'Staff', 'email' => 'staff@acme.test',
            'password' => Hash::make('Secret@12345'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->assertNull($this->contextFor($staff));
    }

    public function test_a_deactivated_contact_is_not_a_portal_context(): void
    {
        $this->assertNull($this->contextFor($this->contactOn(contactAttrs: ['active' => false])));
    }

    public function test_a_contact_never_granted_portal_access_is_not_a_portal_context(): void
    {
        $this->assertNull($this->contextFor($this->contactOn(contactAttrs: ['portal_status' => 'inactive'])));
        $this->assertNull($this->contextFor($this->contactOn(
            clientAttrs: ['company' => 'Second Ltd'],
            contactAttrs: ['email' => 'b@northwind.test', 'portal_status' => 'invited'],
        )), 'Invited means they may set a password, not that access is on.');
    }

    public function test_a_switched_off_customer_is_not_a_portal_context(): void
    {
        $this->assertNull($this->contextFor($this->contactOn(clientAttrs: ['active' => false])));
    }

    public function test_a_removed_customer_is_not_a_portal_context(): void
    {
        $contact = $this->contactOn();
        $contact->client->delete();

        $this->assertNull($this->contextFor($contact->fresh()));
    }

    /* ── and the same gates on the live HTTP surface ────────────────────── */

    /**
     * Switching a customer off with the Status switch must cut its contacts off
     * NOW, not whenever their token happens to expire.
     *
     * ClientPortalAuthService::login() checked clients.active and that was read
     * as the whole fix — but login happens once and a token lasts.
     * ClientController::toggleActive() revokes nothing, so every contact under a
     * switched-off customer carried on working normally. Whoever flipped the
     * switch believed access was cut.
     */
    public function test_switching_a_customer_off_closes_the_portal_immediately(): void
    {
        $contact = $this->contactOn();
        Sanctum::actingAs($contact, ['*']);

        $this->getJson('/api/portal/client/me')->assertOk();

        $contact->client->update(['active' => false]);

        $this->getJson('/api/portal/client/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account is no longer active. Please speak to your account manager.');
    }
}
