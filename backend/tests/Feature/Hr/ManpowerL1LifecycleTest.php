<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\HRDashboardService;
use App\Services\Hr\ManpowerRequestService;
use App\Support\Hr\ManpowerRequestStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L1 restored as a real approval rung.
 *
 * The specification is explicit: "Department User → Department Head Approval
 * (L1) → Management Approval (L2) → HR Queue", and "HR should not be able to
 * start recruitment until both approvals are completed". Only one of those
 * approvals was ever real. submit() set L1_Pending and then approved L1 in the
 * same transaction, so the status never rested there — which made approveL1(),
 * rejectL1(), the L1 send-back, the "L1 Pending" dashboard tile, the per-manager
 * queue scope and the seeded Department Head and Hiring Manager role templates
 * all unreachable.
 *
 * Worse than unreachable: the automatic approval ran as whoever submitted, so a
 * plain staff member with no approval authority had their own id written to
 * l1_approver_id and rendered in the UI as the Department Head's signature.
 *
 * The intent behind that auto-approval was sound — a department head should not
 * rubber-stamp the requisition they raised themselves — and it is kept here as a
 * REFUSAL rather than a free pass.
 *
 * Nothing downstream moves: Ready_for_HR still guards JD conversion, and it is
 * still only reachable by finishing both rungs.
 */
