<?php

namespace Tests\Feature\Vendor;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1 wiring — the master context fields (project / department / site) have always
 * had columns + fillable, but were missing from UpdateVendorRequest so the master
 * screen could not persist them. This proves the update path now saves them; and
 * `project` / `site` are what the onboarding checklist matches its dimensions on.
 */
class VendorMasterEditFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_update_persists_project_department_and_site(): void
    {
        $actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::DRAFT]);

        app(VendorService::class)->update($vendor, [
            'project' => 'New Factory Electrical',
            'department' => 'Projects',
            'site' => 'Block A',
        ], $actor);

        $fresh = $vendor->fresh();
        $this->assertSame('New Factory Electrical', $fresh->project);
        $this->assertSame('Projects', $fresh->department);
        $this->assertSame('Block A', $fresh->site);
    }

    public function test_update_request_allows_the_new_fields(): void
    {
        $rules = (new \App\Http\Requests\Vendor\UpdateVendorRequest())->rules();
        $this->assertArrayHasKey('project', $rules);
        $this->assertArrayHasKey('department', $rules);
        $this->assertArrayHasKey('site', $rules);
        $this->assertArrayHasKey('client_id', $rules);
    }
}
