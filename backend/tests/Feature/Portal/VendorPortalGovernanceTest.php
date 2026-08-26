<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\Tpv\TpvApproval;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §32 Vendor Portal — governance-response half. A vendor can view and respond to
 * its own NCRs/CAPAs, request approvals/extensions, and view the PPE matrix —
 * and can never touch another vendor's items.
 */
class VendorPortalGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendorWithLogin(string $name): array
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => strtolower($name).'-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    public function test_vendor_responds_to_its_own_ncr(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $ncr = TpvNcr::create([
            'tenant_id' => self::TENANT, 'reference' => 'NCR-'.Str::random(5), 'vendor_id' => $vendor->id,
            'title' => 'Missing PPE', 'severity' => 'Major', 'status' => 'Raised',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/portal/ncrs')->assertOk()->assertJsonPath('data.0.id', $ncr->id);

        $this->postJson("/api/portal/ncrs/{$ncr->id}/respond", ['response' => 'Rectified on site'])
            ->assertOk()->assertJsonPath('status', 'Response')->assertJsonPath('response', 'Rectified on site');
    }

    public function test_vendor_cannot_respond_to_another_vendors_ncr(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $ncrB = TpvNcr::create([
            'tenant_id' => self::TENANT, 'reference' => 'NCR-'.Str::random(5), 'vendor_id' => $vendorB->id,
            'title' => 'B issue', 'severity' => 'Minor', 'status' => 'Raised',
        ]);

        Sanctum::actingAs($userA);
        $this->postJson("/api/portal/ncrs/{$ncrB->id}/respond", ['response' => 'x'])->assertStatus(404);
        $this->assertNull($ncrB->fresh()->response);
    }

    public function test_vendor_submits_capa_evidence_note(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $capa = TpvCapa::create([
            'tenant_id' => self::TENANT, 'reference' => 'CAPA-'.Str::random(5), 'vendor_id' => $vendor->id,
            'title' => 'Corrective', 'status' => 'Open',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/capas/{$capa->id}/evidence", ['note' => 'Photos attached'])
            ->assertOk()->assertJsonPath('verification_notes', 'Photos attached');
    }

    public function test_vendor_requests_approval_and_extension(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        Sanctum::actingAs($user);

        $this->postJson('/api/portal/approvals/request', ['title' => 'New scope sign-off'])
            ->assertStatus(201);
        $this->postJson('/api/portal/extensions/request', ['reason' => 'Need 2 more weeks'])
            ->assertStatus(201);

        $this->assertSame(1, TpvApproval::where('vendor_id', $vendor->id)->where('approval_type', 'other')->count());
        $this->assertSame(1, TpvApproval::where('vendor_id', $vendor->id)->where('approval_type', 'extension')->count());
    }

    public function test_vendor_views_the_ppe_matrix(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        TpvPpeRequirement::create([
            'tenant_id' => self::TENANT, 'scope_type' => 'designation', 'scope_value' => 'Welder',
            'hazard' => 'Arc/Heat', 'activity' => 'Welding', 'ppe_class' => 'mandatory',
            'product_id' => 1, 'qty' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/portal/ppe-matrix')->assertOk()
            ->assertJsonPath('rules.0.scope_value', 'Welder')
            ->assertJsonPath('rules.0.hazard', 'Arc/Heat');
    }
}
