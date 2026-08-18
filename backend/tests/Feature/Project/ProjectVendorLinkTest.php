<?php

namespace Tests\Feature\Project;

use App\Models\Project\Project;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project ↔ vendor link, and the two ProjectFormDrawer bugs that reached it.
 *
 * Both were front-end bugs with a SERVER-observable outcome, which is what these
 * pin down — the payload the drawer sends is the contract:
 *
 *  1. Switching party type left vendor_id behind. Both vendor types write
 *     vendor_id and the same integer is a different company under each, so a
 *     Purchase Vendor's id carried onto a TPV selection resolved to a real but
 *     WRONG vendor and passed normalizeLink's existence check.
 *
 *  2. A legacy row (link_type 'vendor'/'tpv', vendor_user_id set, vendor_id
 *     empty) was displayed as "Purchase Vendor". Saving then sent
 *     link_type='purchase_vendor' with an empty vendor_id, which normalizeLink
 *     reads as "party explicitly cleared" — nulling customer_id, vendor_user_id,
 *     vendor_id and link_type. The project silently lost its party.
 */
class ProjectVendorLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function tpvVendor(string $name, ?User $login = null): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@v.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $login?->id,
        ]);
    }

    private function purchaseVendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(5), 'status' => 'Active',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Job '.Str::random(4), 'status' => 'not_started',
            'start_date' => now()->toDateString(), 'billing_type' => 'fixed',
        ], $extra);
    }

    /* ── Bug 1: switching party type must not carry the old id ────────── */

    public function test_switching_vendor_type_without_a_fresh_pick_does_not_reuse_the_old_id(): void
    {
        // Two DIFFERENT companies that happen to share an id across the two
        // tables — the exact case the stale vendor_id turned into a wrong link.
        $purchase = $this->purchaseVendor('Purchase Supplies');
        $tpv      = $this->tpvVendor('TPV Labour');

        Sanctum::actingAs($this->user('admin'));

        $id = $this->postJson('/api/projects', $this->payload([
            'link_type' => 'purchase_vendor', 'vendor_id' => $purchase->id,
        ]))->assertStatus(201)->json('data.id');

        $this->assertSame($purchase->id, Project::find($id)->vendor_id);

        // The drawer now clears vendor_id on a type switch, so this is what it
        // sends when the user switches to TPV and picks nobody: no vendor_id at
        // all. Previously it sent the purchase vendor's id under tpv_vendor.
        $this->putJson("/api/projects/{$id}", ['link_type' => 'tpv_vendor'])->assertOk();

        $p = Project::find($id)->fresh();
        $this->assertNull($p->vendor_id, 'a type switch must not carry the previous party id');
        $this->assertNull($p->link_type, 'with no party chosen the link is cleared, not mislinked');

        // And the TPV vendor that shares nothing with it was never touched.
        $this->assertNotSame($tpv->id, $p->vendor_id);
    }

    public function test_switching_from_customer_to_a_vendor_clears_the_customer(): void
    {
        $pv = $this->purchaseVendor('Purchase Supplies');
        Sanctum::actingAs($this->user('admin'));

        $id = $this->postJson('/api/projects', $this->payload([
            'link_type' => 'purchase_vendor', 'vendor_id' => $pv->id,
        ]))->assertStatus(201)->json('data.id');

        $p = Project::find($id);
        $this->assertNull($p->customer_id);
        // A Purchase Vendor is never a User, so no portal login is carried.
        $this->assertNull($p->vendor_user_id);
    }

    public function test_a_tpv_link_still_carries_its_portal_login(): void
    {
        // Unchanged behaviour: TPV vendors DO own a User, and the vendor-portal
        // queries read vendor_user_id, so normalizeLink backfills it.
        $login = $this->user('third_party_vendor');
        $tpv   = $this->tpvVendor('TPV Labour', $login);

        Sanctum::actingAs($this->user('admin'));

        $id = $this->postJson('/api/projects', $this->payload([
            'link_type' => 'tpv_vendor', 'vendor_id' => $tpv->id,
        ]))->assertStatus(201)->json('data.id');

        $p = Project::find($id);
        $this->assertSame($tpv->id, $p->vendor_id);
        $this->assertSame($login->id, $p->vendor_user_id);
        $this->assertSame('tpv_vendor', $p->link_type);
    }

    /* ── Bug 2: a legacy row must survive an unrelated save ───────────── */

    public function test_an_untouched_legacy_vendor_row_keeps_its_party(): void
    {
        // A row from before record-backed vendors: portal user, no vendor_id.
        $login = $this->user('vendor');
        $admin = $this->user('admin');

        $p = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Legacy job', 'status' => 'in_progress',
            'start_date' => now()->toDateString(), 'created_by' => $admin->id,
            'link_type' => 'vendor', 'vendor_user_id' => $login->id,
        ]);

        Sanctum::actingAs($admin);

        // The drawer now (a) leaves link_type as 'vendor' because there is no
        // vendor_id to remap onto, and (b) omits the empty vendor_id. That is
        // this payload — a rename that touches nothing about the party.
        $this->putJson("/api/projects/{$p->id}", [
            'name' => 'Legacy job renamed',
            'link_type' => 'vendor', 'vendor_user_id' => $login->id,
        ])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('Legacy job renamed', $fresh->name);
        $this->assertSame('vendor', $fresh->link_type, 'the legacy type must survive');
        $this->assertSame($login->id, $fresh->vendor_user_id, 'the legacy party must survive');
    }

    public function test_the_old_drawer_payload_is_what_destroyed_the_legacy_link(): void
    {
        // Documents the defect rather than the fix: this is exactly what the
        // drawer used to send for the row above — remapped to purchase_vendor
        // with an empty vendor_id. normalizeLink reads it as "cleared".
        $login = $this->user('vendor');
        $admin = $this->user('admin');

        $p = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Legacy job', 'status' => 'in_progress',
            'start_date' => now()->toDateString(), 'created_by' => $admin->id,
            'link_type' => 'vendor', 'vendor_user_id' => $login->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/projects/{$p->id}", [
            'name' => 'Legacy job', 'link_type' => 'purchase_vendor', 'vendor_id' => '',
        ])->assertOk();

        // The party is gone — which is why the drawer must not send this.
        $this->assertNull($p->fresh()->link_type);
        $this->assertNull($p->fresh()->vendor_user_id);
    }

    public function test_a_legacy_row_that_does_carry_a_vendor_id_is_safe_to_remap(): void
    {
        // 'tpv' is a live spelling alongside 'tpv_vendor' (Project::TPV_LINK_TYPES),
        // so a row with the old spelling AND a vendor_id is a modern row — the
        // drawer remaps that one, and it round-trips.
        $tpv   = $this->tpvVendor('TPV Labour');
        $admin = $this->user('admin');

        $p = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Old spelling', 'status' => 'in_progress',
            'start_date' => now()->toDateString(), 'created_by' => $admin->id,
            'link_type' => 'tpv', 'vendor_id' => $tpv->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/projects/{$p->id}", [
            'link_type' => 'tpv_vendor', 'vendor_id' => $tpv->id,
        ])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('tpv_vendor', $fresh->link_type);
        $this->assertSame($tpv->id, $fresh->vendor_id);

        // And it is still found by the vendor filter, under either spelling.
        $this->assertTrue(
            Project::forTenant(self::TENANT)->forVendorLink($tpv->id, 'tpv_vendor')->where('id', $p->id)->exists()
        );
    }

    /* ── Both modules keep working end to end ─────────────────────────── */

    public function test_purchase_and_tpv_projects_are_created_edited_and_listed_independently(): void
    {
        $pv    = $this->purchaseVendor('Purchase Supplies');
        $tpv   = $this->tpvVendor('TPV Labour');
        $admin = $this->user('admin');

        Sanctum::actingAs($admin);

        $pid = $this->postJson('/api/projects', $this->payload([
            'name' => 'Purchase job', 'link_type' => 'purchase_vendor', 'vendor_id' => $pv->id,
        ]))->assertStatus(201)->json('data.id');

        $tid = $this->postJson('/api/projects', $this->payload([
            'name' => 'TPV job', 'link_type' => 'tpv_vendor', 'vendor_id' => $tpv->id,
        ]))->assertStatus(201)->json('data.id');

        // Editing an unrelated field leaves each link alone.
        $this->putJson("/api/projects/{$pid}", ['name' => 'Purchase job v2'])->assertOk();
        $this->assertSame('purchase_vendor', Project::find($pid)->link_type);
        $this->assertSame($pv->id, Project::find($pid)->vendor_id);

        $this->putJson("/api/projects/{$tid}", ['name' => 'TPV job v2'])->assertOk();
        $this->assertSame('tpv_vendor', Project::find($tid)->link_type);
        $this->assertSame($tpv->id, Project::find($tid)->vendor_id);

        // Each vendor screen sees only its own.
        $purchaseRows = $this->getJson("/api/projects?vendor_id={$pv->id}&vendor_type=purchase_vendor")
            ->assertOk()->json('data');
        $this->assertSame(['Purchase job v2'], array_column($purchaseRows, 'name'));

        $tpvRows = $this->getJson("/api/projects?vendor_id={$tpv->id}&vendor_type=tpv_vendor")
            ->assertOk()->json('data');
        $this->assertSame(['TPV job v2'], array_column($tpvRows, 'name'));
    }

    public function test_a_vendor_that_does_not_exist_is_refused_rather_than_stored(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/projects', $this->payload([
            'link_type' => 'purchase_vendor', 'vendor_id' => 999999,
        ]))->assertStatus(422);
    }
}
