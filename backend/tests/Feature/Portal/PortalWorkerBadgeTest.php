<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The TPV vendor portal can VIEW a worker's admin-issued badge (read-only), and
 * while the badge is not issued yet, see exactly what is blocking it. Issuing a
 * badge stays an admin decision — the portal never issues one.
 */
class PortalWorkerBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE, 'email' => 'acme@test.local']);
        $user = User::create(['tenant_id' => self::TENANT, 'name' => 'Acme Portal', 'role' => 'third_party_vendor', 'email' => 'acme@test.local', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->vendor->update(['user_id' => $user->id]);
    }

    private function worker(array $attrs): TpvWorker
    {
        $w = new TpvWorker();
        $w->forceFill(array_merge(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id, 'name' => 'W', 'status' => 'Draft', 'current_step' => 1], $attrs))->save();

        return $w;
    }

    public function test_vendor_sees_an_issued_badge_with_its_credential(): void
    {
        $w = $this->worker(['name' => 'Badged', 'status' => 'Active', 'current_step' => 5, 'badge_number' => 'BDG-2026-002', 'qr_token' => Str::random(48), 'badge_valid_until' => '2027-01-01']);

        Sanctum::actingAs($this->vendor->user);
        $res = $this->getJson("/api/portal/workers/{$w->id}/badge")
            ->assertOk()
            ->assertJsonPath('activated', true)
            ->assertJsonPath('badge_number', 'BDG-2026-002')
            ->assertJsonPath('blockers', []);
        $this->assertNotEmpty($res->json('qr_token'));
    }

    public function test_unissued_badge_reports_its_blockers(): void
    {
        // Draft worker missing statutory fields → blockers, no credential.
        $w = $this->worker(['name' => 'Incomplete', 'status' => 'Draft']);

        Sanctum::actingAs($this->vendor->user);
        $res = $this->getJson("/api/portal/workers/{$w->id}/badge")->assertOk()
            ->assertJsonPath('activated', false)
            ->assertJsonPath('qr_token', null);
        $this->assertNotEmpty($res->json('blockers'));
    }

    public function test_another_vendors_worker_is_unreachable(): void
    {
        $other = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Beta', 'status' => VendorStatus::ACTIVE, 'email' => 'beta@test.local']);
        $w = new TpvWorker();
        $w->forceFill(['tenant_id' => self::TENANT, 'vendor_id' => $other->id, 'name' => 'Theirs', 'status' => 'Active', 'current_step' => 5, 'badge_number' => 'BDG-X'])->save();

        Sanctum::actingAs($this->vendor->user);
        $this->getJson("/api/portal/workers/{$w->id}/badge")->assertStatus(404);
    }
}
