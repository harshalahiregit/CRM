<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin TPV API is for admin and staff. A vendor login uses the portal.
 *
 * routes/tpv.php once carried `role:admin,staff,third_party_vendor,vendor` —
 * widened so the portal could reach two worker endpoints. But that gate covers
 * the whole module, and TpvOnboardingController checks only the TENANT, never
 * the vendor. Any TPV login could therefore list, read, overwrite and delete
 * every OTHER vendor's onboarding — bank account, IFSC, GST, PAN, documents —
 * straight past the portal's assertOwned() checks.
 *
 * The two worker endpoints now exist under /portal with ownership checks, and
 * the admin gate is back to admin,staff. These tests hold both halves in place:
 * the admin API refuses vendors, and the portal replacements are owner-scoped.
 */
class TpvAdminApiVendorIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    /** A vendor company, optionally linked to a portal login. */
    private function vendor(string $name, ?User $owner = null): Vendor
    {
        return Vendor::create([
            'tenant_id'   => self::TENANT,
            'company_name' => $name,
            // Never the owner's email: the portal middleware also links by email,
            // and a collision would hand one login two vendors.
            'email'       => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status'      => 'Active',
            'user_id'     => $owner?->id,
        ]);
    }

    private function onboardingFor(Vendor $v): TpvOnboarding
    {
        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'current_step' => 1, 'status' => Status::IN_PROGRESS,
        ]);
    }

    private function workerFor(Vendor $v): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'worker_code' => 'W-'.Str::random(6), 'name' => 'Worker',
            'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /** A TPV login plus its own vendor company. */
    private function tpvLogin(string $company): array
    {
        $user = $this->user('third_party_vendor');

        return [$user, $this->vendor($company, $user)];
    }

    /* ── The hole that was open ───────────────────────────────────────── */

    public function test_a_vendor_cannot_list_every_onboarding_through_the_admin_api(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $this->onboardingFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        $this->getJson('/api/tpv/onboarding')->assertStatus(403);
        $this->getJson('/api/tpv/onboarding/stats')->assertStatus(403);
    }

    public function test_a_vendor_cannot_read_another_vendors_onboarding_through_the_admin_api(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $rival = $this->onboardingFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        // The payload eager-loads vendor.documents and vendor.contacts.
        $this->getJson("/api/tpv/onboarding/{$rival->id}")->assertStatus(403);
        $this->getJson("/api/tpv/onboarding/{$rival->id}/progress")->assertStatus(403);
    }

    public function test_a_vendor_cannot_overwrite_another_vendors_banking_profile(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $rivalCo = $this->vendor('RivalCo');
        $rival   = $this->onboardingFor($rivalCo);

        Sanctum::actingAs($user);

        $this->postJson("/api/tpv/onboarding/{$rival->id}/profile", [
            'profile' => ['bank_account_number' => '999999999', 'bank_ifsc' => 'HDFC0001234'],
        ])->assertStatus(403);

        $this->assertNotSame('999999999', $rivalCo->fresh()->bank_account_number);
    }

    public function test_a_vendor_cannot_drive_or_submit_another_vendors_onboarding(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $rival = $this->onboardingFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        $this->patchJson("/api/tpv/onboarding/{$rival->id}/step", ['step' => 6])->assertStatus(403);
        $this->postJson("/api/tpv/onboarding/{$rival->id}/submit", [])->assertStatus(403);

        $this->assertSame(1, $rival->fresh()->current_step);
    }

    public function test_a_vendor_cannot_delete_an_onboarding(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $rival = $this->onboardingFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        $this->deleteJson("/api/tpv/onboarding/{$rival->id}")->assertStatus(403);
        $this->assertNotNull(TpvOnboarding::find($rival->id));
    }

    /* ── Admin and staff are unaffected ───────────────────────────────── */

    public function test_admin_and_staff_keep_the_admin_api(): void
    {
        $ob = $this->onboardingFor($this->vendor('QueueCo'));

        foreach (['admin', 'staff'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/tpv/onboarding')->assertOk();
            $this->getJson("/api/tpv/onboarding/{$ob->id}")->assertOk();
        }
    }

    public function test_only_an_admin_may_approve(): void
    {
        // The approval routes sit in their own role:admin group; tightening the
        // main gate must not have disturbed them.
        $ob = $this->onboardingFor($this->vendor('ApproveCo'));

        Sanctum::actingAs($this->user('staff'));
        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", ['remarks' => 'ok'])->assertStatus(403);

        [$vendorUser] = $this->tpvLogin('SelfApproveCo');
        Sanctum::actingAs($vendorUser);
        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", ['remarks' => 'ok'])->assertStatus(403);
    }

    public function test_a_vendor_keeps_its_own_temporary_access_countdown(): void
    {
        // Its own narrow role:third_party_vendor group — must survive the change.
        [$user] = $this->tpvLogin('ClockCo');
        Sanctum::actingAs($user);

        $this->getJson('/api/tpv/access/countdown')->assertOk();
    }

    /* ── The portal replacements are owner-scoped ─────────────────────── */

    public function test_a_vendor_may_punch_its_own_worker(): void
    {
        [$user, $mine] = $this->tpvLogin('MineCo');
        $worker = $this->workerFor($mine);

        Sanctum::actingAs($user);

        $this->postJson("/api/portal/workers/{$worker->id}/mark-punch", [
            'punch_count' => 1, 'punch_reason' => 'No helmet',
        ])->assertOk();

        $this->assertSame(1, (int) $worker->fresh()->punch_count);
    }

    public function test_a_vendor_cannot_punch_another_vendors_worker(): void
    {
        [$user] = $this->tpvLogin('MineCo');
        $rivalWorker = $this->workerFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        // 404, not 403 — the portal hides the existence of other vendors' rows.
        $this->postJson("/api/portal/workers/{$rivalWorker->id}/mark-punch", [
            'punch_count' => 1, 'punch_reason' => 'No helmet',
        ])->assertStatus(404);

        $this->assertSame(0, (int) $rivalWorker->fresh()->punch_count);
    }

    public function test_a_vendor_may_card_its_own_worker_but_not_anothers(): void
    {
        [$user, $mine] = $this->tpvLogin('MineCo');
        $ownWorker   = $this->workerFor($mine);
        $rivalWorker = $this->workerFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        $this->postJson("/api/portal/workers/{$ownWorker->id}/mark-card-status", ['card_status' => 1])
            ->assertOk();
        $this->assertSame(1, (int) $ownWorker->fresh()->card_status);

        $this->postJson("/api/portal/workers/{$rivalWorker->id}/mark-card-status", ['card_status' => 1])
            ->assertStatus(404);
        $this->assertNotSame(1, (int) $rivalWorker->fresh()->card_status);
    }

    public function test_the_portal_serves_only_the_callers_own_onboarding(): void
    {
        [$user, $mine] = $this->tpvLogin('MineCo');
        $ownOb   = $this->onboardingFor($mine);
        $rivalOb = $this->onboardingFor($this->vendor('RivalCo'));

        Sanctum::actingAs($user);

        $this->getJson("/api/portal/onboarding/{$ownOb->id}")->assertOk();
        $this->getJson("/api/portal/onboarding/{$rivalOb->id}")->assertStatus(404);

        // A VALID payload, so the form request passes and assertOwned() is what
        // answers. (SaveOnboardingProfileRequest runs before the controller, so
        // a malformed body 422s first — for your own record just the same, which
        // is why that ordering leaks nothing.)
        $this->postJson("/api/portal/onboarding/{$rivalOb->id}/profile", [
            'profile' => ['bank_account_number' => '999999999', 'bank_ifsc' => 'HDFC0001234'],
        ])->assertStatus(404);
    }
}
