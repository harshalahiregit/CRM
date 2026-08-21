<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Customer\ClientNote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\ClientPortalAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The portal is a new authenticated surface facing people outside the company,
 * so the tests that matter are the boundaries: can a contact reach another
 * customer, a section they lack permission for, or anything internal.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;
    private Client $mine;
    private Client $theirs;
    private ClientContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->mine   = Client::create(['tenant_id' => $this->t->id, 'company' => 'Mine Ltd', 'active' => true]);
        $this->theirs = Client::create(['tenant_id' => $this->t->id, 'company' => 'Theirs Ltd', 'active' => true]);

        $this->contact = ClientContact::create([
            'tenant_id' => $this->t->id, 'client_id' => $this->mine->id,
            'first_name' => 'Riya', 'last_name' => 'Shah', 'email' => 'riya@mine.test',
            'password' => Hash::make('Secret@12345'), 'active' => true,
            'portal_status' => 'active', 'permissions' => ['invoice', 'support'],
        ]);
    }

    private function asContact(): void
    {
        Sanctum::actingAs($this->contact, ['*']);
    }

    // ── Login ────────────────────────────────────────────────────────────

    public function test_a_contact_can_sign_in(): void
    {
        $this->postJson('/api/client-portal/login', [
            'email' => 'riya@mine.test', 'password' => 'Secret@12345',
        ])->assertOk()->assertJsonPath('data.contact.email', 'riya@mine.test');
    }

    public function test_portal_access_must_be_enabled(): void
    {
        $this->contact->update(['portal_status' => 'inactive']);

        $this->postJson('/api/client-portal/login', [
            'email' => 'riya@mine.test', 'password' => 'Secret@12345',
        ])->assertStatus(403);
    }

    /** A wrong password and an unknown address must be indistinguishable. */
    public function test_login_does_not_reveal_whether_an_account_exists(): void
    {
        $a = $this->postJson('/api/client-portal/login', ['email' => 'riya@mine.test', 'password' => 'wrong']);
        $b = $this->postJson('/api/client-portal/login', ['email' => 'nobody@nowhere.test', 'password' => 'wrong']);

        $a->assertStatus(401);
        $b->assertStatus(401);
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_forgot_password_answers_the_same_either_way(): void
    {
        $known   = $this->postJson('/api/client-portal/forgot-password', ['email' => 'riya@mine.test']);
        $unknown = $this->postJson('/api/client-portal/forgot-password', ['email' => 'nobody@nowhere.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    // ── Boundaries ───────────────────────────────────────────────────────

    public function test_a_staff_token_cannot_reach_the_portal(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->t->id, 'name' => 'Staff', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));

        $this->getJson('/api/portal/client/dashboard')->assertStatus(403);
    }

    public function test_a_section_the_contact_lacks_permission_for_is_refused(): void
    {
        $this->asContact();   // has invoice + support only

        $this->getJson('/api/portal/client/invoices')->assertOk();
        $this->getJson('/api/portal/client/tickets')->assertOk();
        $this->getJson('/api/portal/client/estimates')->assertStatus(403);
        $this->getJson('/api/portal/client/projects')->assertStatus(403);
        $this->getJson('/api/portal/client/contracts')->assertStatus(403);
    }

    /** A legacy row with no permissions array must mean nothing, not everything. */
    public function test_a_contact_with_no_permissions_gets_no_sections(): void
    {
        $this->contact->update(['permissions' => null]);
        $this->asContact();

        $this->getJson('/api/portal/client/invoices')->assertStatus(403);
    }

    public function test_only_client_visible_notes_are_returned(): void
    {
        // created_by is a real FK — a staff author has to exist.
        $author = User::create([
            'tenant_id' => $this->t->id, 'name' => 'Author', 'email' => 'author@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        ClientNote::create(['tenant_id' => $this->t->id, 'client_id' => $this->mine->id,
            'content' => 'INTERNAL — chasing payment', 'visibility' => 'team', 'created_by' => $author->id]);
        ClientNote::create(['tenant_id' => $this->t->id, 'client_id' => $this->mine->id,
            'content' => 'Shared with you', 'visibility' => 'client', 'created_by' => $author->id]);

        // And a note belonging to a DIFFERENT customer, marked client-visible:
        // visibility alone must never be enough to cross the customer boundary.
        ClientNote::create(['tenant_id' => $this->t->id, 'client_id' => $this->theirs->id,
            'content' => 'Another customer note', 'visibility' => 'client', 'created_by' => $author->id]);

        $this->asContact();
        $body = $this->getJson('/api/portal/client/notes')->assertOk()->json();

        $this->assertCount(1, $body);
        $this->assertSame('Shared with you', $body[0]['content']);
    }

    /** There is no endpoint that takes a client id, so this is the proof. */
    public function test_no_portal_route_accepts_a_client_id(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/portal/client'))
            ->filter(fn ($r) => str_contains($r->uri(), '{'));

        $this->assertCount(0, $routes, 'portal routes must not take an id from the caller');
    }

    public function test_a_contact_cannot_change_their_own_email(): void
    {
        $this->asContact();

        $this->putJson('/api/portal/client/profile', [
            'first_name' => 'Riya', 'email' => 'attacker@evil.test',
        ])->assertOk();

        $this->assertSame('riya@mine.test', $this->contact->fresh()->email);
    }

    // ── Set password ─────────────────────────────────────────────────────

    public function test_an_invitation_lets_a_contact_set_a_password(): void
    {
        $svc = app(ClientPortalAuthService::class);
        $svc->invite($this->contact);

        $token = $this->contact->fresh()->password_reset_token;
        $this->assertNotNull($token);

        $this->postJson('/api/client-portal/set-password', [
            'token' => $token, 'password' => 'BrandNew@123', 'password_confirmation' => 'BrandNew@123',
        ])->assertOk();

        $this->postJson('/api/client-portal/login', [
            'email' => 'riya@mine.test', 'password' => 'BrandNew@123',
        ])->assertOk();
    }

    public function test_an_expired_link_is_refused(): void
    {
        app(ClientPortalAuthService::class)->invite($this->contact);
        $this->contact->forceFill(['password_reset_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/client-portal/set-password', [
            'token' => $this->contact->fresh()->password_reset_token,
            'password' => 'BrandNew@123', 'password_confirmation' => 'BrandNew@123',
        ])->assertStatus(410);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $svc = app(ClientPortalAuthService::class);
        $svc->invite($this->contact);
        $token = $this->contact->fresh()->password_reset_token;

        $this->postJson('/api/client-portal/set-password', [
            'token' => $token, 'password' => 'First@12345', 'password_confirmation' => 'First@12345',
        ])->assertOk();

        $this->postJson('/api/client-portal/set-password', [
            'token' => $token, 'password' => 'Second@1234', 'password_confirmation' => 'Second@1234',
        ])->assertStatus(404);
    }

    // ── Invitation, from the staff side ──────────────────────────────────

    private function actingStaff(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->t->id, 'name' => 'Staff', 'email' => 'staff@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    public function test_staff_can_invite_a_contact_to_the_portal(): void
    {
        $this->actingStaff();

        $this->postJson("/api/customers/{$this->mine->id}/contacts/{$this->contact->id}/invite")
             ->assertOk();

        $this->assertNotNull($this->contact->fresh()->password_reset_token);
    }

    /** An invitation to a contact with nothing granted lands on an empty portal. */
    public function test_inviting_a_contact_with_no_permissions_is_refused(): void
    {
        $this->contact->update(['permissions' => []]);
        $this->actingStaff();

        $this->postJson("/api/customers/{$this->mine->id}/contacts/{$this->contact->id}/invite")
             ->assertStatus(422);
    }

    public function test_inviting_a_contact_with_no_email_is_refused(): void
    {
        $this->contact->forceFill(['email' => null])->save();
        $this->actingStaff();

        $this->postJson("/api/customers/{$this->mine->id}/contacts/{$this->contact->id}/invite")
             ->assertStatus(422);
    }

    /** A contact of another customer must not be invitable through this client. */
    public function test_a_contact_from_another_customer_cannot_be_invited_through_this_one(): void
    {
        $other = ClientContact::create([
            'tenant_id' => $this->t->id, 'client_id' => $this->theirs->id,
            'first_name' => 'Other', 'email' => 'other@theirs.test',
            'active' => true, 'permissions' => ['invoice'],
        ]);
        $this->actingStaff();

        $this->postJson("/api/customers/{$this->mine->id}/contacts/{$other->id}/invite")
             ->assertStatus(404);
    }
}
