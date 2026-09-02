<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-3 — "No Medical, No Training". Induction/training cannot be recorded until
 * the worker has a passed, current medical — unless medical is skipped for the
 * site. Mirrors the badge blockers so the two gates agree.
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

    public function test_training_allowed_with_a_fit_medical(): void
    {
        $w = $this->worker();
        $this->svc->saveMedical($w, ['exam_type' => 'internal', 'fitness_status' => 'Fit', 'examiner_name' => 'Dr A'], $this->actor);

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
