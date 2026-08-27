<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tpv\TpvSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §34 — admin-configurable catalogues (vendor types/categories/risk levels/
 * document/training/competency/permit/violation/compliance) via TpvSettings.
 */
class CatalogsSettingsTest extends TestCase
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

    public function test_baseline_catalogs_are_present(): void
    {
        $catalogs = app(TpvSettings::class)->catalogs(self::TENANT);

        foreach (['vendor_types', 'vendor_categories', 'risk_levels', 'document_types',
                  'training_types', 'competency_requirements', 'permit_types', 'violation_types'] as $c) {
            $this->assertArrayHasKey($c, $catalogs, "Catalog '{$c}' must exist (§34).");
        }
    }

    public function test_admin_can_override_a_catalog_and_engine_reads_it(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/tpv/settings/catalogs', [
            'document_types' => ['GST', 'PAN', 'Custom Cert'],
        ])->assertOk();

        // A fresh resolver (memo not shared) sees the override.
        $catalogs = app()->make(TpvSettings::class)->catalog('document_types', self::TENANT);
        $this->assertContains('Custom Cert', $catalogs);
    }
}
