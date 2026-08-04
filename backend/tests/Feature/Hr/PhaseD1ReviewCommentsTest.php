<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\JobPostingService;
use App\Services\Hr\ManpowerRequestService;
use App\Support\Hr\ManpowerRequestStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase D1 of the original 45 review comments — the parts with backend behaviour:
 *   #3 Filter option in every listing (e.g. HIRING MANAGER)
 *   #7 Option to "Approve" after a Rejected manpower requirement
 *
 * #2 (Per Month labels), #21 (popup positioning), #24 (L&D lifecycle grouping)
 * and #27 (dashboard sequence) are presentation-only and carry no server change,
 * so they are covered by the frontend build rather than by tests here.
 */
class PhaseD1ReviewCommentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->user('admin');
    }

    /* ── fixtures ─────────────────────────────────────────────────────── */

    private function user(string $role, ?string $internalRole = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'email' => $role.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internalRole,
        ]);
    }

    private function manpowerRequest(array $attrs = []): HrManpowerRequest
    {
        return HrManpowerRequest::create(array_merge([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'Engineer', 'position' => 'Engineer',
            'number_of_positions' => 1, 'status' => Status::DRAFT,
            'l1_status' => 'pending', 'l2_status' => 'pending',
            'requested_by' => $this->admin->id,
        ], $attrs));
    }

    /** A request rejected at the given level, with the field state that produces. */
    private function rejectedAt(string $level): HrManpowerRequest
    {
        return $this->manpowerRequest($level === 'L2'
            ? ['status' => Status::REJECTED, 'l1_status' => 'approved', 'l2_status' => 'rejected',
               'rejection_reason' => '[L2] Budget not cleared']
            : ['status' => Status::REJECTED, 'l1_status' => 'rejected', 'l2_status' => 'pending',
               'rejection_reason' => '[L1] Headcount not justified']);
    }

    private function service(): ManpowerRequestService
    {
        return app(ManpowerRequestService::class);
    }

    /* ── #7 — Approve after rejection ─────────────────────────────────── */

    public function test_an_l1_rejection_reversed_lands_on_l2_pending_not_the_hr_queue(): void
    {
        $mr = $this->rejectedAt('L1');

        $result = $this->service()->reconsider($mr, $this->admin, 'Headcount re-approved by Finance');

        // The whole point: reversing an L1 rejection must not skip Management.
        $this->assertSame(Status::L2_PENDING, $result->status);
        $this->assertSame('approved', $result->l1_status);
        $this->assertNull($result->rejection_reason);
    }

    public function test_an_l2_rejection_reversed_reaches_the_hr_queue(): void
    {
        $mr = $this->rejectedAt('L2');

        $result = $this->service()->reconsider($mr, $this->admin, 'Budget released');

        $this->assertSame(Status::READY_FOR_HR, $result->status);
        $this->assertSame('approved', $result->l2_status);
    }

    public function test_reversing_an_l2_rejection_keeps_l1s_original_approval(): void
    {
        $mr = $this->rejectedAt('L2');
        $l1Approver = $this->user('staff', 'department_head');
        $mr->update(['l1_approver_id' => $l1Approver->id, 'l1_approved_at' => now()]);

        $result = $this->service()->reconsider($mr, $this->admin, 'Budget released');

        // L1 genuinely approved this request; an L2 reversal must not erase that.
        $this->assertSame($l1Approver->id, $result->l1_approver_id);
        $this->assertSame('approved', $result->l1_status);
    }

    public function test_only_a_rejected_request_can_be_reconsidered(): void
    {
        $mr = $this->manpowerRequest(['status' => Status::L1_PENDING]);

        $this->expectException(BusinessException::class);
        $this->service()->reconsider($mr, $this->admin, 'Looks fine now');
    }

    public function test_an_l1_approver_cannot_reverse_a_management_rejection(): void
    {
        $mr = $this->rejectedAt('L2');
        $l1Only = $this->user('staff', 'department_head');

        // canApproveL1 is true for a department head, canApproveL2 is not.
        $this->expectException(BusinessException::class);
        $this->service()->reconsider($mr, $l1Only, 'Overruling management');
    }

    public function test_the_reversal_is_written_to_the_history(): void
    {
        $mr = $this->rejectedAt('L1');

        $this->service()->reconsider($mr, $this->admin, 'Headcount re-approved');

        $this->assertTrue(
            $mr->fresh()->auditLogs()->where('action', 'Reopened after rejection')->exists(),
            'a reversed rejection must leave a trace — it overrides someone else\'s decision'
        );
    }

    public function test_resubmit_after_rejection_still_works(): void
    {
        // #7 must not close the requester's existing route. The extra fields are
        // the ones comment #5 made mandatory before a request may be submitted.
        //
        $requester = $this->user('staff');
        $mr = $this->rejectedAt('L1');
        $mr->update([
            'requested_by' => $requester->id,
            'job_description' => 'Build and maintain the platform services.',
            'required_skills' => ['PHP', 'Laravel'],
            'hiring_manager_id' => $this->user('staff', 'department_head')->id,
            'employee_level' => 'Mid',
            'experience_required' => '3-5 years',
        ]);

        $result = $this->service()->submit($mr->fresh(), $requester);

        // L2_Pending, not L1_Pending: submit() auto-approves L1 unconditionally
        // (the SPK-1 rule that the creator no longer approves L1 by hand). So a
        // resubmit and a reconsider of an L1 rejection converge on the same
        // place, which is what makes the two routes safe to offer side by side.
        $this->assertSame(Status::L2_PENDING, $result->status);
        $this->assertNull($result->rejection_reason, 'a resubmit clears the previous rejection');
    }

    public function test_the_reconsider_endpoint_requires_remarks(): void
    {
        Sanctum::actingAs($this->admin);
        $mr = $this->rejectedAt('L1');

        $this->postJson("/api/hr/manpower-requests/{$mr->id}/reconsider", [])
            ->assertStatus(422);
    }

    public function test_the_reconsider_endpoint_approves_with_remarks(): void
    {
        Sanctum::actingAs($this->admin);
        $mr = $this->rejectedAt('L1');

        $this->postJson("/api/hr/manpower-requests/{$mr->id}/reconsider", ['remarks' => 'Cleared by Finance'])
            ->assertOk();

        $this->assertSame(Status::L2_PENDING, $mr->fresh()->status);
    }

    /* ── #3 — Hiring Manager filter ───────────────────────────────────── */

    public function test_manpower_requests_filter_by_hiring_manager(): void
    {
        $mgrA = $this->user('staff', 'department_head');
        $mgrB = $this->user('staff', 'department_head');

        $this->manpowerRequest(['hiring_manager_id' => $mgrA->id, 'position_title' => 'A-role']);
        $this->manpowerRequest(['hiring_manager_id' => $mgrB->id, 'position_title' => 'B-role']);

        $rows = $this->service()->list($this->admin, ['hiring_manager_id' => $mgrA->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('A-role', $rows->first()->position_title);
    }

    public function test_an_absent_hiring_manager_filter_returns_everything(): void
    {
        $mgr = $this->user('staff', 'department_head');
        $this->manpowerRequest(['hiring_manager_id' => $mgr->id]);
        $this->manpowerRequest();

        // Existing callers pass no such key, and must keep seeing the full list.
        $this->assertCount(2, $this->service()->list($this->admin, []));
        $this->assertCount(2, $this->service()->list($this->admin, ['hiring_manager_id' => 'All']));
    }

    public function test_job_postings_filter_by_the_hiring_manager_of_their_requisition(): void
    {
        $mgr = $this->user('staff', 'department_head');
        $mine = $this->manpowerRequest(['hiring_manager_id' => $mgr->id]);
        $other = $this->manpowerRequest();

        $this->jobPosting($mine, 'Mine');
        $this->jobPosting($other, 'Theirs');

        $rows = app(JobPostingService::class)->list($this->admin, ['hiring_manager_id' => $mgr->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows->first()->title);
    }

    public function test_a_job_posting_with_no_requisition_is_excluded_not_crashed(): void
    {
        $mgr = $this->user('staff', 'department_head');
        $this->jobPosting(null, 'Standalone');

        // whereHas on a null relation must simply not match.
        $this->assertCount(0, app(JobPostingService::class)->list($this->admin, ['hiring_manager_id' => $mgr->id]));
        $this->assertCount(1, app(JobPostingService::class)->list($this->admin, []));
    }

    private function jobPosting(?HrManpowerRequest $mr, string $title): HrJobPosting
    {
        return HrJobPosting::create([
            'tenant_id' => self::TENANT, 'manpower_request_id' => $mr?->id,
            'title' => $title, 'department' => 'Engineering', 'location' => 'Pune', 'status' => 'Draft',
            'number_of_positions' => 1, 'created_by' => $this->admin->id,
        ]);
    }
}
