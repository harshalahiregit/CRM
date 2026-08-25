<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvCapaService;
use App\Services\Tpv\TpvNcrService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 11 — "Every Action Has an Owner" (Sangoe TPV §36). A CAPA cannot be worked
 * or closed while unassigned; an NCR cannot move past "Raised" without a
 * responsible person. Enforced in the two services' transition() methods.
 */
class ActionOwnerRuleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    /* ── CAPA ── */

    public function test_capa_cannot_progress_without_an_owner(): void
    {
        $capa = TpvCapa::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor()->id, 'title' => 'Fix guardrail', 'type' => 'Corrective', 'status' => 'Open']);
        $this->expectException(BusinessException::class);
        app(TpvCapaService::class)->transition($capa, 'In_Progress', $this->actor());
    }

    public function test_capa_progresses_once_assigned(): void
    {
        $owner = $this->actor();
        $capa = TpvCapa::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor()->id, 'title' => 'Fix guardrail', 'type' => 'Corrective', 'status' => 'Open', 'assigned_to' => $owner->id]);
        $out = app(TpvCapaService::class)->transition($capa, 'In_Progress', $owner);
        $this->assertSame('In_Progress', $out->status);
    }

    public function test_assigned_capa_still_needs_evidence_to_verify(): void
    {
        $owner = $this->actor();
        $capa = TpvCapa::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor()->id, 'title' => 'Fix', 'type' => 'Corrective', 'status' => 'Open', 'assigned_to' => $owner->id]);
        // owner present, but Rule 12 (evidence) still applies
        $this->expectException(BusinessException::class);
        app(TpvCapaService::class)->transition($capa, 'Verified', $owner);
    }

    /* ── NCR ── */

    public function test_ncr_cannot_be_assigned_without_a_responsible_person(): void
    {
        $ncr = TpvNcr::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor()->id, 'title' => 'Missing MS', 'severity' => 'Major', 'status' => 'Raised']);
        $this->expectException(BusinessException::class);
        app(TpvNcrService::class)->transition($ncr, 'Assigned', $this->actor());
    }

    public function test_ncr_progresses_once_a_responsible_person_is_set(): void
    {
        $owner = $this->actor();
        $ncr = TpvNcr::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor()->id, 'title' => 'Missing MS', 'severity' => 'Major', 'status' => 'Raised', 'responsible_by' => $owner->id]);
        $out = app(TpvNcrService::class)->transition($ncr, 'Assigned', $owner);
        $this->assertSame('Assigned', $out->status);
    }
}
