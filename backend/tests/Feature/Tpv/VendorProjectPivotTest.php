<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §35 — an explicit vendor↔project pivot (TPV-local): attach, list, detach.
 */
class VendorProjectPivotTest extends TestCase
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

    public function test_attach_and_list_projects(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/tpv/vendors/{$vendor->id}/projects", [
            'project' => 'Refinery TA-2026', 'role' => 'Main contractor',
        ])->assertStatus(201)->assertJsonPath('project', 'Refinery TA-2026');

        $this->getJson("/api/tpv/vendors/{$vendor->id}/projects")
            ->assertOk()->assertJsonPath('data.0.role', 'Main contractor');
    }
}
