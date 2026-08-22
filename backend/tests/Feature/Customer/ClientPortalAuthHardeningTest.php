<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who may hold a customer portal account, and who may not.
 *
 * The portal has no tenant context — a contact signs in with an email and a
 * password and nothing else. That makes the email the whole identity, and two
 * defects followed from treating it loosely.
 *
 * 1. The lookup was `where('email', $email)->first()`. With the same address on
 *    two contacts — a shared accountant, somebody who changed employer — that
 *    returns whichever row the database offers first, which can be a different
 *    customer's account and, since nothing scoped it, a different tenant's.
 *
 * 2. Forgotten-password invited whoever it found. A contact nobody had granted
 *    access to would be switched to 'invited' and emailed a working link, so
 *    anyone whose address appeared in the CRM could enrol themselves. The
 *    sections with no permission gate — notes, files, the company's contact
 *    list — were then readable.
 */
class ClientPortalAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(int $id, string $slug): Tenant
    {
        return Tenant::firstOrCreate(['id' => $id], [
            'name' => ucfirst($slug), 'slug' => $slug, 'subdomain' => $slug,
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    private function contact(int $tenantId, string $company, string $email, ?string $portalStatus, bool $withPassword = true): ClientContact
    {
        $client = Client::create(['tenant_id' => $tenantId, 'company' => $company, 'active' => true]);

        return ClientContact::create([
            'tenant_id' => $tenantId, 'client_id' => $client->id,
            'first_name' => 'A', 'last_name' => 'Person', 'email' => $email, 'active' => true,
            'portal_status' => $portalStatus,
            'password' => $withPassword ? Hash::make('secret123') : null,
            'permissions' => ['invoice'],
        ]);
    }

    // ── ambiguity ────────────────────────────────────────────────────────────

    public function test_an_email_shared_across_two_tenants_authenticates_nobody(): void
    {
        $this->tenant(1, 'acme');
        $this->tenant(2, 'globex');
        $this->contact(1, 'Acme Ltd', 'shared@example.test', 'active');
        $this->contact(2, 'Globex Ltd', 'shared@example.test', 'active');

        // Refusing is the only safe answer: there is no tenant in the request
        // to disambiguate with, so signing them in means guessing whose data.
        $this->postJson('/api/client-portal/login', [
            'email' => 'shared@example.test', 'password' => 'secret123',
        ])->assertStatus(401);
    }

    public function test_a_shared_email_is_not_sent_a_reset_either(): void
    {
        $this->tenant(1, 'acme');
        $this->tenant(2, 'globex');
        $a = $this->contact(1, 'Acme Ltd', 'shared@example.test', 'active');
        $b = $this->contact(2, 'Globex Ltd', 'shared@example.test', 'active');

        $this->postJson('/api/client-portal/forgot-password', ['email' => 'shared@example.test'])
            ->assertOk();

        // Neither account gets a usable link — a reset on an ambiguous address
        // would hand one person a way into the other's company.
        $this->assertNull($a->fresh()->password_reset_token);
        $this->assertNull($b->fresh()->password_reset_token);
    }

    public function test_a_unique_email_still_signs_in_normally(): void
    {
        $this->tenant(1, 'acme');
        $this->contact(1, 'Acme Ltd', 'solo@example.test', 'active');

        $this->postJson('/api/client-portal/login', [
            'email' => 'solo@example.test', 'password' => 'secret123',
        ])->assertOk()->assertJsonPath('data.access_token', fn ($t) => is_string($t) && $t !== '');
    }

    // ── self-enrolment ───────────────────────────────────────────────────────

    public function test_a_contact_nobody_invited_cannot_enrol_via_forgot_password(): void
    {
        $this->tenant(1, 'acme');
        // 'inactive' is the column default — this is what a contact nobody
        // invited actually looks like, and what fell through to invite().
        $never = $this->contact(1, 'Acme Ltd', 'never@example.test', 'inactive', withPassword: false);

        $this->postJson('/api/client-portal/forgot-password', ['email' => 'never@example.test'])
            ->assertOk();   // still silent — the endpoint must not confirm the address

        $fresh = $never->fresh();
        $this->assertNull($fresh->password_reset_token, 'a never-invited contact was sent a working link');
        $this->assertSame('inactive', $fresh->portal_status, 'forgot-password granted portal access on its own');
    }

    public function test_an_inactive_contact_cannot_reset_their_way_in(): void
    {
        $this->tenant(1, 'acme');
        $off = $this->contact(1, 'Acme Ltd', 'off@example.test', 'inactive');

        $this->postJson('/api/client-portal/forgot-password', ['email' => 'off@example.test'])->assertOk();

        $this->assertNull($off->fresh()->password_reset_token);
    }

    public function test_an_invited_contact_can_still_reset(): void
    {
        $this->tenant(1, 'acme');
        $invited = $this->contact(1, 'Acme Ltd', 'invited@example.test', 'invited', withPassword: false);

        $this->postJson('/api/client-portal/forgot-password', ['email' => 'invited@example.test'])->assertOk();

        $this->assertNotNull($invited->fresh()->password_reset_token);
    }

    public function test_an_inactive_contact_with_the_right_password_is_told_why(): void
    {
        $this->tenant(1, 'acme');
        $this->contact(1, 'Acme Ltd', 'off@example.test', 'inactive');

        // Deliberately NOT the generic 401. This message is only reachable
        // after the password check passes, so it reveals nothing to someone
        // guessing addresses — and the person it does reach needs to know their
        // access was switched off rather than retype a correct password.
        $this->postJson('/api/client-portal/login', [
            'email' => 'off@example.test', 'password' => 'secret123',
        ])->assertStatus(403);

        // The wrong password on the same account stays generic.
        $this->postJson('/api/client-portal/login', [
            'email' => 'off@example.test', 'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    // ── the collision is refused at the point it would be created ────────────

    public function test_inviting_a_second_contact_on_the_same_email_is_refused(): void
    {
        $t = $this->tenant(1, 'acme');
        $this->contact(1, 'Acme Ltd', 'shared@example.test', 'active');
        $second = $this->contact(1, 'Beta Ltd', 'shared@example.test', 'inactive', withPassword: false);

        // Creating the ambiguity is the mistake; catching it at login is too late,
        // because by then the first account has silently stopped working.
        $this->expectException(\App\Exceptions\BusinessException::class);
        app(\App\Services\Customer\ClientPortalAuthService::class)->invite($second);
    }

    public function test_reinviting_the_same_contact_is_fine(): void
    {
        $this->tenant(1, 'acme');
        $c = $this->contact(1, 'Acme Ltd', 'solo@example.test', 'active');

        app(\App\Services\Customer\ClientPortalAuthService::class)->invite($c);

        $this->assertNotNull($c->fresh()->password_reset_token);
    }
}
