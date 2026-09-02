<?php

namespace Tests\Feature\Settings;

use App\Mail\Settings\TestMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\TenantMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ST1 — a signed-in user's own sender identity (mail_from_email/name) is used as
 * the From on outgoing mail, overriding the tenant/config default; with none set
 * the default applies.
 */
class PerUserSenderIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function user(array $over = []): User
    {
        return User::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Priya', 'role' => 'staff',
            'email' => 'u-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ], $over));
    }

    /** The From actually applied to an outgoing mailable, via the resolver. */
    private function resolvedFrom(): ?array
    {
        $mailer = app(TenantMailer::class);
        $ref = new \ReflectionMethod($mailer, 'effectiveFrom');
        $ref->setAccessible(true);

        return $ref->invoke($mailer, null);   // null = no tenant SMTP settings
    }

    public function test_user_identity_is_used_as_the_from(): void
    {
        $user = $this->user(['mail_from_name' => 'Priya Sales', 'mail_from_email' => 'priya@company.com']);
        $this->actingAs($user);

        $from = $this->resolvedFrom();
        $this->assertSame('priya@company.com', $from['email']);
        $this->assertSame('Priya Sales', $from['name']);
    }

    public function test_from_name_falls_back_to_the_email(): void
    {
        $user = $this->user(['mail_from_email' => 'ops@company.com']);   // email only, no name
        $this->actingAs($user);

        $from = $this->resolvedFrom();
        $this->assertSame('ops@company.com', $from['email']);
        $this->assertSame('ops@company.com', $from['name']);
    }

    public function test_without_identity_or_settings_there_is_no_forced_from(): void
    {
        $user = $this->user();   // no sender identity
        $this->actingAs($user);

        // No user identity and (in this call) no tenant settings → the config
        // default From is used, so the resolver forces nothing.
        $this->assertNull($this->resolvedFrom());
    }

    /**
     * The identity is NOT self-service.
     *
     * TenantMailer uses mail_from_email verbatim as the From address, so while
     * this was settable on the user's own profile any signed-in user could send
     * CRM mail as anyone — a colleague, a director, a customer — behind nothing
     * but a well-formed-email check. An admin sets it on the staff record.
     *
     * This test previously asserted the opposite. It is inverted rather than
     * deleted so the endpoint can never quietly start accepting the field again.
     */
    public function test_a_user_cannot_set_their_own_sender_identity(): void
    {
        $user = $this->user();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->putJson('/api/auth/profile', [
            'name' => 'Priya', 'mail_from_name' => 'Priya Sales', 'mail_from_email' => 'priya@company.com',
        ])->assertOk();   // the request succeeds; the sender fields are simply ignored

        $this->assertNull($user->fresh()->mail_from_email, 'A user must not be able to set their own From address.');
        $this->assertNull($user->fresh()->mail_from_name);
    }

    public function test_an_admin_can_set_it_on_a_staff_record(): void
    {
        $staff = $this->user();
        $staff->forceFill(['role' => 'staff'])->save();

        $admin = $this->user();
        $admin->forceFill(['role' => 'admin', 'tenant_id' => $staff->tenant_id])->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->putJson("/api/admin/staff/{$staff->id}", [
            'name' => $staff->name, 'mail_from_name' => 'Priya Sales', 'mail_from_email' => 'priya@company.com',
        ])->assertOk();

        $this->assertSame('priya@company.com', $staff->fresh()->mail_from_email);
        $this->assertSame('Priya Sales', $staff->fresh()->mail_from_name);
    }
}
