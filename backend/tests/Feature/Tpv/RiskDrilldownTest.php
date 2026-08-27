<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\HsseIncident;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §4 — Control Tower risk drill-down by Vendor / Project / Site / Department /
 * Work Package / Risk Category, using the TPV-local dimension fields.
 */
class RiskDrilldownTest extends TestCase
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

    public function test_drilldown_by_project_groups_vendors_and_incidents(): void
    {
        Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'A', 'status' => VendorStatus::ACTIVE, 'project' => 'Refinery', 'risk_level' => 'High']);
        Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'B', 'status' => VendorStatus::ACTIVE, 'project' => 'Refinery', 'risk_level' => 'Low']);
        Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'C', 'status' => VendorStatus::ACTIVE, 'project' => 'Township', 'risk_level' => 'Medium']);

        HsseIncident::create([
            'tenant_id' => self::TENANT, 'reference' => 'INC-'.uniqid(), 'title' => 'Slip',
            'type' => 'First_Aid', 'severity' => 'Minor', 'status' => 'Reported', 'project' => 'Refinery',
        ]);

        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/tpv/dashboard/risk-drilldown?dimension=project')->assertOk();

        $groups = collect($res->json('groups'))->keyBy('group');
        $this->assertSame(2, $groups['Refinery']['vendors']);
        $this->assertSame(1, $groups['Refinery']['risk']['High']);
        $this->assertSame(1, $groups['Refinery']['open_incidents']);
        $this->assertSame(1, $groups['Township']['vendors']);
    }

    public function test_invalid_dimension_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/tpv/dashboard/risk-drilldown?dimension=bogus')->assertStatus(422);
    }
}
