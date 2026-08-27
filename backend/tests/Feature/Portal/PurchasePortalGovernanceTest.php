<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseApprovalRequest;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §32 Purchase Vendor Portal — governance-response half (separate DB/models).
 * Mirrors the TPV portal: a Purchase vendor views + responds to its own
 * NCRs/CAPAs and requests approvals/extensions, scoped to itself.
 */
class PurchasePortalGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendor(string $name = 'Bolt Supplies'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active',
        ]);
    }

    public function test_vendor_responds_to_its_own_ncr(): void
    {
        $v = $this->vendor();
        $ncr = PurchaseNcr::create([
            'tenant_id' => self::TENANT, 'reference' => 'PNCR-'.uniqid(), 'purchase_vendor_id' => $v->id,
            'title' => 'Late delivery', 'severity' => 'Major', 'status' => 'Raised',
        ]);

        Sanctum::actingAs($v);
        $this->getJson('/api/portal/purchase/ncrs')->assertOk()->assertJsonPath('data.0.id', $ncr->id);
        $this->postJson("/api/portal/purchase/ncrs/{$ncr->id}/respond", ['response' => 'Expedited'])
            ->assertOk()->assertJsonPath('status', 'Response');
    }

    public function test_vendor_cannot_respond_to_another_vendors_ncr(): void
    {
        $a = $this->vendor('AlphaCo');
        $b = $this->vendor('BetaCo');
        $ncrB = PurchaseNcr::create([
            'tenant_id' => self::TENANT, 'reference' => 'PNCR-'.uniqid(), 'purchase_vendor_id' => $b->id,
            'title' => 'B', 'severity' => 'Minor', 'status' => 'Raised',
        ]);

        Sanctum::actingAs($a);
        $this->postJson("/api/portal/purchase/ncrs/{$ncrB->id}/respond", ['response' => 'x'])->assertStatus(404);
    }

    public function test_vendor_submits_capa_evidence(): void
    {
        $v = $this->vendor();
        $capa = PurchaseCapa::create([
            'tenant_id' => self::TENANT, 'reference' => 'PCAPA-'.uniqid(), 'purchase_vendor_id' => $v->id,
            'title' => 'Fix', 'status' => 'Open',
        ]);

        Sanctum::actingAs($v);
        $this->postJson("/api/portal/purchase/capas/{$capa->id}/evidence", ['note' => 'Done, photos attached'])
            ->assertOk()->assertJsonPath('verification_notes', 'Done, photos attached');
    }

    public function test_vendor_requests_approval_and_extension(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($v);

        $this->postJson('/api/portal/purchase/approvals/request', ['title' => 'Scope change'])->assertStatus(201);
        $this->postJson('/api/portal/purchase/extensions/request', ['reason' => 'Two more weeks'])->assertStatus(201);

        $this->assertSame(1, PurchaseApprovalRequest::where('purchase_vendor_id', $v->id)->where('approval_type', 'other')->count());
        $this->assertSame(1, PurchaseApprovalRequest::where('purchase_vendor_id', $v->id)->where('approval_type', 'extension')->count());
    }
}
