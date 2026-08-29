<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Models\Tenant;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase worker Medical + Training brought to TPV depth.
 *
 * A "Fit with Restrictions" verdict must PASS medical readiness (parity with
 * TpvMedicalFitness::PASSING), training must be typed and honour a currency
 * window, and a lapsed medical or training must fail readiness / withhold the
 * step. current_step holds the HIGHEST step completed, so a passing medical
 * advances to step 2 while an Unfit or expired one does not.
 */
class PurchaseMedicalTrainingDepthTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseWorkforceService $wf;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();

        $this->wf = app(PurchaseWorkforceService::class);
    }

    private function vendor(string $n): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $n,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($n).'@test.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function worker(PurchaseVendor $v, string $n = 'Worker'): PurchaseWorker
    {
        return $this->wf->create($v, [
            'full_name' => $n, 'dob' => '1990-01-01', 'designation' => 'Fitter', 'phone' => '9990000000',
        ]);
    }

    /* ── Medical ────────────────────────────────────────────────────────── */

    public function test_fit_with_restrictions_passes_medical_readiness_and_advances(): void
    {
        $w = $this->worker($this->vendor('RestrictCo'));

        $m = $this->wf->saveMedical($w, [
            'fitness_status' => 'Fit_With_Restrictions',
            'exam_date'      => now()->toDateString(),
            'expiry_date'    => now()->addYear()->toDateString(),
            'restrictions'   => 'No work at height',
            'examiner_name'  => 'Dr Rao',
        ]);

        // Persisted depth fields.
        $this->assertSame('No work at height', $m->fresh()->restrictions);
        $this->assertSame('Dr Rao', $m->fresh()->examiner_name);
        $this->assertTrue($m->fresh()->isPassing());

        // Readiness accepts the restricted verdict and the step advances.
        $this->assertTrue($this->wf->readiness($w->fresh())['medical_ok']);
        $this->assertSame(2, (int) $w->fresh()->current_step);
    }

    public function test_unfit_medical_fails_readiness(): void
    {
        $w = $this->worker($this->vendor('UnfitCo'));

        $this->wf->saveMedical($w, ['fitness_status' => 'Unfit', 'exam_date' => now()->toDateString()]);

        $this->assertFalse($this->wf->readiness($w->fresh())['medical_ok']);
        $this->assertSame(1, (int) $w->fresh()->current_step);
    }

    public function test_expired_medical_fails_readiness(): void
    {
        $w = $this->worker($this->vendor('LapsedMedCo'));

        // A passing verdict, but the certificate has already lapsed.
        $this->wf->saveMedical($w, [
            'fitness_status' => 'Fit',
            'exam_date'      => now()->subYears(2)->toDateString(),
            'expiry_date'    => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->wf->readiness($w->fresh())['medical_ok']);
    }

    /* ── Training ───────────────────────────────────────────────────────── */

    public function test_typed_training_persists_its_type_and_passes_readiness(): void
    {
        $w = $this->worker($this->vendor('TypedCo'));

        $t = $this->wf->saveTraining($w, [
            'training_type' => 'Work_At_Height',
            'status'        => 'Completed',
            'training_date' => now()->toDateString(),
            'valid_until'   => now()->addYear()->toDateString(),
        ]);

        $this->assertSame('Work_At_Height', $t->fresh()->training_type);
        $this->assertSame('Valid', $t->fresh()->derived_status);
        $this->assertTrue($this->wf->readiness($w->fresh())['training_ok']);
    }

    public function test_untyped_free_text_training_still_persists_and_counts(): void
    {
        $w = $this->worker($this->vendor('UntypedCo'));

        $t = $this->wf->saveTraining($w, [
            'title'  => 'Legacy Safety Course',
            'status' => 'Completed',
        ]);

        $this->assertSame('Legacy Safety Course', $t->fresh()->title);
        $this->assertNull($t->fresh()->training_type);
        $this->assertTrue($this->wf->readiness($w->fresh())['training_ok']);
    }

    public function test_expired_training_fails_readiness(): void
    {
        $w = $this->worker($this->vendor('LapsedTrainCo'));

        $t = $this->wf->saveTraining($w, [
            'training_type' => 'Fire',
            'status'        => 'Completed',
            'training_date' => now()->subYears(2)->toDateString(),
            'valid_until'   => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('Expired', $t->fresh()->derived_status);
        $this->assertFalse($this->wf->readiness($w->fresh())['training_ok']);
    }

    public function test_unknown_training_type_is_normalised_to_other(): void
    {
        $w = $this->worker($this->vendor('OddCo'));

        $t = $this->wf->saveTraining($w, [
            'training_type' => 'Nonsense',
            'title'         => 'Something',
            'status'        => 'Completed',
        ]);

        $this->assertSame('Other', $t->fresh()->training_type);
        $this->assertContains($t->fresh()->training_type, PurchaseWorkerTraining::TYPES);
    }
}
