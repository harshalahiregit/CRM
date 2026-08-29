<?php

namespace Tests\Feature\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerCompetency;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseCompetencyService;
use App\Services\Purchase\PurchaseSettingService;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "No Competency, No Work" for the Purchase workforce (mirror of TPV Rule 4 / §15).
 *
 * Purchase carries no work-package/activity model, so the required-competency
 * source is the tenant Settings key `workforce_required_competencies`. This test
 * guards that the rule is ENFORCED at readiness()/activateBadge() (not merely
 * displayed), that it degrades gracefully when nothing is required, and that the
 * CRUD + skillMatrix + workerHasCompetency service surface behaves.
 */
class PurchaseCompetencyGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseWorkforceService $wf;
    private PurchaseCompetencyService $comp;
    private PurchaseSettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('purchase_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();

        $this->wf       = app(PurchaseWorkforceService::class);
        $this->comp     = app(PurchaseCompetencyService::class);
        $this->settings = app(PurchaseSettingService::class);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $n = 'Acme'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $n,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($n).'-'.Str::random(4).'@test.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function worker(PurchaseVendor $v, string $n = 'Worker'): PurchaseWorker
    {
        return $this->wf->create($v, [
            'full_name' => $n, 'dob' => '1990-01-01', 'designation' => 'Fitter', 'phone' => '9990000000',
        ]);
    }

    /** Docs + Fit medical + completed training + completed induction — all four base checks pass. */
    private function baseReadyWorker(PurchaseVendor $v): PurchaseWorker
    {
        $w = $this->worker($v);
        $this->wf->addDocument($w, 'id_proof', UploadedFile::fake()->create('id.pdf', 5));
        $this->wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        $this->wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Completed']);
        $this->wf->saveInduction($w, ['status' => 'Completed']);

        return $w->fresh();
    }

    private function requireCompetency(string $csv): void
    {
        $this->settings->set(self::TENANT, 'workforce_required_competencies', $csv);
    }

    /* ── Graceful degradation ───────────────────────────────────────────── */

    public function test_no_requirement_configured_leaves_competency_ok(): void
    {
        $w = $this->baseReadyWorker($this->vendor('NoReqCo'));

        $r = $this->wf->readiness($w);
        $this->assertTrue($r['competency_ok'], 'With nothing required the gate must not bite.');
        $this->assertTrue($r['ready'], 'A fully-evidenced worker is ready when no competency is required.');
        $this->assertSame([], $r['missing_competencies']);
    }

    /* ── The gate ───────────────────────────────────────────────────────── */

    public function test_worker_lacking_required_competency_is_not_ready(): void
    {
        $this->requireCompetency('Electrical Licence');
        $w = $this->baseReadyWorker($this->vendor('BlockCo'));

        $r = $this->wf->readiness($w);
        $this->assertFalse($r['competency_ok']);
        $this->assertFalse($r['ready']);
        $this->assertContains('Electrical Licence', $r['missing_competencies']);
    }

    public function test_activate_badge_is_refused_and_names_the_missing_competency(): void
    {
        $this->requireCompetency('Electrical Licence');
        $w = $this->baseReadyWorker($this->vendor('RefuseCo'));

        try {
            $this->wf->activateBadge($w, $this->admin());
            $this->fail('Badge should be refused for a missing required competency.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('competency', strtolower($e->getMessage()));
            $this->assertStringContainsString('Electrical Licence', $e->getMessage());
        }
    }

    public function test_valid_competency_clears_the_gate(): void
    {
        $this->requireCompetency('Electrical Licence');
        $v = $this->vendor('ClearCo');
        $w = $this->baseReadyWorker($v);

        $this->comp->addCompetency($w, [
            'name' => 'Electrical Licence (HT)', 'category' => 'Licence',
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        $r = $this->wf->readiness($w->fresh());
        $this->assertTrue($r['competency_ok'], 'A valid matching competency clears Rule 4.');
        $this->assertSame([], $r['missing_competencies']);
    }

    public function test_expired_competency_still_blocks(): void
    {
        $this->requireCompetency('Electrical Licence');
        $v = $this->vendor('ExpiredCo');
        $w = $this->baseReadyWorker($v);

        $this->comp->addCompetency($w, [
            'name' => 'Electrical Licence', 'category' => 'Licence',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->wf->readiness($w->fresh())['competency_ok']);
    }

    /* ── Service surface: workerHasCompetency / CRUD / skillMatrix ───────── */

    public function test_worker_has_competency_matches_valid_records_only(): void
    {
        $v = $this->vendor('MatchCo');
        $w = $this->worker($v);

        $this->assertTrue($this->comp->workerHasCompetency($w->id, null), 'No requirement is vacuously satisfied.');
        $this->assertFalse($this->comp->workerHasCompetency($w->id, 'Rigger Licence'));

        // Non-expiring record satisfies.
        $this->comp->addCompetency($w, ['name' => 'Rigger Licence L2', 'category' => 'Licence', 'valid_until' => null]);
        $this->assertTrue($this->comp->workerHasCompetency($w->id, 'Rigger Licence'));

        // Expired record does not satisfy.
        $w2 = $this->worker($v, 'W2');
        $this->comp->addCompetency($w2, ['name' => 'Rigger Licence', 'category' => 'Licence', 'valid_until' => now()->subDay()->toDateString()]);
        $this->assertFalse($this->comp->workerHasCompetency($w2->id, 'Rigger Licence'));
    }

    public function test_competency_crud_roundtrip(): void
    {
        $v = $this->vendor('CrudCo');
        $w = $this->worker($v);

        $c = $this->comp->addCompetency($w, ['name' => 'Welding', 'category' => 'Skill', 'skill_level' => 'Competent']);
        $this->assertDatabaseHas('purchase_worker_competencies', [
            'id' => $c->id, 'purchase_worker_id' => $w->id, 'purchase_vendor_id' => $v->id, 'tenant_id' => self::TENANT, 'name' => 'Welding',
        ]);

        $this->comp->updateCompetency($c, ['skill_level' => 'Expert']);
        $this->assertSame('Expert', $c->fresh()->skill_level);

        $this->comp->deleteCompetency($c);
        $this->assertSoftDeleted('purchase_worker_competencies', ['id' => $c->id]);
    }

    public function test_skill_matrix_reports_met_and_unmet_per_worker(): void
    {
        $this->requireCompetency('Electrical Licence');
        $v = $this->vendor('MatrixCo');

        $held = $this->worker($v, 'Has It');
        $this->comp->addCompetency($held, ['name' => 'Electrical Licence', 'category' => 'Licence', 'valid_until' => now()->addYear()->toDateString()]);
        $missing = $this->worker($v, 'Lacks It');

        $matrix = $this->comp->skillMatrix(self::TENANT, $v->id);

        $this->assertCount(2, $matrix['workers']);
        $this->assertCount(1, $matrix['requirements']);
        $row = $matrix['requirements'][0];
        $this->assertSame('Electrical Licence', $row['required_competency']);

        $cells = collect($row['cells'])->keyBy('worker_id');
        $this->assertTrue($cells[$held->id]['met']);
        $this->assertFalse($cells[$missing->id]['met']);
    }
}
