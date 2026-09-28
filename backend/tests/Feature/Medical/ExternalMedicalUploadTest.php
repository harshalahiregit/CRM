<?php

namespace Tests\Feature\Medical;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The External Medical Flow: a vendor whose own doctor examined their workers
 * files those certificates here — one at a time, or a sheet at once.
 *
 * The rule the bulk path exists to honour: rows are independent. A vendor
 * uploading forty certificates must not lose thirty-nine to one typo, and every
 * rejected row has to come back with a reason they can act on.
 */
class ExternalMedicalUploadTest extends TestCase
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

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendorWithLogin(string $name = 'Acme Contracting'): array
    {
        $login  = $this->user('third_party_vendor');
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => $login->email, 'user_id' => $login->id, 'status' => 'Active',
        ]);
        $this->markOnboarded($vendor);

        return [$vendor, $login];
    }

    private function worker(Vendor $vendor, string $code): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Worker '.$code,
            'worker_code' => $code, 'designation' => 'Fitter', 'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /** A CSV in the template's shape. */
    private function sheet(array $rows): UploadedFile
    {
        $headers = 'worker_code,worker_name,exam_date,valid_until,fitness_status,doctor_name,doctor_license_no,clinic_name,blood_group,height_cm,weight_kg,bp_systolic,bp_diastolic,restrictions,remarks';
        $csv = $headers."\n".implode("\n", $rows)."\n";

        return UploadedFile::fake()->createWithContent('certificates.csv', $csv);
    }

    /* ── One at a time ──────────────────────────────────────────────────── */

    public function test_a_vendor_uploads_one_external_certificate(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $worker = $this->worker($vendor, 'WRK-0001');

        Sanctum::actingAs($login);

        $this->postJson("/api/portal/workers/{$worker->id}/medical/external", [
            'fitness_status'    => 'Fit',
            'examiner_name'     => 'Dr A. Sharma',
            'doctor_license_no' => 'MH-999',
            'exam_date'         => now()->subDay()->toDateString(),
            'report_file'       => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'),
        ])->assertStatus(201);

        $medical = $worker->fresh()->medical;
        $this->assertSame(MedicalWorkflow::ORIGIN_VENDOR_UPLOAD, $medical->origin);
        $this->assertSame('external', $medical->exam_type);
        // The uploaded certificate IS the record's evidence — losing it would
        // empty the record of its meaning.
        $this->assertNotNull($medical->document_path);
        // External always faces a reviewer.
        $this->assertSame(MedicalQcStatus::PENDING, $medical->qc_status);
        // And a currency window is derived even though the vendor gave none.
        $this->assertNotNull($medical->valid_until);
    }

    public function test_an_external_upload_without_the_certificate_file_is_refused(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $worker = $this->worker($vendor, 'WRK-0002');

        Sanctum::actingAs($login);

        $this->postJson("/api/portal/workers/{$worker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr A. Sharma',
        ])->assertStatus(422);
    }

    public function test_a_vendor_cannot_upload_against_another_vendors_worker(): void
    {
        [, $myLogin]  = $this->vendorWithLogin('Mine');
        [$theirs]     = $this->vendorWithLogin('Theirs');
        $theirWorker  = $this->worker($theirs, 'WRK-0003');

        Sanctum::actingAs($myLogin);

        $this->postJson("/api/portal/workers/{$theirWorker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr A',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->assertStatus(404);

        $this->assertNull($theirWorker->fresh()->medical);
    }

    /* ── The sheet ──────────────────────────────────────────────────────── */

    public function test_the_template_names_the_columns_the_import_reads(): void
    {
        [, $login] = $this->vendorWithLogin();
        Sanctum::actingAs($login);

        $csv = $this->get('/api/portal/medical/template')->assertOk()->streamedContent();
        $header = strtolower(trim(explode("\n", $csv)[0]));

        foreach (['worker_code', 'exam_date', 'valid_until', 'fitness_status', 'doctor_license_no'] as $column) {
            $this->assertStringContainsString($column, $header);
        }
    }

    public function test_a_bulk_sheet_imports_its_good_rows_and_reports_the_rest(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $this->worker($vendor, 'WRK-0001');
        $this->worker($vendor, 'WRK-0002');

        Sanctum::actingAs($login);

        $res = $this->post('/api/portal/medical/bulk', [
            'file' => $this->sheet([
                'WRK-0001,Ravi,'.now()->subDay()->toDateString().',,Fit,Dr A,MH-1,City Clinic,B+,172,68,120,80,,Annual',
                'WRK-0002,Suresh,'.now()->subDay()->toDateString().',,Fit_With_Restrictions,Dr A,MH-1,City Clinic,O+,168,72,124,82,No work at height,Annual',
                // Not this vendor's worker.
                'WRK-9999,Ghost,'.now()->subDay()->toDateString().',,Fit,Dr A,MH-1,City Clinic,A+,170,70,120,80,,Annual',
                // A verdict that is not in the vocabulary.
                'WRK-0001,Ravi,'.now()->subDays(2)->toDateString().',,Sort-of-fit,Dr A,MH-1,City Clinic,B+,172,68,120,80,,Annual',
            ]),
        ])->assertStatus(201);

        $this->assertSame(2, $res->json('data.created_count'));
        $this->assertSame(2, $res->json('data.failed_count'));

        // Each reject names its row and why — so only those rows are re-sent.
        $errors = collect($res->json('data.errors'));
        $this->assertSame([4, 5], $errors->pluck('row')->all());
        $this->assertStringContainsString('No worker with this code', $errors->firstWhere('row', 4)['error']);
        $this->assertStringContainsString('Unknown fitness status', $errors->firstWhere('row', 5)['error']);

        // The good rows landed as reviewable external certificates.
        $medical = TpvWorker::where('worker_code', 'WRK-0001')->first()->medical;
        $this->assertSame(MedicalWorkflow::ORIGIN_BULK_UPLOAD, $medical->origin);
        $this->assertSame(MedicalQcStatus::PENDING, $medical->qc_status);
        $this->assertSame('Dr A', $medical->examiner_name);
        $this->assertSame('MH-1', $medical->doctor_license_no);
    }

    public function test_bulk_certificate_files_are_matched_to_their_rows_by_worker_code(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $this->worker($vendor, 'WRK-0001');

        Sanctum::actingAs($login);

        $this->post('/api/portal/medical/bulk', [
            'file' => $this->sheet([
                'WRK-0001,Ravi,'.now()->subDay()->toDateString().',,Fit,Dr A,MH-1,City Clinic,B+,172,68,120,80,,Annual',
            ]),
            'certificates' => [
                UploadedFile::fake()->create('WRK-0001.pdf', 20, 'application/pdf'),
            ],
        ])->assertStatus(201);

        $this->assertNotNull(
            TpvWorker::where('worker_code', 'WRK-0001')->first()->medical->document_path,
            'the attached certificate should be filed against its row',
        );
    }

    public function test_a_bulk_upload_cannot_reach_another_vendors_workers(): void
    {
        [$mine, $myLogin] = $this->vendorWithLogin('Mine');
        [$theirs]         = $this->vendorWithLogin('Theirs');
        $this->worker($theirs, 'WRK-7777');

        Sanctum::actingAs($myLogin);

        // The vendor is taken from the session, never the sheet.
        $res = $this->post('/api/portal/medical/bulk', [
            'file' => $this->sheet([
                'WRK-7777,Theirs,'.now()->subDay()->toDateString().',,Fit,Dr A,MH-1,Clinic,B+,170,70,120,80,,x',
            ]),
        ])->assertStatus(201);

        $this->assertSame(0, $res->json('data.created_count'));
        $this->assertSame(1, $res->json('data.failed_count'));
        $this->assertNull(TpvWorker::where('worker_code', 'WRK-7777')->first()->medical);
    }

    public function test_a_vendor_sees_only_its_own_import_history(): void
    {
        [$vendor, $login] = $this->vendorWithLogin('Mine');
        $this->worker($vendor, 'WRK-0001');

        Sanctum::actingAs($login);
        $this->post('/api/portal/medical/bulk', [
            'file' => $this->sheet([
                'WRK-0001,Ravi,'.now()->subDay()->toDateString().',,Fit,Dr A,MH-1,Clinic,B+,172,68,120,80,,x',
            ]),
        ])->assertStatus(201);

        $this->assertCount(1, $this->getJson('/api/portal/medical/batches')->assertOk()->json('data'));

        [, $otherLogin] = $this->vendorWithLogin('Theirs');
        Sanctum::actingAs($otherLogin);
        $this->assertCount(0, $this->getJson('/api/portal/medical/batches')->assertOk()->json('data'));
    }

    /* ── What the vendor sees afterwards ────────────────────────────────── */

    public function test_the_portal_shows_which_workers_are_still_blocked(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $cleared = $this->worker($vendor, 'WRK-0001');
        $this->worker($vendor, 'WRK-0002');   // nothing recorded at all

        Sanctum::actingAs($login);
        $this->postJson("/api/portal/workers/{$cleared->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr A',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->assertStatus(201);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/medical/{$cleared->fresh()->medical->id}/decide", ['decision' => 'Approved'])->assertOk();

        Sanctum::actingAs($login);
        $body = $this->getJson('/api/portal/medical')->assertOk()->json();

        $this->assertSame(1, $body['summary']['approved']);
        $this->assertSame(1, $body['summary']['blocked_workers']);

        $blocked = collect($body['workers'])->firstWhere('worker_code', 'WRK-0002');
        $this->assertFalse($blocked['clearance']['cleared']);
        $this->assertStringContainsString('Medical Report is Pending', $blocked['clearance']['message']);
    }
}
