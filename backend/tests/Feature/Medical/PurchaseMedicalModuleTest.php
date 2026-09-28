<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkPackage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseMedicalWorkflowService;
use App\Services\Purchase\PurchaseWorkforceService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Medical module on the Purchase side.
 *
 * Same flows as TPV, against the Purchase register — which is the point of
 * testing it separately: the two sides share a vocabulary, not a code path, and
 * parity only holds if both are exercised. The Purchase vendor also signs in as
 * a PurchaseVendor rather than a User, so "who filed this" is a different
 * question here.
 */
class PurchaseMedicalModuleTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG — the smallest thing that reads as a real image. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    /* ── Fixtures ───────────────────────────────────────────────────────── */

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function doctor(): User
    {
        $user = $this->user('doctor');
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id,
            'license_no' => 'MH-4242', 'qualification' => 'MBBS', 'is_active' => true,
        ]);

        return $user;
    }

    private function vendor(string $name = 'Bolt Supplies'): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    private function worker(PurchaseVendor $vendor, string $code = 'PW-0001'): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'worker_code' => $code, 'full_name' => 'Suresh Patil',
            'designation' => 'Rigger', 'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /* ── Internal flow ──────────────────────────────────────────────────── */

    public function test_a_doctor_examines_a_purchase_worker_and_a_certificate_is_issued(): void
    {
        $worker = $this->worker($this->vendor());
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/purchase/workers/{$worker->id}/examination", [
            'fitness_status' => 'Fit',
            'exam_date'      => now()->toDateString(),
            'height_cm'      => 170, 'weight_kg' => 70,
            'bp_systolic'    => 118, 'bp_diastolic' => 78,
            'geo_location'   => '19.1197,72.8468',
            'signature_data' => self::PNG, 'capture_photo' => self::PNG,
        ])->assertStatus(201);

        $medical = $worker->fresh()->latestMedical;

        $this->assertStringContainsString('MED-PUR-', $medical->certificate_no);
        $this->assertSame('MH-4242', $medical->doctor_license_no);
        $this->assertSame(MedicalQcStatus::PENDING, $medical->qc_status);
        $this->assertNotNull($medical->health_score);
        // Purchase keeps both date columns; they must never drift apart, because
        // expiry_date is what the fitness gate reads.
        $this->assertSame(
            optional($medical->valid_until)->toDateString(),
            optional($medical->expiry_date)->toDateString(),
        );
    }

    /* ── External flow ──────────────────────────────────────────────────── */

    public function test_a_purchase_vendor_uploads_an_external_certificate(): void
    {
        $vendor = $this->vendor();
        $worker = $this->worker($vendor);

        Sanctum::actingAs($vendor);

        $this->postJson("/api/portal/purchase/workers/{$worker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr B. Rao',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 15, 'application/pdf'),
        ])->assertStatus(201);

        $medical = $worker->fresh()->latestMedical;
        $this->assertSame(MedicalWorkflow::ORIGIN_VENDOR_UPLOAD, $medical->origin);
        $this->assertNotNull($medical->document_path);

        // A vendor is not a user: the "recorded by" column names a USER, and
        // writing a vendor id there would corrupt every later reading of it.
        $this->assertNull($medical->recorded_by);
        // The timeline still knows who filed it.
        $this->assertSame(MedicalWorkflow::SIDE_VENDOR, $medical->messages()->first()->author_side);
        $this->assertSame($vendor->company_name, $medical->messages()->first()->author_name);
    }

    public function test_a_purchase_vendor_cannot_see_another_vendors_certificate(): void
    {
        $mine   = $this->vendor('Mine');
        $theirs = $this->vendor('Theirs');
        $worker = $this->worker($theirs, 'PW-9999');

        Sanctum::actingAs($theirs);
        $this->postJson("/api/portal/purchase/workers/{$worker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr B',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->assertStatus(201);

        $theirMedical = $worker->fresh()->latestMedical;

        Sanctum::actingAs($mine);
        $this->getJson("/api/portal/purchase/medical/{$theirMedical->id}")->assertStatus(404);
    }

    /* ── Quality check and the prerequisite ─────────────────────────────── */

    public function test_the_quality_check_decides_and_the_induction_block_follows_it(): void
    {
        $vendor = $this->vendor();
        $worker = $this->worker($vendor);
        $wf     = app(PurchaseWorkforceService::class);

        Sanctum::actingAs($vendor);
        $this->postJson("/api/portal/purchase/workers/{$worker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr B',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->assertStatus(201);

        $medical = $worker->fresh()->latestMedical;

        // Signed but unreviewed is not clearance — the induction is refused.
        try {
            $wf->saveInduction($worker->fresh(), ['status' => 'Completed']);
            $this->fail('the induction should have been blocked');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Medical Report is Pending', $e->getMessage());
        }

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/purchase/medical/{$medical->id}/decide", ['decision' => 'Approved'])->assertOk();

        // Approved — and now it goes through.
        $induction = $wf->saveInduction($worker->fresh(), ['status' => 'Completed']);
        $this->assertNotNull($induction->id);
    }

    public function test_a_hold_comes_back_and_the_exchange_is_counted(): void
    {
        $vendor = $this->vendor();
        $worker = $this->worker($vendor);

        Sanctum::actingAs($vendor);
        $this->postJson("/api/portal/purchase/workers/{$worker->id}/medical/external", [
            'fitness_status' => 'Fit', 'examiner_name' => 'Dr B',
            'report_file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->assertStatus(201);

        $medical = $worker->fresh()->latestMedical;

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/purchase/medical/{$medical->id}/decide",
            ['decision' => 'Hold', 'reason_code' => 'illegible_document'])->assertOk();

        Sanctum::actingAs($vendor);
        $this->postJson("/api/portal/purchase/medical/{$medical->id}/resubmit", [
            'message' => 'Clearer scan attached.',
            'report_file' => UploadedFile::fake()->create('cert2.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(MedicalQcStatus::PENDING, $medical->fresh()->qc_status);
        $this->assertSame(1, (int) $medical->fresh()->iteration_count);
    }

    /* ── The project bypass ─────────────────────────────────────────────── */

    public function test_a_project_marked_not_applicable_lifts_the_requirement(): void
    {
        $vendor = $this->vendor();
        $worker = $this->worker($vendor);
        $wp = PurchaseWorkPackage::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'name' => 'Supply only', 'medical_not_applicable' => true,
        ]);
        $worker->forceFill(['work_package_id' => $wp->id])->save();

        $clearance = app(PurchaseMedicalWorkflowService::class)->clearanceFor($worker->fresh());

        $this->assertTrue($clearance['bypassed']);
        $this->assertFalse($clearance['required']);
        $this->assertTrue($clearance['cleared']);

        // And with no examination at all, the induction goes through.
        $induction = app(PurchaseWorkforceService::class)->saveInduction($worker->fresh(), ['status' => 'Completed']);
        $this->assertNotNull($induction->id);
    }

    /* ── Verification ───────────────────────────────────────────────────── */

    public function test_the_public_page_verifies_a_certificate_without_a_login(): void
    {
        $vendor = $this->vendor();
        $worker = $this->worker($vendor);

        Sanctum::actingAs($this->doctor());
        $this->postJson("/api/doctor/purchase/workers/{$worker->id}/examination", [
            'fitness_status' => 'Fit', 'exam_date' => now()->toDateString(),
            // Location, signature and camera photo are mandatory on a doctor's
            // examination — they are what the certificate rests on.
            'geo_location' => '19.1197,72.8468',
            'signature_data' => self::PNG, 'capture_photo' => self::PNG,
        ])->assertStatus(201);

        $medical = $worker->fresh()->latestMedical;

        // Nobody signed in from here on.
        app('auth')->forgetGuards();

        $body = $this->getJson('/api/public/medical/verify/'.$medical->certificate_no)->assertOk()->json('data');
        $this->assertTrue($body['found']);
        // Not approved yet, so not valid — verification reports clearance, not
        // merely existence.
        $this->assertFalse($body['valid']);
        $this->assertSame('Suresh Patil', $body['worker_name']);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/purchase/medical/{$medical->id}/decide", ['decision' => 'Approved'])->assertOk();
        app('auth')->forgetGuards();

        $this->assertTrue(
            $this->getJson('/api/public/medical/verify/'.$medical->certificate_no)->assertOk()->json('data.valid')
        );
    }

    public function test_an_unknown_certificate_number_answers_without_confirming_anything(): void
    {
        $body = $this->getJson('/api/public/medical/verify/MED-PUR-2026-999999')->assertOk()->json('data');

        // 200, not 404 — scanning a forged code must not become a way to probe
        // which numbers exist.
        $this->assertFalse($body['found']);
        $this->assertFalse($body['valid']);
    }
}