class ManpowerL1LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /**
     * A Department Head who exists in every test but takes part in none.
     *
     * submit() refuses when nobody could approve the request, so a workspace
     * with no L1 approver at all cannot submit anything — see the
     * "no eligible approver" tests, which remove this one deliberately. Every
     * other test needs somebody standing there for submission to be possible.
     */
    private User $standingHead;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->standingHead = $this->deptHead();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $role, ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($internal ?? $role),
            'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    private function deptHead(): User { return $this->user('staff', 'department_head'); }
    private function management(): User { return $this->user('staff', 'project_manager'); }
    private function plainStaff(): User { return $this->user('staff', null); }

    /** A request complete enough to pass assertCompleteForApproval(). */
    private function draft(User $requester, array $attrs = []): HrManpowerRequest
    {
        return HrManpowerRequest::create(array_merge([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'Engineer', 'position' => 'Engineer',
            'number_of_positions' => 1, 'status' => Status::DRAFT,
            'l1_status' => 'pending', 'l2_status' => 'pending',
            'requested_by' => $requester->id,
            'job_description' => 'Build and maintain the platform services.',
            'required_skills' => ['PHP', 'Laravel'],
            'hiring_manager_id' => $requester->id,
            'employee_level' => 'Mid', 'experience_required' => '3-5 years',
        ], $attrs));
    }

    private function service(): ManpowerRequestService
    {
        return app(ManpowerRequestService::class);
    }

    /** Submit and return the request resting on L1. */
    private function submitted(User $requester): HrManpowerRequest
    {
        $mr = $this->draft($requester);
        $this->service()->submit($mr, $requester);

        return $mr->fresh();
    }

    /* ── 1. submission rests at L1 ────────────────────────────────────── */

    public function test_an_ordinary_submission_rests_at_l1_pending(): void
    {
        $requester = $this->plainStaff();

        $mr = $this->submitted($requester);

        $this->assertSame(Status::L1_PENDING, $mr->status);
        $this->assertSame('pending', $mr->l1_status);
        $this->assertNotNull($mr->submitted_at);
    }

    public function test_submission_never_stamps_an_l1_approver(): void
    {
        // The defect in one assertion. This submitter cannot approve L1, and
        // before this change their own id was written here as the Department
        // Head's approval.
        $requester = $this->plainStaff();
        $this->assertFalse($requester->canApproveL1());

        $mr = $this->submitted($requester);

        $this->assertNull($mr->l1_approver_id);
        $this->assertNull($mr->l1_approved_at);
        $this->assertNotSame('approved', $mr->l1_status);
    }

    public function test_even_a_submitter_who_holds_l1_authority_is_not_auto_approved(): void
    {
        // A department head raising their own headcount request is exactly the
        // case the old auto-approval was reaching for. It still does not
        // approve itself — it waits for somebody else.
        $head = $this->deptHead();

        $mr = $this->submitted($head);

        $this->assertSame(Status::L1_PENDING, $mr->status);
        $this->assertNull($mr->l1_approver_id);
    }

    /* ── 1b. a submission nobody could approve is refused, not stranded ── */

    public function test_a_workspace_with_no_l1_approver_cannot_submit(): void
    {
        // The only L1-capable account is removed, so there is nobody to decide.
        $this->standingHead->delete();
        $requester = $this->plainStaff();
        $mr = $this->draft($requester);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Nobody in this workspace can give Department Head (L1) approval');
        $this->service()->submit($mr, $requester);
    }

    public function test_a_refused_submission_leaves_the_request_editable(): void
    {
        $this->standingHead->delete();
        $requester = $this->plainStaff();
        $mr = $this->draft($requester);

        try {
            $this->service()->submit($mr, $requester);
        } catch (BusinessException) {
            // expected
        }

        // Refused, not half-done: the request must not be sitting at L1_Pending
        // with nobody able to move it, which is the stall this check exists to
        // prevent.
        $this->assertSame(Status::DRAFT, $mr->fresh()->status);
        $this->assertNull($mr->fresh()->submitted_at);
    }

    public function test_the_sole_l1_approver_cannot_submit_their_own_request(): void
    {
        // The deadlock case. This department head is the only L1 approver, and
        // nobody approves what they raised — so there is genuinely no route
        // through, and submission says so instead of stalling silently.
        $this->standingHead->delete();
        $head = $this->deptHead();
        $mr = $this->draft($head);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('you cannot approve one you raised yourself');
        $this->service()->submit($mr, $head);
    }

    public function test_a_second_l1_approver_unblocks_that_submission(): void
    {
        $this->standingHead->delete();
        $head = $this->deptHead();
        $this->deptHead();          // a colleague at the same level

        $mr = $this->draft($head);
        $this->service()->submit($mr, $head);

        $this->assertSame(Status::L1_PENDING, $mr->fresh()->status);
    }

    public function test_an_admin_counts_as_an_l1_approver(): void
    {
        // canApproveL1() admits an admin, so a workspace that has not set up
        // department heads yet is not stopped — it is the same reading the
        // approval gate itself takes, not a bypass invented here.
        $this->standingHead->delete();
        $this->user('admin');
        $requester = $this->plainStaff();

        $mr = $this->draft($requester);
        $this->service()->submit($mr, $requester);

        $this->assertSame(Status::L1_PENDING, $mr->fresh()->status);
    }

    public function test_an_inactive_approver_does_not_count(): void
    {
        $this->standingHead->update(['status' => 'inactive']);
        $requester = $this->plainStaff();
        $mr = $this->draft($requester);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Nobody in this workspace can give Department Head (L1) approval');
        $this->service()->submit($mr, $requester);
    }

    /* ── 2. only an authorised L1 approver may act ────────────────────── */

    public function test_an_unauthorised_user_cannot_approve_l1(): void
    {
        $requester = $this->plainStaff();
        $mr = $this->submitted($requester);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('not authorised to give L1');
        $this->service()->approveL1($mr, $this->plainStaff(), 'go on then');
    }

    public function test_management_alone_cannot_approve_l1(): void
    {
        $mr = $this->submitted($this->plainStaff());

        // canApproveL2 is true for a project manager; canApproveL1 is not.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('not authorised to give L1');
        $this->service()->approveL1($mr, $this->management(), 'skipping the dept head');
    }

    public function test_nobody_approves_l1_on_the_request_they_raised(): void
    {
        $head = $this->deptHead();
        $mr = $this->submitted($head);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot approve a manpower request you raised yourself');
        $this->service()->approveL1($mr, $head, 'my own headcount');
    }

    public function test_an_authorised_department_head_advances_the_request_to_l2(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $head = $this->deptHead();

        $result = $this->service()->approveL1($mr, $head, 'Headcount justified');

        $this->assertSame(Status::L2_PENDING, $result->status);
        $this->assertSame('approved', $result->l1_status);
        $this->assertSame($head->id, $result->l1_approver_id);
        $this->assertNotNull($result->l1_approved_at);
    }

    public function test_an_authorised_department_head_can_reject(): void
    {
        $mr = $this->submitted($this->plainStaff());

        $result = $this->service()->rejectL1($mr, $this->deptHead(), 'Not budgeted this quarter');

        $this->assertSame(Status::REJECTED, $result->status);
        $this->assertSame('rejected', $result->l1_status);
        $this->assertStringContainsString('[L1]', $result->rejection_reason);
    }

    public function test_send_back_returns_the_request_to_an_editable_draft(): void
    {
        $mr = $this->submitted($this->plainStaff());

        $result = $this->service()->sendBack($mr, $this->deptHead(), 'Add the skills matrix');

        $this->assertSame(Status::DRAFT, $result->status);
        $this->assertSame('pending', $result->l1_status);
        $this->assertNull($result->submitted_at);
        $this->assertNull($result->l1_approver_id);
    }

    /* ── 3. L1 cannot be bypassed ─────────────────────────────────────── */

    public function test_l2_cannot_be_approved_while_the_request_is_still_at_l1(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $this->assertSame(Status::L1_PENDING, $mr->status);

        // The gate that makes "both approvals" true rather than aspirational.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('not pending L2 approval');
        $this->service()->approveL2($mr, $this->management(), 'straight through');
    }

    public function test_the_hr_queue_is_reached_only_after_both_rungs(): void
    {
        $mr = $this->submitted($this->plainStaff());

        // L1 alone is not enough — still not fully approved.
        $afterL1 = $this->service()->approveL1($mr, $this->deptHead(), 'ok');
        $this->assertFalse(Status::isFullyApproved($afterL1->status));
        $this->assertFalse($afterL1->canConvertToJd());

        $afterL2 = $this->service()->approveL2($afterL1->fresh(), $this->management(), 'funded');

        $this->assertSame(Status::READY_FOR_HR, $afterL2->status);
        $this->assertTrue(Status::isFullyApproved($afterL2->status));
        $this->assertTrue($afterL2->canConvertToJd());
    }

    public function test_a_half_approved_request_cannot_be_converted_to_a_jd(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $this->service()->approveL1($mr, $this->deptHead(), 'ok');

        // The downstream gate is unchanged and still does the work.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only fully-approved');
        $this->service()->convertToJd($mr->fresh(), $this->user('admin'));
    }

    public function test_jd_conversion_still_works_for_a_fully_approved_request(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $this->service()->approveL1($mr, $this->deptHead(), 'ok');
        $this->service()->approveL2($mr->fresh(), $this->management(), 'funded');

        $result = $this->service()->convertToJd($mr->fresh(), $this->user('admin'));

        $this->assertSame(Status::CONVERTED_TO_JD, $result->status);
        $this->assertNotNull($result->job_posting_id);
    }

    /* ── 4. reconsider reopens, it does not approve ───────────────────── */

    public function test_reconsider_does_not_approve_l1(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $head = $this->deptHead();
        $this->service()->rejectL1($mr, $head, 'changed my mind later');

        $result = $this->service()->reconsider($mr->fresh(), $head, 'Finance released the budget');

        $this->assertSame(Status::L1_PENDING, $result->status);
        $this->assertSame('pending', $result->l1_status);
        $this->assertNull($result->l1_approver_id, 'reopening is not a decision');
    }

    public function test_a_reopened_request_still_has_to_be_approved_explicitly(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $head = $this->deptHead();
        $this->service()->rejectL1($mr, $head, 'no');
        $this->service()->reconsider($mr->fresh(), $head, 'reopening');

        $result = $this->service()->approveL1($mr->fresh(), $head, 'approved on review');

        $this->assertSame(Status::L2_PENDING, $result->status);
        $this->assertSame($head->id, $result->l1_approver_id);
    }

    /* ── 5. the audit trail names the real approver ───────────────────── */

    public function test_the_audit_entry_names_the_authorised_l1_approver(): void
    {
        $requester = $this->plainStaff();
        $mr = $this->submitted($requester);
        $head = $this->deptHead();

        $this->service()->approveL1($mr, $head, 'Headcount justified');

        $entry = $mr->fresh()->auditLogs()->where('action', 'Approved')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($head->id, (int) $entry->actor_id);
        $this->assertNotSame($requester->id, (int) $entry->actor_id);
    }

    public function test_the_dead_legacy_history_table_is_left_alone(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $this->service()->approveL1($mr, $this->deptHead(), 'ok');

        // Audit lives in audit_logs. hr_approval_history was superseded and
        // migrated across; nothing revives it just because it still exists.
        $this->assertSame(0, \DB::table('hr_approval_history')->count());
        $this->assertTrue($mr->fresh()->auditLogs()->exists());
    }

    /* ── 6. the queues and dashboards that were always empty ──────────── */

    public function test_the_l1_queue_now_reports_the_waiting_request(): void
    {
        $this->submitted($this->plainStaff());
        $head = $this->deptHead();

        $queue = $this->service()->pendingApprovals($head);

        $this->assertSame(1, $queue['l1_count']);
        $this->assertCount(1, $queue['l1_pending']);
    }

    public function test_the_dashboard_counts_l1_pending(): void
    {
        $this->submitted($this->plainStaff());
        $head = $this->deptHead();

        $data = app(HRDashboardService::class)->summary($head);

        $this->assertSame(1, $data['manpower']['l1_pending']);
        $this->assertSame(0, $data['manpower']['l2_pending']);
        $this->assertGreaterThanOrEqual(1, $data['kpis']['pending_approvals'] ?? 0);
    }

    public function test_a_department_head_sees_the_l1_queue_over_http(): void
    {
        $this->submitted($this->plainStaff());
        $head = $this->deptHead();

        Sanctum::actingAs($head);

        $this->getJson('/api/hr/manpower-requests/pending-approvals')
            ->assertOk()
            ->assertJsonPath('data.l1_count', 1);
    }

    /* ── 7. notifications, because a blocking rung must be announced ──── */

    public function test_submission_notifies_the_l1_approvers(): void
    {
        $head = $this->deptHead();
        $requester = $this->plainStaff();

        $this->submitted($requester);

        $this->assertTrue(
            Notification::where('user_id', $head->id)->where('type', 'hr_manpower_approval')->exists(),
            'a request that blocks on the Department Head has to tell the Department Head'
        );
    }

    public function test_l1_approval_notifies_management(): void
    {
        $management = $this->management();
        $mr = $this->submitted($this->plainStaff());

        $this->service()->approveL1($mr, $this->deptHead(), 'ok');

        $this->assertTrue(
            Notification::where('user_id', $management->id)->where('type', 'hr_manpower_approval')->exists()
        );
    }

    public function test_a_rejection_reaches_the_requester(): void
    {
        $requester = $this->plainStaff();
        $mr = $this->submitted($requester);

        $this->service()->rejectL1($mr, $this->deptHead(), 'Not budgeted');

        $this->assertTrue(
            Notification::where('user_id', $requester->id)->where('type', 'hr_manpower_decision')->exists()
        );
    }

    public function test_a_send_back_reaches_the_requester(): void
    {
        $requester = $this->plainStaff();
        $mr = $this->submitted($requester);

        $this->service()->sendBack($mr, $this->deptHead(), 'Add the skills matrix');

        $this->assertTrue(
            Notification::where('user_id', $requester->id)
                ->where('title', 'Manpower request sent back for revision')->exists()
        );
    }

    public function test_full_approval_reaches_the_requester(): void
    {
        $requester = $this->plainStaff();
        $mr = $this->submitted($requester);
        $this->service()->approveL1($mr, $this->deptHead(), 'ok');
        $this->service()->approveL2($mr->fresh(), $this->management(), 'funded');

        $this->assertTrue(
            Notification::where('user_id', $requester->id)
                ->where('title', 'Manpower request fully approved')->exists()
        );
    }

    public function test_a_notification_failure_cannot_lose_an_approval(): void
    {
        $mr = $this->submitted($this->plainStaff());
        $head = $this->deptHead();

        // A real failure, not a simulated one: with the table gone every insert
        // throws. notify() swallows and logs, so the approval must still land.
        // An approval lost because a bell could not be rung would be a far worse
        // defect than a missing notification.
        \Schema::drop('notifications');

        $result = $this->service()->approveL1($mr, $head, 'ok');

        $this->assertSame(Status::L2_PENDING, $result->status);
        $this->assertSame($head->id, $result->l1_approver_id);
    }
}
