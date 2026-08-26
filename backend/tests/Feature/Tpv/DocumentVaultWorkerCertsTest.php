<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerCompetency;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\Tpv\TpvWorkerTraining;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvDocumentVaultService;
use App\Support\Tpv\TpvMedicalFitness;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §30 — the document vault surfaces worker medical / training / competency
 * certificates alongside the vendor-level documents.
 */
class DocumentVaultWorkerCertsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_worker_certificates_appear_in_the_vault(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $worker = TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Ravi',
            'worker_code' => 'W-'.Str::random(5), 'current_step' => 1, 'status' => 'Draft',
        ]);

        TpvWorkerMedical::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id,
            'fitness_status' => TpvMedicalFitness::FIT, 'certificate_path' => 'medicals/cert.pdf',
            'valid_until' => now()->addMonths(6)->toDateString(),
        ]);
        TpvWorkerTraining::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id,
            'training_type' => 'Fire', 'certificate_path' => 'training/fire.pdf', 'passed' => true,
        ]);
        TpvWorkerCompetency::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id,
            'name' => 'Welding', 'category' => 'Skill', 'evidence_path' => 'comp/weld.pdf',
        ]);

        $rows = app(TpvDocumentVaultService::class)->roster(self::TENANT, ['vendor_id' => $vendor->id]);
        $sources = collect($rows)->pluck('source')->unique();

        $this->assertTrue($sources->contains('Medical'));
        $this->assertTrue($sources->contains('Training'));
        $this->assertTrue($sources->contains('Competency'));
    }
}
