<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
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
 * The Medical report: totals, vendor statistics, successes and failures, health
 * ratings.
 *
 * The distinction this suite exists to hold: a "success" is a CLEARED
 * certificate — passing, current and approved — not merely one that was filed.
 * Counting submissions as successes would flatter every number on the page.
 */
class MedicalReportTest extends TestCase
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

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => Str::random(6).'@t.local', 'status' => 'Active',
        ]);
    }

    private function worker(Vendor $vendor, string $name = 'Worker'): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => $name,
            'worker_code' => 'WRK-'.Str::random(5), 'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /**
     * Record an examination and, optionally, rule on it.
     *
     * @param  array<string,mixed>  $data
     */
    private function exam(TpvWorker $worker, array $data, ?string $decision = null, ?string $reason = null)
    {
        $workflow = app(TpvMedicalWorkflowService::class);
        $actor    = $this->user('admin');

        $medical = $workflow->record($worker, $data, $actor, MedicalWorkflow::ORIGIN_ADMIN);

        if ($decision) {
            $workflow->decide($medical, $decision, ['reason_code' => $reason], $actor);
        }

        return $medical->fresh();
    }

    /* ── The report ─────────────────────────────────────────────────────── */

    public function test_the_report_counts_successes_as_cleared_not_merely_submitted(): void
    {
        $vendor = $this->vendor('Acme');

        // Approved and fit — a real success.
        $this->exam($this->worker($vendor, 'Cleared'), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()], MedicalQcStatus::APPROVED);
        // Fit, but nobody has reviewed it: not a success yet.
        $this->exam($this->worker($vendor, 'Awaiting'), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        // Rejected: a failure.
        $this->exam($this->worker($vendor, 'Refused'), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()], MedicalQcStatus::REJECTED, 'age_limit');
        // Unfit: also a failure, whatever the reviewer says.
        $this->exam($this->worker($vendor, 'Unfit'), ['fitness_status' => 'Unfit', 'exam_date' => now()->toDateString()], MedicalQcStatus::APPROVED);

        Sanctum::actingAs($this->user('admin'));
        $totals = $this->getJson('/api/tpv/medical/report')->assertOk()->json('data.totals');

        $this->assertSame(4, $totals['examinations']);
        $this->assertSame(4, $totals['workers']);
        $this->assertSame(1, $totals['successes'], 'only the approved AND fit certificate is a success');
        $this->assertSame(2, $totals['failures'], 'the rejection and the unfit outcome');
        $this->assertSame(1, $totals['pending_review']);
        $this->assertSame(1, $totals['rejected']);

        // Success rate is measured against certificates somebody actually ruled
        // on — 2 approved of 3 decided.
        $this->assertSame(3, $totals['decided']);
        $this->assertSame(66.7, $totals['success_rate']);
    }

    public function test_vendor_statistics_are_reported_per_vendor(): void
    {
        $acme = $this->vendor('Acme');
        $bolt = $this->vendor('Bolt');

        $this->exam($this->worker($acme), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()], MedicalQcStatus::APPROVED);
        $this->exam($this->worker($acme), ['fitness_status' => 'Unfit', 'exam_date' => now()->toDateString()], MedicalQcStatus::REJECTED, 'physically_unfit');
        $this->exam($this->worker($bolt), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()], MedicalQcStatus::APPROVED);

        Sanctum::actingAs($this->user('admin'));
        $rows = collect($this->getJson('/api/tpv/medical/report')->assertOk()->json('data.by_vendor'));

        $acmeRow = $rows->firstWhere('vendor', 'Acme');
        $boltRow = $rows->firstWhere('vendor', 'Bolt');

        $this->assertSame(2, $acmeRow['examinations']);
        $this->assertSame(1, $acmeRow['approved']);
        $this->assertSame(1, $acmeRow['rejected']);
        $this->assertSame(1, $acmeRow['unfit']);
        $this->assertEquals(50, $acmeRow['success_rate']);

        $this->assertSame(1, $boltRow['examinations']);
        $this->assertEquals(100, $boltRow['success_rate']);

        // Busiest vendor first — the reader's first question is "who is heaviest".
        $this->assertSame('Acme', $rows->first()['vendor']);
    }

    public function test_health_ratings_are_banded_and_averaged(): void
    {
        $vendor = $this->vendor('Acme');

        // A healthy examination and a poor one, so the bands cannot both land in
        // the same bucket by accident.
        $this->exam($this->worker($vendor, 'Healthy'), [
            'fitness_status' => 'Fit', 'exam_date' => now()->toDateString(),
            'height_cm' => 172, 'weight_kg' => 68, 'bp_systolic' => 118, 'bp_diastolic' => 78, 'spo2' => 98,
        ], MedicalQcStatus::APPROVED);

        $this->exam($this->worker($vendor, 'Poorly'), [
            'fitness_status' => 'Unfit', 'exam_date' => now()->toDateString(),
            'height_cm' => 165, 'weight_kg' => 110, 'bp_systolic' => 170, 'bp_diastolic' => 106, 'spo2' => 89,
        ], MedicalQcStatus::APPROVED);

        Sanctum::actingAs($this->user('admin'));
        $health = $this->getJson('/api/tpv/medical/report')->assertOk()->json('data.health');

        $this->assertSame(2, $health['scored']);
        $this->assertNotNull($health['average']);
        $this->assertGreaterThan($health['worst'], $health['best']);

        $bands = collect($health['bands'])->keyBy('band');
        $this->assertSame(4, $bands->count(), 'every band is reported, including the empty ones');
        $this->assertSame(1, $bands['Excellent']['count']);
        $this->assertSame(1, $bands['Poor']['count']);
        $this->assertEquals(50, $bands['Poor']['percent']);
    }

    public function test_rejection_reasons_are_grouped_so_a_pattern_is_visible(): void
    {
        $vendor = $this->vendor('Acme');

        foreach (range(1, 3) as $i) {
            $this->exam($this->worker($vendor, "W{$i}"), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()],
                MedicalQcStatus::HOLD, 'illegible_document');
        }
        $this->exam($this->worker($vendor, 'W4'), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()],
            MedicalQcStatus::REJECTED, 'age_limit');

        Sanctum::actingAs($this->user('admin'));
        $reasons = collect($this->getJson('/api/tpv/medical/report')->assertOk()->json('data.rejections'));

        // Three certificates held for one reason is a scanning problem, and the
        // report has to make that visible rather than listing four one-offs.
        $top = $reasons->first();
        $this->assertSame('illegible_document', $top['reason']);
        $this->assertSame(3, $top['count']);
        $this->assertSame(3, $top['held']);
        $this->assertSame('Document illegible or incomplete', $top['label']);
    }

    public function test_the_date_range_and_vendor_filters_narrow_the_report(): void
    {
        $acme = $this->vendor('Acme');
        $bolt = $this->vendor('Bolt');

        $this->exam($this->worker($acme), ['fitness_status' => 'Fit', 'exam_date' => now()->subMonths(6)->toDateString()]);
        $this->exam($this->worker($acme), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        $this->exam($this->worker($bolt), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);

        Sanctum::actingAs($this->user('admin'));

        $recent = $this->getJson('/api/tpv/medical/report?from='.now()->subMonth()->toDateString())
            ->assertOk()->json('data.totals');
        $this->assertSame(2, $recent['examinations']);

        $justAcme = $this->getJson('/api/tpv/medical/report?vendor_id='.$acme->id)
            ->assertOk()->json('data.totals');
        $this->assertSame(2, $justAcme['examinations']);
        $this->assertSame(1, $justAcme['vendors']);
    }

    public function test_the_month_series_is_oldest_first_for_a_trend_line(): void
    {
        $vendor = $this->vendor('Acme');
        $this->exam($this->worker($vendor), ['fitness_status' => 'Fit', 'exam_date' => now()->subMonths(2)->toDateString()]);
        $this->exam($this->worker($vendor), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);

        Sanctum::actingAs($this->user('admin'));
        $months = $this->getJson('/api/tpv/medical/report')->assertOk()->json('data.by_month');

        $this->assertCount(2, $months);
        $this->assertTrue($months[0]['month'] < $months[1]['month']);
    }

    public function test_the_report_names_the_examining_doctor(): void
    {
        $vendor = $this->vendor('Acme');
        $worker = $this->worker($vendor);

        $doctor = $this->user('doctor');
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $doctor->id, 'license_no' => 'MH-77', 'is_active' => true,
        ]);

        app(TpvMedicalWorkflowService::class)->record(
            $worker, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()],
            $doctor, MedicalWorkflow::ORIGIN_DOCTOR_PORTAL,
        );

        Sanctum::actingAs($this->user('admin'));
        $doctors = $this->getJson('/api/tpv/medical/report')->assertOk()->json('data.by_doctor');

        $this->assertSame($doctor->name, $doctors[0]['doctor']);
        $this->assertSame('MH-77', $doctors[0]['licence']);
        $this->assertSame(1, $doctors[0]['examinations']);
    }

    public function test_the_vendor_table_exports_as_a_spreadsheet(): void
    {
        $vendor = $this->vendor('Acme');
        $this->exam($this->worker($vendor), ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()], MedicalQcStatus::APPROVED);

        Sanctum::actingAs($this->user('admin'));
        $csv = $this->get('/api/tpv/medical/report/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Vendor', $csv);
        $this->assertStringContainsString('Success rate', $csv);
        $this->assertStringContainsString('Acme', $csv);
    }

    public function test_the_report_is_closed_to_a_vendor_login(): void
    {
        Sanctum::actingAs($this->user('third_party_vendor'));
        $this->getJson('/api/tpv/medical/report')->assertStatus(403);
    }
}
