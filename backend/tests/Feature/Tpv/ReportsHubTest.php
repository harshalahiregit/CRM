<?php

namespace Tests\Feature\Tpv;

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
 * §33 Reports & Analytics hub — the doc's named reports enumerated, plus the new
 * operational CSV exports (workforce/gate/ppe/training/medical/strikes/incidents).
 */
class ReportsHubTest extends TestCase
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

    public function test_reports_hub_enumerates_named_reports(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->getJson('/api/tpv/reports')->assertOk();
        $names = collect($res->json('reports'))->pluck('name');

        foreach (['Workforce Report', 'Gate Log Report', 'PPE Issuance Report', 'Training Report',
                  'Medical Fitness Report', 'Strikes & Violations Report', 'Incident Report'] as $expected) {
            $this->assertTrue($names->contains($expected), "Reports hub must list '{$expected}'.");
        }
    }

    public function test_workforce_export_returns_csv(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi',
            'designation' => 'Welder', 'trade' => 'Welding', 'status' => 'Draft', 'current_step' => 1,
        ]);

        Sanctum::actingAs($this->admin());

        $res = $this->get('/api/tpv/analytics/export?dataset=workers');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('Ravi', $res->getContent());
    }

    public function test_new_datasets_are_all_exportable(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['gate', 'ppe', 'training', 'medical', 'strikes', 'incidents'] as $ds) {
            $this->get("/api/tpv/analytics/export?dataset={$ds}")->assertOk();
        }
    }
}
