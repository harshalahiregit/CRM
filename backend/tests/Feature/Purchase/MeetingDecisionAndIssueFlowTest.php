<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseMomDecisionStatus as DecisionStatus;
use App\Support\Purchase\PurchaseMomIssueStatus as IssueStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the DECISION and ISSUES RAISED sections at the bottom of a meeting
 * actually do, walked end to end with dummy data.
 *
 * SIR-000030 item 5 asked exactly that — "check process flow for DECISION and
 * ISSUES RAISED sections in bottom that how it works, try with dummy data" — and
 * it is a fair question, because the two look alike on the form and behave
 * nothing alike underneath:
 *
 *  • A DECISION is content. It is what the meeting decided, it is edited by
 *    whoever edits the meeting, and its only lifecycle is Active → Superseded /
 *    Rescinded when a later meeting overrules it.
 *  • An ISSUE is a record with a life of its own. It opens on the day it is
 *    raised, is worked afterwards by people who may never open this form again,
 *    and moves Open → In Progress → Resolved → Closed under a transition map.
 *
 * That difference is the whole reason these tests exist. Re-saving the meeting
 * form has to be able to correct the wording of an issue without dragging its
 * status back to Open — otherwise a fortnight of somebody's work disappears
 * because a chairperson fixed a typo.
 */
class MeetingDecisionAndIssueFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        Sanctum::actingAs($this->admin());
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name = 'Southgate'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name.' '.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(6)).'@t.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    /** The body the meeting form posts: an agenda item, a decision, an issue. */
    private function meeting(array $extra = []): array
    {
        return $this->postJson('/api/purchase/kickoff', array_merge([
            'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'HSSE review '.Str::random(4),
            'meeting_type' => 'hse',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'chairperson' => 'Dana Chair',
            'agenda_items' => [
                ['id' => 'tmp-1', 'item' => 'Permit compliance', 'owner' => 'Dana Chair', 'duration_minutes' => 20],
            ],
            'decisions' => [
                ['id' => 'tmp-2', 'decision' => 'Scaffold work paused until permits are current',
                 'decided_by' => 'Dana Chair', 'impact' => 'Schedule', 'status' => DecisionStatus::ACTIVE],
            ],
            'issues' => [
                ['id' => 'tmp-3', 'title' => 'Two permits lapsed', 'description' => 'Found during the site walk.',
                 'category' => 'Compliance', 'severity' => 'High', 'owner' => 'Vic Vendor'],
            ],
        ], $extra))->assertCreated()->json();
    }

    private function show(int $id): array
    {
        return $this->getJson("/api/purchase/kickoff/{$id}")->assertOk()->json('data')
            ?? $this->getJson("/api/purchase/kickoff/{$id}")->assertOk()->json();
    }

    private function progress(int $meetingId, int $issueId, string $to)
    {
        return $this->postJson("/api/purchase/kickoff/{$meetingId}/issues/{$issueId}/progress", ['status' => $to]);
    }

    /* ── what saving the form produces ──────────────────────────── */

    public function test_saving_the_form_files_a_numbered_decision_and_an_open_issue(): void
    {
        $m = $this->meeting();
        $body = $this->show($m['id']);

        $decision = $body['mom_decisions'][0] ?? $body['decisions'][0];
        $issue = $body['mom_issues'][0] ?? $body['issues'][0];

        // Each gets its own reference, so either can be quoted in a mail without
        // sending the whole minutes with it.
        $this->assertNotEmpty($decision['decision_ref']);
        $this->assertNotEmpty($issue['issue_ref']);

        // A decision is in force the moment it is recorded; an issue is a job
        // that has not been done yet. Different defaults, on purpose.
        $this->assertSame(DecisionStatus::ACTIVE, $decision['status']);
        $this->assertSame(IssueStatus::OPEN, $issue['status']);
    }

    public function test_the_two_sections_stay_separate(): void
    {
        $m = $this->meeting();
        $body = $this->show($m['id']);

        // They sit next to each other on the form and read alike; if one write
        // ever fed both, the register counts would be double everywhere.
        $this->assertCount(1, $body['mom_decisions'] ?? $body['decisions']);
        $this->assertCount(1, $body['mom_issues'] ?? $body['issues']);
    }

    /* ── the issue's life after the meeting ─────────────────────── */

    public function test_an_issue_walks_from_open_to_closed(): void
    {
        $m = $this->meeting();
        $issue = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];

        foreach ([IssueStatus::IN_PROGRESS, IssueStatus::RESOLVED, IssueStatus::CLOSED] as $to) {
            $this->progress($m['id'], $issue['id'], $to)->assertOk();
        }

        $after = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];
        $this->assertSame(IssueStatus::CLOSED, $after['status']);
    }

    public function test_a_closed_issue_can_be_reopened(): void
    {
        $m = $this->meeting();
        $issue = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];

        foreach ([IssueStatus::IN_PROGRESS, IssueStatus::RESOLVED, IssueStatus::CLOSED, IssueStatus::REOPENED] as $to) {
            $this->progress($m['id'], $issue['id'], $to)->assertOk();
        }

        // The thing came back. Closing is not the end of the road, and the
        // register has to be able to show it as open again.
        $after = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];
        $this->assertSame(IssueStatus::REOPENED, $after['status']);
        $this->assertTrue(IssueStatus::isOpen($after['status']));
    }

    public function test_a_jump_that_skips_the_work_is_refused(): void
    {
        $m = $this->meeting();
        $issue = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];

        // Open → Closed is not on the map. An issue that was never resolved must
        // not be closable in one click, or the register stops meaning anything.
        $this->progress($m['id'], $issue['id'], IssueStatus::CLOSED)->assertStatus(422);

        $after = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];
        $this->assertSame(IssueStatus::OPEN, $after['status'], 'a refused move still changed the issue');
    }

    /* ── the part that would quietly lose work ──────────────────── */

    public function test_re_saving_the_meeting_does_not_drag_a_moved_issue_back_to_open(): void
    {
        $m = $this->meeting();
        $issue = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];
        $this->progress($m['id'], $issue['id'], IssueStatus::IN_PROGRESS)->assertOk();

        // A fortnight later the chair reopens the meeting and fixes a typo. The
        // form posts every issue row back, status included or not — and the row
        // it holds is the one it loaded, which said Open.
        $this->putJson("/api/purchase/kickoff/{$m['id']}", [
            'issues' => [[
                'id' => $issue['id'], 'title' => 'Two permits lapsed (site B)',
                'description' => 'Found during the site walk.', 'category' => 'Compliance',
                'severity' => 'High', 'owner' => 'Vic Vendor', 'status' => IssueStatus::OPEN,
            ]],
        ])->assertOk();

        $after = ($this->show($m['id'])['mom_issues'] ?? $this->show($m['id'])['issues'])[0];

        // The wording is the form's to change. The status is not.
        $this->assertSame('Two permits lapsed (site B)', $after['title']);
        $this->assertSame(IssueStatus::IN_PROGRESS, $after['status'], 'a form edit reset the issue and lost the work done on it');
    }

    public function test_re_saving_the_meeting_keeps_a_superseded_decision_superseded(): void
    {
        $m = $this->meeting();
        $decision = ($this->show($m['id'])['mom_decisions'] ?? $this->show($m['id'])['decisions'])[0];

        $this->putJson("/api/purchase/kickoff/{$m['id']}/decisions/{$decision['id']}", [
            'decision' => 'Scaffold work paused until permits are current',
            'status' => DecisionStatus::SUPERSEDED,
        ])->assertOk();

        // Same trap as the issue above: the form re-posts the row it loaded.
        $this->putJson("/api/purchase/kickoff/{$m['id']}", [
            'decisions' => [[
                'id' => $decision['id'],
                'decision' => 'Scaffold work paused until permits are current',
                'decided_by' => 'Dana Chair', 'impact' => 'Schedule and cost',
            ]],
        ])->assertOk();

        $after = ($this->show($m['id'])['mom_decisions'] ?? $this->show($m['id'])['decisions'])[0];
        $this->assertSame('Schedule and cost', $after['impact']);
        $this->assertSame(DecisionStatus::SUPERSEDED, $after['status']);
    }

    public function test_saving_only_one_section_leaves_the_other_alone(): void
    {
        $m = $this->meeting();

        // The form saves a section at a time. A payload with no `issues` key is
        // not the same as a payload with an empty one.
        $this->putJson("/api/purchase/kickoff/{$m['id']}", [
            'decisions' => [['decision' => 'A second call, made later', 'decided_by' => 'Dana Chair']],
        ])->assertOk();

        $body = $this->show($m['id']);
        $this->assertCount(1, $body['mom_issues'] ?? $body['issues'], 'saving decisions deleted the issues');
    }

    /* ── where they end up ──────────────────────────────────────── */

    public function test_both_reach_their_registers(): void
    {
        $m = $this->meeting();

        $issues = $this->getJson('/api/purchase/kickoff/registers/issues')->assertOk()->json();
        $decisions = $this->getJson('/api/purchase/kickoff/registers/decisions')->assertOk()->json();

        // The point of the sections: they are readable across every meeting, not
        // only by opening the minutes they were raised in.
        $this->assertSame('Two permits lapsed', $issues[0]['title']);
        $this->assertTrue($issues[0]['is_open']);
        $this->assertSame('Scaffold work paused until permits are current', $decisions[0]['decision']);
    }

    public function test_a_closed_issue_sorts_below_the_ones_still_needing_work(): void
    {
        $first = $this->meeting();
        $issue = ($this->show($first['id'])['mom_issues'] ?? $this->show($first['id'])['issues'])[0];
        foreach ([IssueStatus::IN_PROGRESS, IssueStatus::RESOLVED, IssueStatus::CLOSED] as $to) {
            $this->progress($first['id'], $issue['id'], $to)->assertOk();
        }

        $this->meeting(['issues' => [
            ['id' => 'tmp-9', 'title' => 'Fire extinguisher out of date', 'category' => 'Safety', 'severity' => 'Critical'],
        ]]);

        $rows = $this->getJson('/api/purchase/kickoff/registers/issues')->assertOk()->json();

        // Somebody opening this register wants the work, not the history.
        $this->assertSame('Fire extinguisher out of date', $rows[0]['title']);
        $this->assertTrue($rows[0]['is_open']);
    }

    public function test_another_tenants_meeting_is_not_on_the_register(): void
    {
        $this->meeting();

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        Sanctum::actingAs(User::create([
            'tenant_id' => 2, 'name' => 'Other', 'role' => 'admin',
            'email' => 'other-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->assertSame([], $this->getJson('/api/purchase/kickoff/registers/issues')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/purchase/kickoff/registers/decisions')->assertOk()->json());
    }
}
