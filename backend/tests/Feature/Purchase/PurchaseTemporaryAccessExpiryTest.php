<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Services\Purchase\PurchaseAccessService;
use App\Support\Purchase\PurchaseAccessStatus as Access;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A temporary Purchase Vendor's access window actually closes.
 *
 * Before this, expiry on the Purchase side was decorative. The countdown reached
 * zero, the badge turned red and said Expired — and the vendor kept full portal
 * access, because the guard consulted only portal_status, nothing set
 * portal_status on expiry, and no sweep existed to notice. Proven by probe
 * before the fix: a window that had closed three days earlier still answered 200
 * on /portal/purchase/dashboard.
 *
 * TPV had all of this from the start. The two engines are deliberately separate,
 * and this is the cost of that: a capability can be complete on one side and
 * absent on the other with nothing failing anywhere.
 */
class PurchaseTemporaryAccessExpiryTest extends TestCase
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

    private function vendor(array $overrides = []): PurchaseVendor
    {
        return PurchaseVendor::create(array_merge([
            'tenant_id' => self::TENANT,
            'company_name' => 'Acme Temporary',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(4).'@t.local',
            'category' => 'Supplier', 'currency' => 'INR',
            'vendor_type' => 'temporary',
            'registration_type' => RegistrationType::TEMPORARY,
            'status' => 'Active', 'portal_status' => 'active',
            'approved_at' => now()->subDays(30),
            'access_expires_at' => now()->addDays(5),
            'access_status' => Access::ACTIVE,
        ], $overrides));
    }

    /* ── the door ───────────────────────────────────────────────── */

    public function test_an_expired_vendor_is_refused_by_the_portal(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->subDays(3)]);
        Sanctum::actingAs($vendor, ['*']);

        $res = $this->getJson('/api/portal/purchase/dashboard');

        // This exact request answered 200 before the gate existed.
        $res->assertStatus(403);
        $this->assertSame('access_expired', $res->json('code'),
            'the refusal must say WHY — a bare 403 reads as a broken portal');
    }

    public function test_the_refusal_also_closes_the_account(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->subDay()]);
        Sanctum::actingAs($vendor, ['*']);

        $this->getJson('/api/portal/purchase/dashboard')->assertStatus(403);

        $vendor->refresh();
        $this->assertSame(Access::EXPIRED, $vendor->access_status);
        $this->assertSame('suspended', $vendor->portal_status);
    }

    public function test_a_live_window_is_left_alone(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->addDays(5)]);
        Sanctum::actingAs($vendor, ['*']);

        $this->getJson('/api/portal/purchase/dashboard')->assertOk();
        $this->assertSame('active', $vendor->fresh()->portal_status);
    }

    public function test_a_permanent_vendor_is_never_expired(): void
    {
        // The dangerous failure mode of a sweep: locking out everybody because
        // "no expiry date" was read as "expired".
        $vendor = $this->vendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
            'access_status' => null,
        ]);

        Sanctum::actingAs($vendor, ['*']);
        $this->getJson('/api/portal/purchase/dashboard')->assertOk();

        $this->assertFalse($vendor->fresh()->isAccessExpired());
        $this->assertSame(PHP_INT_MAX, $vendor->accessSecondsRemaining());
    }

    /* ── the tokens ─────────────────────────────────────────────── */

    public function test_expiring_signs_the_vendor_out_everywhere(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->subDay()]);
        $vendor->createToken('portal');
        $vendor->createToken('mobile');
        $this->assertSame(2, $vendor->tokens()->count());

        app(PurchaseAccessService::class)->expire($vendor);

        // Suspending the account alone would stop the NEXT sign-in and leave
        // anyone already holding a token working until it lapsed on its own —
        // which for a vendor logged in at the moment the clock ran out is no
        // expiry at all.
        $this->assertSame(0, $vendor->fresh()->tokens()->count());
    }

    /* ── the sweep ──────────────────────────────────────────────── */

    public function test_the_sweep_expires_a_lapsed_window(): void
    {
        $this->vendor(['access_expires_at' => now()->subHour()]);

        $result = app(PurchaseAccessService::class)->sendDueReminders();

        $this->assertSame(1, $result['expired']);
        $this->assertDatabaseHas('purchase_vendors', ['access_status' => Access::EXPIRED]);
    }

    public function test_the_sweep_warns_before_it_expires(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->addHours(20)]);

        $result = app(PurchaseAccessService::class)->sendDueReminders();

        // 20 hours left is inside 7d, 3d and 1d, but not yet 6h.
        $this->assertSame(3, $result['reminders_sent']);
        $this->assertSame(0, $result['expired']);

        $sent = $vendor->fresh()->access_reminders_sent;
        $this->assertEqualsCanonicalizing(['7d', '3d', '1d'], $sent);
    }

    public function test_a_reminder_is_never_sent_twice(): void
    {
        $this->vendor(['access_expires_at' => now()->addHours(20)]);

        $first = app(PurchaseAccessService::class)->sendDueReminders();
        $second = app(PurchaseAccessService::class)->sendDueReminders();

        // The sweep is hourly. Without the sent-list this is the same three
        // warnings every hour for a week, which trains people to ignore them.
        $this->assertSame(3, $first['reminders_sent']);
        $this->assertSame(0, $second['reminders_sent']);
    }

    public function test_the_sweep_leaves_permanent_vendors_alone(): void
    {
        $this->vendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            // A stale end date on a vendor that was converted: the sweep must
            // read the TYPE, not merely the presence of a date.
            'access_expires_at' => now()->subYear(),
            'access_status' => null,
        ]);

        $result = app(PurchaseAccessService::class)->sendDueReminders();

        $this->assertSame(0, $result['expired']);
        $this->assertSame(0, $result['reminders_sent']);
    }

    public function test_a_converted_vendor_is_not_swept_up_again(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->subDay()]);
        $vendor->update(['access_status' => Access::CONVERTED]);

        $result = app(PurchaseAccessService::class)->sendDueReminders();

        $this->assertSame(0, $result['expired']);
        $this->assertSame(Access::CONVERTED, $vendor->fresh()->access_status);
    }

    /* ── conversion ends the clock ──────────────────────────────── */

    public function test_converting_stops_the_vendor_being_expired(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->subDay()]);

        $admin = \App\Models\User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        app(\App\Services\Purchase\PurchaseVendorService::class)->convertToPermanent($vendor, $admin);

        // The whole point of the promotion: the sweep must not come back for
        // them, and the portal must let them in.
        $this->assertSame(0, app(PurchaseAccessService::class)->sendDueReminders()['expired']);

        Sanctum::actingAs($vendor->fresh(), ['*']);
        $this->getJson('/api/portal/purchase/dashboard')->assertOk();
    }

    /* ── the projection the badge reads ─────────────────────────── */

    public function test_the_countdown_derives_expiring_without_storing_it(): void
    {
        $vendor = $this->vendor(['access_expires_at' => now()->addHours(6)]);

        $p = app(PurchaseAccessService::class)->project($vendor);

        $this->assertSame(Access::EXPIRING, $p['access_status']);
        $this->assertSame('red', $p['band']);
        // Derived for display only. Persisting "Expiring" makes it wrong the
        // moment the clock moves past it.
        $this->assertSame(Access::ACTIVE, $vendor->fresh()->access_status);
    }
}
