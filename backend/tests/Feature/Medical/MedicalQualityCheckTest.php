<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The quality check and the conversation around it.
 *
 * Three rules this suite exists to defend:
 *  - a refusal must carry a reason, or nobody can act on it;
 *  - a HOLD comes back and a REJECTION does not — the remedy for a rejection is
 *    a fresh examination, not another look at the same paper;
 *  - the back-and-forth ends. At the configured limit the reviewer has to decide
 *    on what they have.
 */
class MedicalQualityCheckTest extends TestCase
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

    private function vendorWithLogin(): array
    {
        $login = $this->user('third_party_vendor');
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Contracting',
            'email' => $login->email, 'user_id' => $login->id, 'status' => 'Active',
        ]);

        return [$vendor, $login];
    }

    private function worker(Vendor $vendor): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Ravi Kumar',
            'worker_code' => 'WRK-'.Str::random(4), 'designation' => 'Fitter',
            'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /** A certificate sitting in the review queue. */
    private function submitted(Vendor $vendor, User $by): TpvWorkerMedical
    {
        $worker = $this->worker($vendor);

        return app(TpvMedicalWorkflowService::class)->record(
            $worker,
            ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString(), 'examiner_name' => 'Dr A'],
            $by,
            MedicalWorkflow::ORIGIN_VENDOR_UPLOAD,
        );
    }

    /* ── The verdict ────────────────────────────────────────────────────── */

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Rejected'])
            ->assertStatus(422);

        $this->assertSame(MedicalQcStatus::PENDING, $medical->fresh()->qc_status);
    }

    public function test_a_hold_needs_a_reason_too(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Hold'])
            ->assertStatus(422);
    }

    public function test_approval_clears_the_worker_and_records_who_signed_it(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);
        $reviewer = $this->user('admin');

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Approved'])->assertOk();

        $fresh = $medical->fresh();
        $this->assertSame(MedicalQcStatus::APPROVED, $fresh->qc_status);
        $this->assertSame($reviewer->id, (int) $fresh->qc_by);
        // Approval IS the sign-off — not a second, separate action someone has
        // to remember to perform.
        $this->assertSame($reviewer->id, (int) $fresh->approved_by);
        $this->assertTrue($fresh->isCurrentlyValid());

        $clearance = app(TpvMedicalWorkflowService::class)->clearanceFor($medical->worker);
        $this->assertTrue($clearance['cleared']);
    }

    public function test_a_rejection_names_its_reason_on_the_timeline(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/medical/{$medical->id}/decide", [
            'decision' => 'Rejected', 'reason_code' => 'age_limit', 'note' => 'Over the site age limit.',
        ])->assertOk();

        $entry = $medical->fresh()->messages()->where('action', MedicalWorkflow::ACTION_REJECTED)->first();
        $this->assertSame('age_limit', $entry->reason_code);
        $this->assertSame('Over the site age limit.', $entry->body);
    }

    /* ── Hold vs rejection ──────────────────────────────────────────────── */

    public function test_a_held_certificate_comes_back_and_a_rejected_one_does_not(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $held     = $this->submitted($vendor, $login);
        $rejected = $this->submitted($vendor, $login);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/medical/{$held->id}/decide",
            ['decision' => 'Hold', 'reason_code' => 'illegible_document'])->assertOk();
        $this->postJson("/api/tpv/medical/{$rejected->id}/decide",
            ['decision' => 'Rejected', 'reason_code' => 'physically_unfit'])->assertOk();

        Sanctum::actingAs($login);

        $this->postJson("/api/portal/medical/{$held->id}/resubmit", ['message' => 'Clearer scan attached.'])
            ->assertOk();
        $this->assertSame(MedicalQcStatus::PENDING, $held->fresh()->qc_status);
        $this->assertSame(1, (int) $held->fresh()->iteration_count);

        $this->postJson("/api/portal/medical/{$rejected->id}/resubmit", ['message' => 'Please look again.'])
            ->assertStatus(422);
        $this->assertSame(MedicalQcStatus::REJECTED, $rejected->fresh()->qc_status);
    }

    public function test_a_rejected_certificate_cannot_be_decided_a_second_time(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/medical/{$medical->id}/decide",
            ['decision' => 'Rejected', 'reason_code' => 'physically_unfit'])->assertOk();

        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Approved'])
            ->assertStatus(422);
    }

    /* ── The conversation, and its end ──────────────────────────────────── */

    public function test_the_timeline_maps_the_whole_exchange(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical  = $this->submitted($vendor, $login);
        $reviewer = $this->user('admin');

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide",
            ['decision' => 'Hold', 'reason_code' => 'missing_tests'])->assertOk();
        $this->postJson("/api/tpv/medical/{$medical->id}/comment", ['body' => 'Audiometry is missing.'])->assertStatus(201);

        Sanctum::actingAs($login);
        $this->postJson("/api/portal/medical/{$medical->id}/comment", ['body' => 'Booking it for Friday.'])->assertStatus(201);
        $this->postJson("/api/portal/medical/{$medical->id}/resubmit", ['message' => 'Audiometry added.'])->assertOk();

        Sanctum::actingAs($reviewer);
        $body = $this->getJson("/api/tpv/medical/{$medical->id}")->assertOk()->json('data.timeline');

        $actions = collect($body['messages'])->pluck('action')->all();
        $this->assertSame([
            MedicalWorkflow::ACTION_SUBMITTED,
            MedicalWorkflow::ACTION_HOLD,
            MedicalWorkflow::ACTION_COMMENT,
            MedicalWorkflow::ACTION_COMMENT,
            MedicalWorkflow::ACTION_RESUBMITTED,
        ], $actions);

        // Both sides are on the record, not just the one who spoke last.
        $sides = collect($body['messages'])->pluck('author_side')->unique()->values()->all();
        $this->assertContains(MedicalWorkflow::SIDE_QUALITY, $sides);
        $this->assertContains(MedicalWorkflow::SIDE_VENDOR, $sides);
    }

    public function test_the_exchange_stops_at_the_configured_limit(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical  = $this->submitted($vendor, $login);
        $reviewer = $this->user('admin');

        // Ten rounds of hold-and-resubmit is the shipped cap.
        for ($i = 1; $i <= 10; $i++) {
            Sanctum::actingAs($reviewer);
            $this->postJson("/api/tpv/medical/{$medical->id}/decide",
                ['decision' => 'Hold', 'reason_code' => 'missing_tests'])->assertOk();

            Sanctum::actingAs($login);
            $this->postJson("/api/portal/medical/{$medical->id}/resubmit", ['message' => "Round {$i}."])->assertOk();
        }

        $this->assertSame(10, (int) $medical->fresh()->iteration_count);

        // The eleventh is refused — the reviewer must now approve or reject.
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide",
            ['decision' => 'Hold', 'reason_code' => 'missing_tests'])->assertOk();

        Sanctum::actingAs($login);
        $this->postJson("/api/portal/medical/{$medical->id}/resubmit", ['message' => 'Round 11.'])
            ->assertStatus(422);

        Sanctum::actingAs($reviewer);
        $this->getJson("/api/tpv/medical/{$medical->id}")->assertOk()
            ->assertJsonPath('data.timeline.can_resubmit', false)
            ->assertJsonPath('data.timeline.exchanges_left', 0);
    }

    /* ── Who may review ─────────────────────────────────────────────────── */

    public function test_only_a_nominated_reviewer_may_decide_when_a_list_is_configured(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical  = $this->submitted($vendor, $login);
        $assigned = $this->user('staff');
        $other    = $this->user('staff');

        // The tenant names its quality team.
        Sanctum::actingAs($this->user('admin'));
        $this->putJson('/api/tpv/settings/medical', ['qc_approver_ids' => [$assigned->id]])->assertOk();

        Sanctum::actingAs($other);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Approved'])->assertStatus(422);

        Sanctum::actingAs($assigned);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Approved'])->assertOk();
    }

    public function test_a_vendor_cannot_decide_its_own_certificate(): void
    {
        [$vendor, $login] = $this->vendorWithLogin();
        $medical = $this->submitted($vendor, $login);

        Sanctum::actingAs($login);
        $this->postJson("/api/tpv/medical/{$medical->id}/decide", ['decision' => 'Approved'])
            ->assertStatus(403);
    }

    public function test_a_vendor_cannot_see_another_vendors_certificate(): void
    {
        [$mine, $myLogin] = $this->vendorWithLogin();
        [$theirs, $theirLogin] = $this->vendorWithLogin();
        $theirMedical = $this->submitted($theirs, $theirLogin);

        Sanctum::actingAs($myLogin);
        // 404, not 403 — the portal never confirms another vendor's records exist.
        $this->getJson("/api/portal/medical/{$theirMedical->id}")->assertStatus(404);
    }

    /* ── The doctor is told ─────────────────────────────────────────────── */

    public function test_the_examining_doctor_is_notified_of_the_verdict(): void
    {
        [$vendor] = $this->vendorWithLogin();
        $worker = $this->worker($vendor);

        $doctor = $this->user('doctor');
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $doctor->id, 'license_no' => 'MH-1', 'is_active' => true,
        ]);

        $medical = app(TpvMedicalWorkflowService::class)->record(
            $worker,
            ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()],
            $doctor,
            MedicalWorkflow::ORIGIN_DOCTOR_PORTAL,
        );

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/medical/{$medical->id}/decide",
            ['decision' => 'Hold', 'reason_code' => 'missing_tests'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $doctor->id,
            'type'    => 'tpv_medical_decision',
        ]);
    }
}
