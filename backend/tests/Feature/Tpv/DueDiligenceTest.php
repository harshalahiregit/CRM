<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvDueDiligence;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §7 Risk & Due Diligence — a per-vendor verification checklist that rolls up to
 * Cleared/Rejected, plus the doc's Legal/Cyber/Reputational/Environmental risk
 * dimensions in the scoring catalogue.
 */
class DueDiligenceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
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

    public function test_saving_checks_derives_cleared_when_all_verified(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $payload = [];
        foreach (TpvDueDiligence::CHECKS as $c) {
            $payload[$c] = 'Verified';
        }
        $payload['reference_check'] = 'Not_Applicable';

        $res = $this->putJson("/api/tpv/vendors/{$vendor->id}/due-diligence", $payload)->assertOk();
        $this->assertSame('Cleared', $res->json('status'));
    }

    public function test_a_failed_check_rejects_the_record(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/tpv/vendors/{$vendor->id}/due-diligence", [
            'company_verification' => 'Verified',
            'background_check'     => 'Failed',
        ])->assertOk()->assertJsonPath('status', 'Rejected');
    }

    public function test_show_returns_the_catalogue(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/tpv/vendors/{$vendor->id}/due-diligence")
            ->assertOk()
            ->assertJsonPath('checks.0', 'company_verification');
    }

    public function test_risk_catalogue_carries_the_new_dimensions(): void
    {
        $factors = config('vendor_risk.factors');
        foreach (['legal', 'cyber_data', 'reputational', 'environmental'] as $dim) {
            $this->assertArrayHasKey($dim, $factors, "Risk factor '{$dim}' must exist (§7).");
        }
    }
}
