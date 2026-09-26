<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer signing itself up, and then actually getting in.
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────────
 * POST /api/auth/register/client created a single `users` row — role='client',
 * status='pending', no tenant_id, no client, no contact — and answered
 * "Awaiting admin approval." Nothing could ever approve it:
 *
 *   · No endpoint anywhere activates a pending client user. TPV, vendor and
 *     company each have one (TpvAccessService, VendorService,
 *     CompanyAccountService); client never did.
 *   · Even activated it was useless. EnsureClientPortalAccess requires the token
 *     subject to BE a ClientContact, so a User token is refused at the door.
 *   · Nothing linked it to a customer, so there was no data behind it anyway.
 *
 * So the form worked, the message was reassuring, and the person waited forever.
 *
 * The rest of the codebase had already settled where a customer identity lives:
 * forgot-password for role=client goes to ClientPortalAuthService
 * (AuthController::resetClientContactIfAsked), and the login screen's
 * "Client / Customer" option posts to /client-portal/login. registerClient was
 * the last place still pretending a customer was a User.
 *
 * These tests pin the flow end to end — registration, the refusal before
 * approval, and the sign-in after it — because the old version passed every test
 * that existed, there being none.
 */
class ClientSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** A tenant for AgencyContext to resolve to. */
    private function tenant(): Tenant
    {
        return Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Priya',
            'last_name'             => 'Sharma',
            'email'                 => 'priya@northwind.example',
            'company'               => 'Northwind Logistics',
            'phone'                 => '9876543210',
            'address'               => '14 MG Road',
            'city'                  => 'Pune',
            'state'                 => 'Maharashtra',
            'country'               => 'India',
            'password'              => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
        ], $overrides);
    }

    /* ── what registration creates ──────────────────────────────────────── */

    public function test_registering_creates_a_customer_and_a_primary_contact(): void
    {
        $this->tenant();

        $this->postJson('/api/auth/register/client', $this->payload())
            ->assertCreated();

        $client = Client::where('company', 'Northwind Logistics')->firstOrFail();
        $this->assertSame(1, $client->tenant_id, 'The agency tenant must be resolved, not left null.');
        $this->assertSame('Pune', $client->city, 'The address the form collected must be kept.');

        $contact = ClientContact::where('email', 'priya@northwind.example')->firstOrFail();
        $this->assertSame($client->id, $contact->client_id, 'The contact must hang off the customer it registered.');
        $this->assertTrue((bool) $contact->is_primary, 'The person who signs the company up is its primary contact.');
    }

    /**
     * The whole point. A customer is not staff, and the orphaned role='client'
     * row was what made every reader of this flow believe otherwise.
     */
    public function test_registering_creates_no_user_account(): void
    {
        $this->tenant();

        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $this->assertSame(0, User::where('email', 'priya@northwind.example')->count(),
            'A customer contact is not a User — the portal refuses a User token outright.');
        $this->assertSame(0, User::where('role', 'client')->count(),
            'Nothing should mint a role=client login any more.');
    }

    /** Both gates the portal actually checks must start shut. */
    public function test_registration_lands_unapproved_at_both_gates(): void
    {
        $this->tenant();

        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $contact = ClientContact::where('email', 'priya@northwind.example')->firstOrFail();

        $this->assertFalse((bool) $contact->client->active,
            'clients.active is the customer-level gate ClientPortalAuthService::login() checks.');
        $this->assertSame('inactive', $contact->portal_status,
            'portal_status is the contact-level gate. Self-registration grants neither.');
        $this->assertNull($contact->email_verified_at,
            'Filling in a form proves nothing about owning the mailbox.');
    }

    /* ── and that the two gates hold ────────────────────────────────────── */

    /**
     * The refusal has to come from the password check first, then the status —
     * otherwise the endpoint tells a stranger whether an address is on file.
     */
    public function test_a_registered_customer_cannot_sign_in_before_approval(): void
    {
        $this->tenant();
        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $this->postJson('/api/client-portal/login', [
            'email' => 'priya@northwind.example', 'password' => 'correct-horse-9',
        ])->assertStatus(403);
    }

    public function test_a_wrong_password_is_refused_without_revealing_the_account(): void
    {
        $this->tenant();
        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $this->postJson('/api/client-portal/login', [
            'email' => 'priya@northwind.example', 'password' => 'not-the-password',
        ])->assertStatus(401);
    }

    /**
     * Approval needs no new endpoint: the Status switch writes clients.active and
     * the existing invite flow writes portal_status. Once both are on, the
     * password the person chose at registration is the one that works — which is
     * why it is stored rather than discarded.
     */
    public function test_after_approval_the_password_chosen_at_registration_works(): void
    {
        $this->tenant();
        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $contact = ClientContact::where('email', 'priya@northwind.example')->firstOrFail();
        $contact->client->update(['active' => true]);
        $contact->update(['portal_status' => 'active']);

        $this->postJson('/api/client-portal/login', [
            'email' => 'priya@northwind.example', 'password' => 'correct-horse-9',
        ])->assertOk()->assertJsonPath('data.contact.email', 'priya@northwind.example');
    }

    /* ── the duplicate that would be unfixable later ────────────────────── */

    /**
     * ClientPortalAuthService refuses to authenticate an address held by two
     * portal accounts, and refuses to invite a second one. A duplicate admitted
     * here is a contact nobody could ever grant access to, so it is refused at
     * the form instead.
     */
    public function test_an_address_already_on_a_contact_cannot_register_again(): void
    {
        $this->tenant();
        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $this->postJson('/api/auth/register/client', $this->payload(['company' => 'Somewhere Else']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, ClientContact::where('email', 'priya@northwind.example')->count());
    }

    /**
     * A staff login on the same address must NOT block it. One person can be an
     * employee somewhere and a customer contact here — common for consultants —
     * and the old `unique:users,email` rule turned that into a registration they
     * could not complete.
     */
    public function test_a_staff_login_on_the_same_address_does_not_block_registration(): void
    {
        $tenant = $this->tenant();

        User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya Sharma',
            'email' => 'priya@northwind.example', 'password' => 'irrelevant',
            'role' => 'staff', 'status' => 'active',
        ]);

        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        $this->assertSame(1, ClientContact::where('email', 'priya@northwind.example')->count());
    }

    /* ── a customer removed later must not hold the address forever ──────── */

    public function test_a_soft_deleted_contact_frees_its_address(): void
    {
        $this->tenant();
        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();

        ClientContact::where('email', 'priya@northwind.example')->firstOrFail()->delete();

        $this->postJson('/api/auth/register/client', $this->payload())->assertCreated();
    }
}
