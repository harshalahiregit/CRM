<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvApproval;
use App\Models\Tpv\TpvWorkPackage;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvAccessService;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Tpv\ApprovalType;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §13 Worker → Activity link, §14 lifecycle/trade/training_status capture,
 * §11 Temporary-vendor engagement fields + approval-on-creation/extension.
 */
class WorkerActivityAndTempVendorFieldsTest extends TestCase
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

    public function test_worker_activity_link_scoped_to_its_work_package(): void
    {
        $v  = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $wp = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'WP-1', 'status' => 'Active']);
        $act = TpvActivity::create(['tenant_id' => self::TENANT, 'work_package_id' => $wp->id, 'name' => 'Welding']);

        $worker = app(TpvWorkerService::class)->create([
            'vendor_id' => $v->id, 'name' => 'Ravi',
            'work_package_id' => $wp->id, 'activity_id' => $act->id,
            'trade' => 'Welder', 'lifecycle_state' => 'Verification', 'training_status' => 'Pending',
        ], $this->admin());

        $fresh = $worker->fresh();
        $this->assertSame($act->id, $fresh->activity_id);
        $this->assertSame('Welder', $fresh->trade);
        $this->assertSame('Verification', $fresh->lifecycle_state);
        $this->assertSame($worker->id, $act->workers()->first()->id);
    }

    public function test_activity_from_a_different_work_package_is_rejected(): void
    {
        $v   = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $wp1 = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'WP-1', 'status' => 'Active']);
        $wp2 = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'WP-2', 'status' => 'Active']);
        $actOfWp2 = TpvActivity::create(['tenant_id' => self::TENANT, 'work_package_id' => $wp2->id, 'name' => 'Rigging']);

        $this->expectException(\App\Exceptions\BusinessException::class);
        app(TpvWorkerService::class)->create([
            'vendor_id' => $v->id, 'name' => 'Mismatch',
            'work_package_id' => $wp1->id, 'activity_id' => $actOfWp2->id,
        ], $this->admin());
    }

    public function test_temp_vendor_capture_and_approval_on_creation(): void
    {
        $result = app(TpvAccessService::class)->createTemporary([
            'company_name'   => 'TempCo',
            'email'          => 'temp-'.Str::random(6).'@vendor.local',
            'validity_days'  => 7,
            'temp_purpose'   => 'Shutdown support',
            'temp_sponsor'   => 'Ops Head',
            'temp_project'   => 'Turnaround 2026',
            'temp_scope'     => 'Scaffolding',
            'temp_workforce' => 25,
            'temp_risk_level' => 'High',
            'temp_required_documents' => ['GST', 'PF'],
        ], $this->admin());

        $vendor = $result['vendor'];
        $this->assertSame('Shutdown support', $vendor->temp_purpose);
        $this->assertSame(25, $vendor->temp_workforce);
        $this->assertSame(['GST', 'PF'], $vendor->temp_required_documents);

        $approval = TpvApproval::where('vendor_id', $vendor->id)
            ->where('approval_type', ApprovalType::TEMPORARY_VENDOR)->first();
        $this->assertNotNull($approval, 'A temporary-vendor approval must be raised on creation.');
        $this->assertSame('High', $approval->priority);
    }
}
