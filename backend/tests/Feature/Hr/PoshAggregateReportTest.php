<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrPoshCase;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Posh\PoshAggregateReportService;
use App\Support\Hr\DataScope;
use App\Support\Hr\Decision\Decision;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POSH statistics, and the two things that make them safe.
 *
 * SUPPRESSION, which is easy, and SECONDARY suppression, which is the part
 * that actually does the work. Hiding a cell of 3 achieves nothing if the
 * total and the other cells are published — 3 is whatever is missing. The
 * invariant these tests exist to defend is therefore not "small cells are
 * hidden" but:
 *
 *   EVERY BREAKDOWN HAS ZERO SUPPRESSED CELLS, OR AT LEAST TWO.
 *
 * and it has to hold across by_status and by_outcome together, because both
 * count the same cases and a complete one reveals the total that the other is
 * relying on being hidden.
 */
class PoshAggregateReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-r', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'posh-r2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function staffWith(array $modules, ?Tenant $t = null): User
    {
        $t = $t ?: $this->tenant;
        $permissions = [];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $t->id, 'name' => 'R'.substr(uniqid(), -5),
            'slug' => 'r_'.substr(uniqid(), -5), 'permissions' => $permissions,
            'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $t->id, 'name' => 'S', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    private function plain(string $role = 'staff', ?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'U', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    /** N cases in one status/outcome, all dated inside the window. */
    private function cases(int $n, string $status, ?string $outcome = null, ?Tenant $t = null): void
    {
        $t = $t ?: $this->tenant;

        for ($i = 0; $i < $n; $i++) {
            HrPoshCase::create([
                'tenant_id' => $t->id,
                'reference' => 'POSH-'.$t->id.'-'.uniqid(),
                'committee_id' => 1,
                'narrative' => 'x',
                'status' => $status,
                'outcome' => $outcome,
                'complaint_received_at' => now()->subDays(10),
            ]);
        }
    }

    private function summary(array $query = []): array
    {
        return $this->getJson('/api/hr/posh-reports/summary?'.http_build_query($query))
            ->assertOk()->json('data');
    }

    /** Every cell in the response, flattened. */
    private function allCells(array $data): array
    {
        $out = [];
        foreach ($data['periods'] as $p) {
            foreach (['by_status', 'by_outcome'] as $dim) {
                foreach ($p[$dim] as $cell) {
                    $out[] = $cell;
                }
            }
        }

        return $out;
    }

    /** The invariant, asserted wherever a report is produced. */
    private function assertSuppressionInvariant(array $data): void
    {
        foreach ($data['periods'] as $p) {
            foreach (['by_status', 'by_outcome'] as $dim) {
                $suppressed = count(array_filter($p[$dim], fn ($c) => $c['suppressed']));

                $this->assertTrue(
                    $suppressed === 0 || $suppressed >= 2,
                    "{$p['period']} {$dim} has exactly {$suppressed} suppressed cell(s); "
                    .'one can always be recovered by subtraction'
                );
            }
        }
    }

    /* ── authorisation ────────────────────────────────────────────────── */

    public function test_the_report_needs_its_own_capability(): void
    {
        $this->cases(6, HrPoshCase::STATUS_CLOSED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $this->getJson('/api/hr/posh-reports/summary')->assertOk();

        foreach ([
            $this->staffWith(['hr_settings']),
            $this->staffWith(['hr_posh_intake']),
            $this->staffWith(['hr_attendance']),
        ] as $wrong) {
            Sanctum::actingAs($wrong);
            $this->getJson('/api/hr/posh-reports/summary')->assertStatus(403);
        }
    }

    public function test_an_admin_may_read_the_numbers_but_not_a_case(): void
    {
        $this->cases(6, HrPoshCase::STATUS_CLOSED);
        $case = HrPoshCase::first();

        // Unlike hr_posh_intake, this capability uses the ordinary bypass:
        // counts are not case content.
        Sanctum::actingAs($this->plain('admin'));
        $this->getJson('/api/hr/posh-reports/summary')->assertOk();

        // And it still opens nothing.
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
        $this->getJson("/api/hr/posh-cases/{$case->id}/findings")->assertStatus(404);
    }

    public function test_the_reports_capability_is_unknown_to_the_access_layer(): void
    {
        // Behaviour above proves the outcome; this proves the cause. A
        // capability that never appears in the access files cannot become a
        // way in later without somebody deliberately typing it there.
        foreach ([
            'app/Services/Hr/Posh/PoshAccessResolver.php',
            'app/Services/Hr/Posh/PoshCaseAuthority.php',
            'app/Services/Hr/Posh/PoshCaseService.php',
            'app/Services/Hr/Posh/PoshComplainantView.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'hr_posh_reports',
                file_get_contents(base_path($file)),
                "{$file} refers to hr_posh_reports"
            );
        }
    }

    /* ── suppression ──────────────────────────────────────────────────── */

    public function test_counts_of_five_and_above_are_published(): void
    {
        $this->cases(7, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $period = $data['periods'][0];
        $this->assertSame(7, $period['total']);
        $this->assertFalse($period['total_suppressed']);

        $closed = collect($period['by_status'])->firstWhere('status', HrPoshCase::STATUS_CLOSED);
        $this->assertSame(7, $closed['count']);
        $this->assertFalse($closed['suppressed']);

        $this->assertSuppressionInvariant($data);
    }

    public function test_a_small_period_is_withheld_entirely(): void
    {
        $this->cases(3, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $period = $data['periods'][0];
        $this->assertNull($period['total']);
        $this->assertTrue($period['total_suppressed']);

        // Every cell, not merely the small one: each is bounded by a total
        // nobody may see.
        foreach ($this->allCells($data) as $cell) {
            $this->assertTrue($cell['suppressed']);
            $this->assertNull($cell['count']);
        }
    }

    public function test_a_lone_small_cell_takes_a_second_one_with_it(): void
    {
        // 3 withdrawn + 40 closed. Hiding only the 3 while publishing 40 and a
        // total of 43 hides nothing at all.
        $this->cases(3, HrPoshCase::STATUS_WITHDRAWN, Decision::OUTCOME_REJECTED);
        $this->cases(40, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $period = $data['periods'][0];
        $status = collect($period['by_status']);

        $this->assertTrue($status->firstWhere('status', HrPoshCase::STATUS_WITHDRAWN)['suppressed']);
        // The 40 goes too. Real detail is lost to protect the 3, and that is
        // the trade being made deliberately.
        $this->assertTrue($status->firstWhere('status', HrPoshCase::STATUS_CLOSED)['suppressed']);

        $this->assertNull($period['total']);
        $this->assertSuppressionInvariant($data);
    }

    public function test_a_suppressed_cell_cannot_be_recovered_by_subtraction(): void
    {
        $this->cases(3, HrPoshCase::STATUS_WITHDRAWN, Decision::OUTCOME_REJECTED);
        $this->cases(40, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        // The differencing attack, run for real: take every number the report
        // is willing to publish and try to reconstruct a hidden one.
        foreach ($data['periods'] as $period) {
            foreach (['by_status', 'by_outcome'] as $dim) {
                $hidden = array_filter($period[$dim], fn ($c) => $c['suppressed']);

                if ($hidden === []) {
                    continue;
                }

                // With the total withheld there is nothing to subtract from;
                // with it visible, more than one unknown makes the system
                // unsolvable.
                $this->assertTrue(
                    $period['total'] === null || count($hidden) >= 2,
                    "{$period['period']} {$dim} exposes a solvable equation"
                );
            }
        }
    }

    public function test_by_status_being_complete_cannot_unmask_by_outcome(): void
    {
        // Both cases closed — by_status is a single cell of 8, fully visible,
        // which reveals the period total by addition. by_outcome then splits
        // 8 into 6 and 2, and the 2 must not be recoverable from it.
        $this->cases(6, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);
        $this->cases(2, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_REJECTED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $period = $data['periods'][0];
        $outcome = collect($period['by_outcome']);

        $this->assertTrue($outcome->firstWhere('outcome', Decision::OUTCOME_REJECTED)['suppressed']);
        $this->assertTrue($outcome->firstWhere('outcome', Decision::OUTCOME_APPROVED)['suppressed']);

        $this->assertSuppressionInvariant($data);
    }

    public function test_zero_is_published(): void
    {
        $this->cases(9, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $received = collect($data['periods'][0]['by_status'])
            ->firstWhere('status', HrPoshCase::STATUS_RECEIVED);

        // A zero describes nobody. Hiding it would spend detail to protect
        // nothing.
        $this->assertSame(0, $received['count']);
        $this->assertFalse($received['suppressed']);
    }

    public function test_the_invariant_holds_across_many_shapes(): void
    {
        // Every awkward size around the threshold, in one report.
        $this->cases(1, HrPoshCase::STATUS_RECEIVED, null);
        $this->cases(4, HrPoshCase::STATUS_UNDER_INQUIRY, null);
        $this->cases(5, HrPoshCase::STATUS_INQUIRY_COMPLETE, Decision::OUTCOME_APPROVED);
        $this->cases(2, HrPoshCase::STATUS_WITHDRAWN, Decision::OUTCOME_INCONCLUSIVE);
        $this->cases(30, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_REJECTED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));

        foreach ([['bucket' => 'month'], ['bucket' => 'quarter'], ['bucket' => 'year']] as $q) {
            $data = $this->summary($q);
            $this->assertSuppressionInvariant($data);

            // No published count is ever in the forbidden band.
            foreach ($this->allCells($data) as $cell) {
                if (! $cell['suppressed']) {
                    $this->assertTrue(
                        $cell['count'] === 0 || $cell['count'] >= PoshAggregateReportService::MIN_CELL,
                        'A count of '.$cell['count'].' was published'
                    );
                }
            }
        }
    }

    /* ── what the payload may contain ─────────────────────────────────── */

    public function test_the_payload_carries_no_person_or_case_dimension(): void
    {
        $this->cases(9, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);
        $case = HrPoshCase::first();

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $raw = $this->getJson('/api/hr/posh-reports/summary')->assertOk()->getContent();

        // No identifier of any kind, and nothing to drill through.
        $this->assertStringNotContainsString($case->reference, $raw);
        $this->assertStringNotContainsString('"case_id"', $raw);
        $this->assertStringNotContainsString('"narrative"', $raw);

        foreach ([
            'department', 'branch', 'designation', 'respondent', 'complainant',
            'employee_id', 'cases', 'reference',
        ] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $raw, "{$forbidden} appears in the report");
        }
    }

    public function test_the_notice_does_not_claim_anonymity(): void
    {
        $this->cases(6, HrPoshCase::STATUS_CLOSED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary();

        $this->assertSame(5, $data['min_cell']);
        $this->assertStringContainsString('not a guarantee of anonymity', $data['notice']);
    }

    /* ── tenancy ──────────────────────────────────────────────────────── */

    public function test_another_workspaces_cases_are_never_counted(): void
    {
        $this->cases(9, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);
        $this->cases(40, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED, $this->other);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $this->assertSame(9, $this->summary()['periods'][0]['total']);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports'], $this->other));
        $this->assertSame(40, $this->summary()['periods'][0]['total']);
    }

    public function test_the_window_excludes_what_falls_outside_it(): void
    {
        $this->cases(9, HrPoshCase::STATUS_CLOSED, Decision::OUTCOME_APPROVED);
        HrPoshCase::query()->limit(9)->update(['complaint_received_at' => now()->subYears(3)]);
        $this->cases(6, HrPoshCase::STATUS_RECEIVED);

        Sanctum::actingAs($this->staffWith(['hr_posh_reports']));
        $data = $this->summary(['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()]);

        $this->assertSame(6, collect($data['periods'])->sum(fn ($p) => (int) $p['total']));
    }
}
