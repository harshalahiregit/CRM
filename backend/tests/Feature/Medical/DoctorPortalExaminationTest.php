<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Internal Medical Flow: a doctor signs in, picks a vendor and a worker,
 * fills the examination form, and the system produces a certificate.
 *
 * What these tests hold onto: the doctor's licence is what makes a certificate
 * issuable, the examination is scored rather than merely stored, and nothing a
 * doctor files is clearance until the quality team has seen it.
 */
class DoctorPortalExaminationTest extends TestCase
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

    /* ── Fixtures ───────────────────────────────────────────────────────── */

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function doctor(?string $licence = 'MH-123456', ?array $modules = null): User
    {
        $user = $this->user('doctor');
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id,
            'license_no' => $licence, 'council' => 'MMC', 'qualification' => 'MBBS, AFIH',
            'clinic_name' => 'City Occupational Health', 'modules' => $modules, 'is_active' => true,
        ]);

        return $user;
    }

    private function worker(): TpvWorker
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Contracting',
            'email' => 'acme-'.Str::random(5).'@t.local', 'status' => 'Active',
        ]);

        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Ravi Kumar',
            'designation' => 'Fitter', 'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /** A 1x1 PNG — the smallest thing that reads as a real image. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** A complete, ordinary examination payload. */
    private function examination(array $overrides = []): array
    {
        return array_merge([
            'fitness_status' => 'Fit',
            'exam_date'      => now()->toDateString(),
            'height_cm'      => 172,
            'weight_kg'      => 68,
            'bp_systolic'    => 120,
            'bp_diastolic'   => 80,
            'pulse_bpm'      => 74,
            'spo2'           => 98,
            'vision_left'    => '6/6',
            'vision_right'   => '6/6',
            'colour_vision'  => 'Normal',
            'hearing'        => 'Normal',
            'blood_group'    => 'B+',
            'investigations' => [['name' => 'Chest X-ray', 'result' => 'Normal']],
            'medical_history' => ['conditions' => [], 'habits' => ['None']],
            'geo_location'   => '19.1197,72.8468',
            // The signature and the camera photo are now required, alongside the
            // location: together they are what makes the certificate evidence
            // rather than an assertion. A 1x1 PNG is all the endpoint checks for.
            'signature_data' => self::PNG,
            'capture_photo'  => self::PNG,
            'doctor_remarks' => 'Cleared for general duties.',
        ], $overrides);
    }

    /* ── The examination ────────────────────────────────────────────────── */

    public function test_a_doctor_records_an_examination_and_the_system_issues_a_certificate(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $res = $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination", $this->examination())
            ->assertStatus(201);

        $medical = $worker->fresh()->medical;

        // A certificate number, derived not typed — it is what the barcode carries.
        $this->assertNotNull($medical->certificate_no);
        $this->assertStringContainsString('MED-TPV-', $medical->certificate_no);

        // The doctor's credentials are snapshotted onto the record, so a later
        // profile edit cannot change what an issued certificate claims.
        $this->assertSame('MH-123456', $medical->doctor_license_no);
        $this->assertSame('MBBS, AFIH', $medical->doctor_qualification);

        // Scored, not merely stored.
        $this->assertNotNull($medical->health_score);
        $this->assertSame('auto', $medical->health_score_source);

        // Filed for review — a doctor's signature starts the process, it does
        // not end it.
        $this->assertSame(MedicalQcStatus::PENDING, $medical->qc_status);
        $this->assertSame(MedicalWorkflow::ORIGIN_DOCTOR_PORTAL, $medical->origin);

        // The IP is the server's observation, never the client's claim.
        $this->assertNotNull($medical->system_ip);

        // And the timeline opens with the submission.
        $this->assertSame(MedicalWorkflow::ACTION_SUBMITTED, $medical->messages()->first()->action);

        $this->assertSame($medical->id, $res->json('data.id'));
    }

    public function test_a_healthy_examination_scores_higher_than_a_poor_one(): void
    {
        $good = $this->worker();
        $poor = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$good->id}/examination", $this->examination())->assertStatus(201);
        $this->postJson("/api/doctor/tpv/workers/{$poor->id}/examination", $this->examination([
            'bp_systolic' => 168, 'bp_diastolic' => 104, 'spo2' => 91,
            'weight_kg' => 110, 'colour_vision' => 'Deficient',
            'medical_history' => ['conditions' => ['Diabetes', 'Asthma']],
        ]))->assertStatus(201);

        $this->assertGreaterThan(
            (float) $poor->fresh()->medical->health_score,
            (float) $good->fresh()->medical->health_score,
        );
    }

    public function test_an_unfit_verdict_caps_the_score_however_good_the_vitals(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination",
            $this->examination(['fitness_status' => 'Unfit']))->assertStatus(201);

        $this->assertLessThanOrEqual(3.0, (float) $worker->fresh()->medical->health_score);
    }

    /* ── Who may issue ──────────────────────────────────────────────────── */

    public function test_a_doctor_without_a_licence_cannot_issue_a_certificate(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor(licence: null));

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination", $this->examination())
            ->assertStatus(422);

        $this->assertNull($worker->fresh()->medical);
    }

    public function test_an_examination_without_a_verdict_is_refused(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination",
            $this->examination(['fitness_status' => null]))->assertStatus(422);
    }

    public function test_a_doctor_limited_to_purchase_cannot_reach_the_tpv_side(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor(modules: ['purchase']));

        // 404, not 403 — a side this doctor does not serve is not theirs to know about.
        $this->getJson('/api/doctor/tpv/vendors')->assertStatus(404);
        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination", $this->examination())
            ->assertStatus(404);
    }

    public function test_the_doctor_portal_is_closed_to_everyone_else(): void
    {
        foreach (['admin', 'staff', 'third_party_vendor'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/doctor/me')->assertStatus(403);
        }
    }

    /* ── Re-examination ─────────────────────────────────────────────────── */

    public function test_a_same_day_re_examination_is_a_new_record_not_an_overwrite(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination",
            $this->examination(['fitness_status' => 'Unfit']))->assertStatus(201);

        $first = $worker->fresh()->medical;

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination",
            $this->examination(['fitness_status' => 'Fit', 'is_reexam' => true]))->assertStatus(201);

        $history = $worker->fresh()->medicalHistory()->get();

        // Both survive, on the same day, and the new one names what it replaces.
        $this->assertCount(2, $history);
        $latest = $worker->fresh()->medical;
        $this->assertTrue((bool) $latest->is_reexam);
        $this->assertSame(2, (int) $latest->attempt_no);
        $this->assertSame($first->id, (int) $latest->previous_medical_id);
        $this->assertSame('Unfit', $first->fresh()->fitness_status, 'the superseded record must not be rewritten');
    }

    public function test_the_history_reports_a_score_and_its_movement(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination",
            $this->examination(['exam_date' => now()->subYear()->toDateString(), 'bp_systolic' => 168, 'bp_diastolic' => 104]))
            ->assertStatus(201);
        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination", $this->examination())->assertStatus(201);

        $body = $this->getJson("/api/doctor/tpv/workers/{$worker->id}")->assertOk()->json('data.history');

        $this->assertSame(2, $body['exam_count']);
        $this->assertNotNull($body['health_score']);
        $this->assertGreaterThan(0, $body['score_trend'], 'the worker improved, and the trend should say so');
    }

    /* ── The document ───────────────────────────────────────────────────── */

    public function test_the_certificate_renders_as_a_pdf(): void
    {
        $worker = $this->worker();
        Sanctum::actingAs($this->doctor());

        $this->postJson("/api/doctor/tpv/workers/{$worker->id}/examination", $this->examination())->assertStatus(201);
        $medical = $worker->fresh()->medical;

        $res = $this->get("/api/doctor/tpv/examinations/{$medical->id}/certificate")->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
