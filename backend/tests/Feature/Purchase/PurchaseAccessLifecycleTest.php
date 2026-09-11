<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseAccessStatus as Access;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The rest of a temporary Purchase Vendor's window: extend it, close it, read it.
 *
 * Promotion and expiry both existed and nothing sat between them, so an admin
 * whose contractor needed three more days had to choose between making them
 * permanent for ever and letting them be locked out on the day. Neither is what
 * anybody meant. TPV has had extend / force-expire / status since its temporary
 * work landed; this is the same four actions on the Purchase side.
 */
class PurchaseAccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'role' => 'staff',
            'email' => 's-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(array $overrides = []): PurchaseVendor
    {
        return PurchaseVendor::create(array_merge([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Temporary',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(4).'@t.local',
            'category' => 'Supplier', 'currency' => 'INR',
            'vendor_type' => 'temporary', 'registration_type' => RegistrationType::TEMPORARY,
            'status' => 'Active', 'portal_status' => 'active',
            'approved_at' => now()->subDays(20),
            'access_expires_at' => now()->addDays(2),
            'access_status' => Access::ACTIVE,
        ], $overrides));
    }

    /* ── Extend ─────────────────────────────────────────────────── */

    public function test_an_admin_extends_a_window_by_days(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($admin = $this->admin());

        $res = $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 10,
            'extension_reason' => 'Shutdown slipped a week; crew still on site.',
        ]);

        if ($res->getStatusCode() >= 400) {
            $this->fail('extend refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $v->refresh();
        $this->assertTrue($v->access_expires_at->isFuture());
        $this->assertSame(Access::ACTIVE, $v->access_status);
        $this->assertSame($admin->id, $v->access_extended_by);
        $this->assertSame('Shutdown slipped a week; crew still on site.', $v->extension_reason);
    }

    public function test_an_explicit_date_is_honoured(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->admin());

        $when = now()->addDays(45)->startOfDay();

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'access_expires_at' => $when->toDateString(),
            'extension_reason' => 'Contract runs to the end of the quarter.',
        ])->assertSuccessful();

        $this->assertSame($when->toDateString(), $v->fresh()->access_expires_at->toDateString());
    }

    public function test_an_extension_needs_a_reason(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->admin());

        // A reason nobody is obliged to give is a reason nobody gives, and then
        // the record cannot answer why a window moved.
        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", ['validity_days' => 5])
            ->assertStatus(422);
    }

    public function test_an_extension_needs_a_date_or_a_period(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'extension_reason' => 'Because I said so.',
        ])->assertStatus(422);
    }

    public function test_a_window_cannot_be_extended_into_the_past(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'access_expires_at' => now()->subDay()->toDateString(),
            'extension_reason' => 'Typo in the date.',
        ])->assertStatus(422);
    }

    public function test_extending_restarts_the_warnings(): void
    {
        $v = $this->vendor(['access_reminders_sent' => ['7d', '3d', '1d']]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 30,
            'extension_reason' => 'Extended to the end of the job.',
        ])->assertSuccessful();

        // Without this a vendor extended past the 7-day mark is never warned
        // again: the sweep believes it has already told them.
        $this->assertSame([], $v->fresh()->access_reminders_sent ?: []);
    }

    public function test_extending_lets_a_locked_out_vendor_back_in(): void
    {
        // The case that actually turns up: nobody extends until the contractor
        // rings to say they cannot sign in.
        $v = $this->vendor([
            'access_expires_at' => now()->subDay(),
            'access_status' => Access::EXPIRED,
            'portal_status' => 'suspended',
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 14,
            'extension_reason' => 'Locked out in error; work continues.',
        ])->assertSuccessful();

        $v->refresh();
        $this->assertSame('active', $v->portal_status);
        $this->assertSame(Access::ACTIVE, $v->access_status);

        Sanctum::actingAs($v, ['*']);
        $this->getJson('/api/portal/purchase/dashboard')->assertOk();
    }

    public function test_a_permanent_vendor_has_no_window_to_extend(): void
    {
        $v = $this->vendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 5, 'extension_reason' => 'Why not.',
        ])->assertStatus(422);
    }

    public function test_staff_cannot_extend(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->staff());

        // Moving an expiry somebody set deliberately is admin authority, the
        // same as promoting or closing.
        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 5, 'extension_reason' => 'Trying it on.',
        ])->assertStatus(403);
    }

    /* ── Force-expire ───────────────────────────────────────────── */

    public function test_an_admin_closes_a_window_early(): void
    {
        $v = $this->vendor();
        $v->createToken('portal');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/expire")->assertSuccessful();

        $v->refresh();
        $this->assertSame(Access::EXPIRED, $v->access_status);
        $this->assertSame('suspended', $v->portal_status);
        $this->assertSame(0, $v->tokens()->count(), 'a closed window must end the session too');
    }

    public function test_a_permanent_vendor_has_no_window_to_close(): void
    {
        $v = $this->vendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/expire")->assertStatus(422);
    }

    public function test_staff_cannot_force_expire(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/expire")->assertStatus(403);
    }

    /* ── Status ─────────────────────────────────────────────────── */

    public function test_the_status_view_answers_with_the_window_and_its_trail(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$v->id}/access/extend", [
            'validity_days' => 20,
            'extension_reason' => 'Second phase approved.',
        ])->assertSuccessful();

        $body = $this->getJson("/api/purchase/vendors/{$v->id}/access/status")->assertOk()->json();

        foreach (['is_temporary', 'access_status', 'access_expires_at', 'seconds_remaining', 'band'] as $k) {
            $this->assertArrayHasKey($k, $body, "the status view is missing {$k}");
        }

        $this->assertTrue($body['is_temporary']);
        $this->assertSame('Second phase approved.', $body['extension_reason']);
        $this->assertNotNull($body['extended_at']);
        $this->assertNotEmpty($body['timeline'], 'the extension should appear in the trail');
    }

    public function test_staff_may_read_the_status(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->staff());

        // Reading where a window stands is not an authority decision — a
        // coordinator needs to know when somebody's access runs out.
        $this->getJson("/api/purchase/vendors/{$v->id}/access/status")->assertOk();
    }

    public function test_a_permanent_vendor_reads_as_having_no_clock(): void
    {
        $v = $this->vendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
        ]);
        Sanctum::actingAs($this->admin());

        $body = $this->getJson("/api/purchase/vendors/{$v->id}/access/status")->assertOk()->json();

        $this->assertFalse($body['is_temporary']);
        // Null, not zero. Zero means expired; a permanent vendor has no clock.
        $this->assertNull($body['seconds_remaining']);
        $this->assertSame('permanent', $body['band']);
    }
}
