<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0-1 — the external-doctor medical report used to be captured in the UI but
 * never sent, and fitness was hardcoded to "Fit". These lock in the fix: the
 * uploaded file is persisted (document_path) and the certified fitness is
 * whatever the examiner selected — including Unfit.
 */
class MedicalExternalReportUploadTest extends TestCase
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

    private function worker(): TpvWorker
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi',
            'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    public function test_external_report_file_is_persisted_and_fitness_is_not_forced(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'exam_type'      => 'external',
            'examiner_name'  => 'Dr. Meera',
            'fitness_status' => 'Unfit',
            'report_file'    => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf'),
        ])->assertOk();

        $med = $worker->fresh('medical')->medical;
        $this->assertNotNull($med, 'a medical record should exist');
        $this->assertSame('external', $med->exam_type);
        // The evidence is stored, not dropped.
        $this->assertNotNull($med->document_path, 'the uploaded report path must be saved');
        Storage::disk('local')->assertExists($med->document_path);
        // Fitness is the examiner's outcome, not a hardcoded "Fit".
        $this->assertSame('Unfit', $med->fitness_status);
    }

    public function test_internal_exam_still_saves_without_a_file(): void
    {
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr. In House',
            'height_cm'      => 175,
            'weight_kg'      => 72,
            'fitness_status' => 'Fit',
        ])->assertOk();

        $med = $worker->fresh('medical')->medical;
        $this->assertSame('internal', $med->exam_type);
        $this->assertSame('Fit', $med->fitness_status);
        $this->assertNull($med->document_path);
    }
}
