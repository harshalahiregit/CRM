<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkPackage;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvMedicalWorkflowService;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-3 — "No Medical, No Training", as the Medical module now enforces it.
 *
 * Induction cannot be recorded until the worker has medical CLEARANCE, which is
 * a passed, current certificate the quality team has approved. A signed but
 * unreviewed certificate deliberately does not clear the gate — that is the
 * whole point of the quality check.
 *
 * It stops applying where medical is not applicable: the legacy per-worker skip,
 * or the project-level bypass on the worker's work package.
 */
class MedicalGatesTrainingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private TpvWorkerService $svc;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->svc = app(TpvWorkerService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Trainer', 'role' => 'admin',
            'email' => 't-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(): TpvWorker
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return TpvWorker::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi', 'current_step' => 1, 'status' => 'Draft']);
    }

    private function induct(TpvWorker $w): TpvWorker
    {
        return $this->svc->saveInduction($w, ['induction_type' => 'General Safety', 'trainer' => 'Coach'], $this->actor);
    }

    public function test_training_blocked_without_a_medical(): void
    {
        $this->expectException(BusinessException::class);
        $this->induct($this->worker());
    }

    public function test_training_blocked_when_medical_is_unfit(): void
    {
        $w = $this->worker();
        $this->svc->saveMedical($w, ['exam_type' => 'internal', 'fitness_status' => 'Unfit', 'examiner_name' => 'Dr A'], $this->actor);

        $this->expectException(BusinessException::class);
        $this->induct($w->fresh());
    }

    public function test_training_blocked_while_the_medical_awaits_quality_check(): void
    {
        $w = $this->worker();
        $this->svc->saveMedical($w, ['exam_type' => 'internal', 'fitness_status' => 'Fit', 'examiner_name' => 'Dr A'], $this->actor);

        // Fit, current — but nobody has reviewed it yet.
        $this->expectException(BusinessException::class);
        $this->induct($w->fresh());
    }

    public function test_training_allowed_once_the_medical_is_approved(): void
    {
        $w = $this->worker();
        $this->svc->saveMedical($w, ['exam_type' => 'internal', 'fitness_status' => 'Fit', 'examiner_name' => 'Dr A'], $this->actor);

        app(TpvMedicalWorkflowService::class)->decide(
            $w->fresh()->medical, MedicalQcStatus::APPROVED, [], $this->actor
        );

        $out = $this->induct($w->fresh());
        $this->assertNotNull($out->fresh('induction')->induction);
    }

    public function test_training_blocked_after_a_rejection(): void
    {
        $w = $this->worker();
        $this->svc->saveMedical($w, ['exam_type' => 'internal', 'fitness_status' => 'Fit', 'examiner_name' => 'Dr A'], $this->actor);

        app(TpvMedicalWorkflowService::class)->decide(
            $w->fresh()->medical, MedicalQcStatus::REJECTED,
            ['reason_code' => 'age_limit'], $this->actor
        );

        $this->expectException(BusinessException::class);
        $this->induct($w->fresh());
    }

    public function test_training_allowed_when_the_project_marks_medical_not_applicable(): void
    {
        $w  = $this->worker();
        $wp = TpvWorkPackage::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $w->vendor_id,
            'name' => 'Civil works', 'medical_not_applicable' => true,
        ]);
        $w->forceFill(['work_package_id' => $wp->id])->save();

        // No examination at all — and none is needed on this project.
        $out = $this->induct($w->fresh());
        $this->assertNotNull($out->fresh('induction')->induction);
    }

    public function test_training_allowed_when_medical_is_skipped(): void
    {
        $w = $this->worker();
        $w->update(['medical_status' => 2, 'medical_type' => 'skip']);

        $out = $this->induct($w->fresh());
        $this->assertNotNull($out->fresh('induction')->induction);
    }
}
