<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §14 Workforce — a worker records years of experience, a joining date and an
 * exit date. These persist through the worker service like any other profile field.
 */
class WorkerEmploymentFieldsTest extends TestCase
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
            'email' => 'admin-'.Str::random(6).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    public function test_employment_fields_persist_on_create(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        $worker = app(TpvWorkerService::class)->create([
            'tenant_id'        => self::TENANT,
            'vendor_id'        => $v->id,
            'name'             => 'Ravi',
            'designation'      => 'Welder',
            'experience_years' => 5.5,
            'joining_date'     => '2026-01-15',
            'exit_date'        => '2026-08-01',
        ], $this->admin());

        $fresh = $worker->fresh();
        $this->assertSame('5.5', (string) $fresh->experience_years);
        $this->assertSame('2026-01-15', $fresh->joining_date->toDateString());
        $this->assertSame('2026-08-01', $fresh->exit_date->toDateString());
    }

    public function test_employment_fields_update(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $worker = app(TpvWorkerService::class)->create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi', 'designation' => 'Welder',
        ], $this->admin());

        app(TpvWorkerService::class)->update($worker, ['experience_years' => 10, 'exit_date' => '2027-03-31'], $this->admin());

        $fresh = $worker->fresh();
        $this->assertSame('10.0', (string) $fresh->experience_years);
        $this->assertSame('2027-03-31', $fresh->exit_date->toDateString());
    }
}
