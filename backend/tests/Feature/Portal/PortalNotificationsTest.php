<?php

namespace Tests\Feature\Portal;

use App\Models\Notification;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * In-app (bell) notifications in BOTH vendor portals: a vendor reads/clears its
 * OWN notifications only. TPV rides the shared users-keyed store; Purchase has
 * its own purchase_vendor_notifications store (a PurchaseVendor is not a User).
 */
class PortalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function tpvVendor(string $name): Vendor
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => $name, 'status' => VendorStatus::ACTIVE, 'email' => strtolower($name).'@t.local']);
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => strtolower($name).'@t.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor->update(['user_id' => $user->id]);

        return $vendor->fresh();
    }

    private function purchaseVendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active',
        ]);
    }

    /* ── TPV portal ─────────────────────────────────────────────────────── */

    public function test_tpv_vendor_reads_only_its_own_notifications(): void
    {
        $mine  = $this->tpvVendor('Acme');
        $other = $this->tpvVendor('BetaCo');

        Notification::create(['tenant_id' => self::TENANT, 'user_id' => $mine->user_id, 'type' => 't', 'title' => 'Mine A']);
        Notification::create(['tenant_id' => self::TENANT, 'user_id' => $mine->user_id, 'type' => 't', 'title' => 'Mine B']);
        Notification::create(['tenant_id' => self::TENANT, 'user_id' => $other->user_id, 'type' => 't', 'title' => 'Theirs']);

        Sanctum::actingAs($mine->user);
        $res = $this->getJson('/api/portal/notifications')->assertOk();
        $res->assertJsonPath('unread_count', 2);
        $titles = collect($res->json('items'))->pluck('title')->all();
        sort($titles);
        $this->assertSame(['Mine A', 'Mine B'], $titles);
    }

    public function test_tpv_vendor_marks_read_and_read_all(): void
    {
        $vendor = $this->tpvVendor('Acme');
        $one = Notification::create(['tenant_id' => self::TENANT, 'user_id' => $vendor->user_id, 'type' => 't', 'title' => 'One']);
        Notification::create(['tenant_id' => self::TENANT, 'user_id' => $vendor->user_id, 'type' => 't', 'title' => 'Two']);

        Sanctum::actingAs($vendor->user);
        $this->patchJson("/api/portal/notifications/{$one->id}/read")->assertOk();
        $this->getJson('/api/portal/notifications')->assertOk()->assertJsonPath('unread_count', 1);

        $this->postJson('/api/portal/notifications/read-all')->assertOk();
        $this->getJson('/api/portal/notifications')->assertOk()->assertJsonPath('unread_count', 0);
    }

    /* ── Purchase portal ────────────────────────────────────────────────── */

    public function test_purchase_vendor_reads_only_its_own_notifications(): void
    {
        $mine  = $this->purchaseVendor('Bolt');
        $other = $this->purchaseVendor('Nut');

        PurchaseVendorNotification::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $mine->id, 'type' => 't', 'title' => 'Mine']);
        PurchaseVendorNotification::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $other->id, 'type' => 't', 'title' => 'Theirs']);

        Sanctum::actingAs($mine);
        $res = $this->getJson('/api/portal/purchase/notifications')->assertOk();
        $res->assertJsonPath('unread_count', 1);
        $titles = collect($res->json('items'))->pluck('title')->all();
        $this->assertSame(['Mine'], $titles);
    }

    public function test_purchase_vendor_marks_read_and_read_all(): void
    {
        $vendor = $this->purchaseVendor('Bolt');
        $one = PurchaseVendorNotification::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'type' => 't', 'title' => 'One']);
        PurchaseVendorNotification::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'type' => 't', 'title' => 'Two']);

        Sanctum::actingAs($vendor);
        $this->patchJson("/api/portal/purchase/notifications/{$one->id}/read")->assertOk();
        $this->getJson('/api/portal/purchase/notifications')->assertOk()->assertJsonPath('unread_count', 1);

        $this->postJson('/api/portal/purchase/notifications/read-all')->assertOk();
        $this->getJson('/api/portal/purchase/notifications')->assertOk()->assertJsonPath('unread_count', 0);
    }
}
