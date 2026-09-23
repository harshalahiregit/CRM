<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Report;
use Sire\Models\ReportSeverity;
use Sire\Support\SireStatus;
use Tests\TestCase;

/**
 * SIRE — "still open" and "still needs fixing" are not the same question.
 *
 * The register's `open` scope means NOT TERMINAL, which is right for a work
 * queue: an issue that has been fixed and shipped is still open, because
 * somebody still owes it a close. The developer brief took that same scope, and
 * so it carried issues that were already fixed, already released, even already
 * validated in production — in front of the person being asked to fix them.
 *
 * `unresolved` is `open` minus the six fix-submitted states. Both scopes stay,
 * because both questions are real; this holds down the difference between them.
 */
class SireUnresolvedScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $lead;
    private ReportSeverity $high;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->lead = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'name' => 'Asha Lead',
        ]);
        $this->high = ReportSeverity::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 's2', 'name' => 'High', 'level' => 3,
        ]);

        Sanctum::actingAs($this->lead);
    }

    private function issue(string $status, string $title): Report
    {
        return Report::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'severity_id' => $this->high->id,
            'status'      => $status,
            'title'       => $title,
            'module'      => 'purchase',
        ]);
    }

    /** @return array<int, string> */
    private function numbersIn(string $scope): array
    {
        $rows = $this->getJson('/api/sire/dashboard/register?scope='.$scope)
            ->assertOk()
            ->json('data');

        return array_column($rows, 'report_number');
    }

    public function test_unresolved_leaves_out_every_issue_that_already_has_a_fix(): void
    {
        $needsCode = [
            $this->issue(SireStatus::NEW, 'Nobody has looked at this'),
            $this->issue(SireStatus::TRIAGED, 'Triaged, unassigned'),
            $this->issue(SireStatus::ASSIGNED, 'Assigned, not started'),
            $this->issue(SireStatus::IN_DEVELOPMENT, 'Being written now'),
            // A failed QA pass sends it BACK to the developer, so it needs code
            // again — the one state that looks like QA and is not.
            $this->issue(SireStatus::QA_FAILED, 'QA sent it back'),
            $this->issue(SireStatus::REOPENED, 'It came back from production'),
            $this->issue(SireStatus::ON_HOLD, 'Parked, still unfixed'),
        ];

        foreach (SireStatus::FIX_SUBMITTED as $status) {
            $this->issue($status, 'Fix written — '.$status);
        }

        $unresolved = $this->numbersIn('unresolved');

        foreach ($needsCode as $report) {
            $this->assertContains(
                $report->report_number,
                $unresolved,
                $report->status.' still needs code written and must be in the brief.',
            );
        }

        $this->assertCount(
            count($needsCode),
            $unresolved,
            'A fix-submitted issue reached the developer brief.',
        );
    }

    public function test_open_still_carries_them_because_they_are_not_closed(): void
    {
        $shipped = $this->issue(SireStatus::PRODUCTION_VALIDATED, 'Live and verified');
        $fresh   = $this->issue(SireStatus::NEW, 'Filed this morning');

        $open = $this->numbersIn('open');

        // The work queue is a different question and keeps its answer: this
        // issue is not closed, and somebody owes it a close.
        $this->assertContains($shipped->report_number, $open);
        $this->assertContains($fresh->report_number, $open);

        $this->assertNotContains($shipped->report_number, $this->numbersIn('unresolved'));
    }

    public function test_a_closed_issue_is_in_neither(): void
    {
        $closed = $this->issue(SireStatus::CLOSED, 'Done and dusted');
        $wontFix = $this->issue(SireStatus::WONT_FIX, 'Not doing it');

        foreach (['open', 'unresolved'] as $scope) {
            $numbers = $this->numbersIn($scope);
            $this->assertNotContains($closed->report_number, $numbers, $scope.' carried a closed issue.');
            $this->assertNotContains($wontFix->report_number, $numbers, $scope.' carried a won\'t-fix issue.');
        }
    }

    /**
     * The scope reaches the export, not just the register — the export is the
     * only reason this scope exists.
     */
    public function test_the_brief_honours_it_and_says_so_in_its_header(): void
    {
        $shipped = $this->issue(SireStatus::RELEASED, 'Shipped last Tuesday');
        $todo    = $this->issue(SireStatus::ASSIGNED, 'Waiting on a developer');

        $brief = $this->get('/api/sire/export?scope=unresolved')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($todo->report_number, $brief);
        $this->assertStringNotContainsString($shipped->report_number, $brief);

        // Printed in the header, so the file can be checked after the fact by
        // whoever opens it rather than only by whoever exported it.
        $this->assertStringContainsString('scope: `unresolved`', $brief);
    }

    /** An invented scope must be refused, not silently treated as "everything". */
    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->getJson('/api/sire/dashboard/register?scope=solved')
            ->assertStatus(422);
    }
}
