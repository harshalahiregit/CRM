<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorkPackage;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerCompetency;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 4 — "No Competency, No Work Authorization" (Sangoe TPV §15/§19/§36).
 *
 * A worker deployed on a work package must hold every competency the package's
 * activities name, or they cannot be badged (the Gate Pass stage). This guards
 * that the rule is ENFORCED at `blockers()`, not merely displayed — and that a
 * worker can only be assigned to a work package of their own vendor.
 */
class CompetencyGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    private function packageRequiring(Vendor $vendor, string $competency): TpvWorkPackage
    {
        $wp = TpvWorkPackage::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Electrical WP', 'status' => 'Active',
        ]);
        TpvActivity::create([
            'tenant_id' => self::TENANT, 'work_package_id' => $wp->id, 'name' => 'Cable Installation',
            'required_competency' => $competency, 'status' => 'Active', 'sort_order' => 1,
        ]);

        return $wp;
    }

    private function worker(Vendor $vendor, ?int $workPackageId): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'work_package_id' => $workPackageId,
            'name' => 'Ravi', 'designation' => 'Electrician', 'status' => 'Draft',
        ]);
    }

    public function test_worker_lacking_required_competency_is_blocked(): void
    {
        $v = $this->vendor();
        $wp = $this->packageRequiring($v, 'Electrical Licence');
        $worker = $this->worker($v, $wp->id);

        $blockers = app(TpvWorkerService::class)->blockers($worker);

        $this->assertTrue(
            collect($blockers)->contains(fn ($b) => str_contains($b, 'Competency missing') && str_contains($b, 'Electrical Licence')),
            'Expected a competency blocker for the missing "Electrical Licence". Got: '.json_encode($blockers)
        );
    }

    public function test_worker_holding_the_competency_has_no_competency_blocker(): void
    {
        $v = $this->vendor();
        $wp = $this->packageRequiring($v, 'Electrical Licence');
        $worker = $this->worker($v, $wp->id);

        // A valid, non-expired matching competency clears Rule 4.
        TpvWorkerCompetency::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id, 'name' => 'Electrical Licence (HT)',
            'category' => 'Licence', 'valid_until' => now()->addYear()->toDateString(),
        ]);

        $blockers = app(TpvWorkerService::class)->blockers($worker->fresh());

        $this->assertFalse(
            collect($blockers)->contains(fn ($b) => str_contains($b, 'Competency missing')),
            'Competency should be satisfied. Got: '.json_encode($blockers)
        );
    }

    public function test_expired_competency_still_blocks(): void
    {
        $v = $this->vendor();
        $wp = $this->packageRequiring($v, 'Electrical Licence');
        $worker = $this->worker($v, $wp->id);
        TpvWorkerCompetency::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id, 'name' => 'Electrical Licence',
            'category' => 'Licence', 'valid_until' => now()->subDay()->toDateString(),
        ]);

        $blockers = app(TpvWorkerService::class)->blockers($worker->fresh());
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'Competency missing')));
    }

    public function test_worker_without_a_work_package_has_no_competency_blocker(): void
    {
        $v = $this->vendor();
        $worker = $this->worker($v, null);

        $blockers = app(TpvWorkerService::class)->blockers($worker);
        $this->assertFalse(collect($blockers)->contains(fn ($b) => str_contains($b, 'Competency missing')));
    }

    public function test_cannot_assign_worker_to_another_vendors_work_package(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $otherWp = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $other->id, 'name' => 'Other WP', 'status' => 'Active']);
        $worker = $this->worker($mine, null);

        $this->expectException(BusinessException::class);
        app(TpvWorkerService::class)->update($worker, ['work_package_id' => $otherWp->id], $this->admin());
    }
}
