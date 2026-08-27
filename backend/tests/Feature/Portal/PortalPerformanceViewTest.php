<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Performance section — a TPV vendor sees its OWN risk score and violations
 * (read-only), never another vendor's, and never a write path.
 */
class PortalPerformanceViewTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function portalVendor(string $name = 'Acme'): Vendor
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => $name, 'status' => VendorStatus::ACTIVE, 'email' => strtolower($name).'@test.local']);
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name.' Portal', 'role' => 'third_party_vendor',
            'email' => strtolower($name).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor->update(['user_id' => $user->id]);

        return $vendor->fresh();
    }

    public function test_vendor_sees_its_own_risk_score(): void
    {
        $vendor = $this->portalVendor();
        $vendor->update(['risk_level' => 'High', 'risk_score' => 65, 'risk_assessed_at' => now()]);

        Sanctum::actingAs($vendor->user);
        $this->getJson('/api/portal/risk')
            ->assertOk()
            ->assertJsonPath('assessed', true)
            ->assertJsonPath('level', 'High')
            ->assertJsonPath('score', 65);
    }

    public function test_unassessed_vendor_reports_not_assessed(): void
    {
        $vendor = $this->portalVendor();

        Sanctum::actingAs($vendor->user);
        $this->getJson('/api/portal/risk')->assertOk()->assertJsonPath('assessed', false);
    }

    public function test_vendor_sees_only_its_own_violations(): void
    {
        $mine  = $this->portalVendor('Acme');
        $other = $this->portalVendor('BetaCo');

        TpvVendorViolation::create(['tenant_id' => self::TENANT, 'reference' => 'V-1', 'vendor_id' => $mine->id, 'type' => 'Safety', 'severity' => 'Major', 'occurred_at' => now(), 'points' => 5, 'status' => 'Open']);
        TpvVendorViolation::create(['tenant_id' => self::TENANT, 'reference' => 'V-2', 'vendor_id' => $other->id, 'type' => 'Quality', 'severity' => 'Minor', 'occurred_at' => now(), 'points' => 2, 'status' => 'Open']);

        Sanctum::actingAs($mine->user);
        $res = $this->getJson('/api/portal/violations')->assertOk();
        $res->assertJsonPath('total_points', 5)->assertJsonPath('open_count', 1);
        $refs = collect($res->json('data'))->pluck('reference')->all();
        $this->assertSame(['V-1'], $refs);
    }
}
