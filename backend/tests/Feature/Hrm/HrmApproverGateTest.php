<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\AdvanceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may work the app's approval queue.
 *
 * The gate on HrmAdminController admitted admins, HR, accounts and directors —
 * but not a line manager, who is the FIRST rung of the advance ladder. From a
 * phone a manager could therefore approve nothing, and every advance stopped
 * dead at tier one. That is the state these tests exist to stop coming back.
 *
 * Widening a gate is only half a change. The endpoints behind it check the
 * tenant and the current status, never WHOSE request it is, so every test that
 * proves a manager got in is paired with one proving they cannot reach past
 * their own reports — and one proving the gate stayed shut on payroll.
 */
class HrmApproverGateTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrm-gate-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    private function employee(string $code, ?User $u = null, ?int $mgr = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => 'Operations', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $u?->id, 'reporting_manager_id' => $mgr,
        ]);
    }

    private function advance(HrEmployee $e, float $amount = 9000): HrAdvance
    {
        return HrAdvance::create([
            'tenant_id'        => $this->tenant()->id,
            'employee_id'      => $e->id,
            'purpose'          => 'Site visit',
            'amount_requested' => $amount,
            'status'           => AdvanceStage::PENDING,
        ]);
    }

    /**
     * A manager, the one person reporting to them, and an unrelated employee.
     *
     * @return array{0: User, 1: HrEmployee, 2: HrEmployee}
     */
    private function team(): array
    {
        $mgrUser = $this->user('manager@example.test');
        $mgrEmp  = $this->employee('MGR-1', $mgrUser);

        return [
            $mgrUser,
            $this->employee('RPT-1', $this->user('report@example.test'), $mgrEmp->id),
            $this->employee('OTH-1', $this->user('other@example.test')),
        ];
    }

    private function queueAdvanceIds(): array
    {
        return collect(
            $this->postJson('/api/Hrm/admin/pending-approvals', [])->assertOk()->json('data.advances')
        )->pluck('id')->all();
    }

    /* ── the manager is let in ───────────────────────────────────────── */

    public function test_a_line_manager_can_approve_their_own_report_at_tier_one(): void
    {
        [$mgr, $report] = $this->team();
        $advance = $this->advance($report);

        Sanctum::actingAs($mgr);

        $r = $this->postJson('/api/Hrm/admin/approve-reject-advance', [
            'advance_id' => $advance->id, 'status' => 'approved',
        ]);

        $r->assertOk();
        $this->assertSame(
            1, $r->json('status'),
            'A line manager IS tier one and must be able to approve. Refused with: '.$r->json('message')
        );
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->fresh()->status);
    }

    /** Being linked to an employee record is not the same as managing anyone. */
    public function test_somebody_who_manages_nobody_is_still_refused(): void
    {
        $lonely = $this->user('lonely@example.test');
        $this->employee('LON-1', $lonely);

        $advance = $this->advance($this->employee('OTH-2', $this->user('other2@example.test')));

        Sanctum::actingAs($lonely);

        $r = $this->postJson('/api/Hrm/admin/approve-reject-advance', [
            'advance_id' => $advance->id, 'status' => 'approved',
        ]);

        $r->assertOk();
        $this->assertSame(0, $r->json('status'));
        $this->assertSame(AdvanceStage::PENDING, $advance->fresh()->status);
    }

    /* ── and cannot reach past their own reports ─────────────────────── */

    public function test_a_manager_cannot_decide_for_somebody_who_is_not_theirs(): void
    {
        [$mgr, , $stranger] = $this->team();
        $advance = $this->advance($stranger);

        Sanctum::actingAs($mgr);

        $r = $this->postJson('/api/Hrm/admin/approve-reject-advance', [
            'advance_id' => $advance->id, 'status' => 'approved',
        ]);

        $r->assertOk();
        $this->assertSame(0, $r->json('status'));
        $this->assertSame(
            AdvanceStage::PENDING, $advance->fresh()->status,
            'A foreign advance must not move an inch.'
        );
    }

    public function test_a_managers_queue_holds_only_their_own_reports(): void
    {
        [$mgr, $report, $stranger] = $this->team();
        $mine   = $this->advance($report, 1000);
        $theirs = $this->advance($stranger, 2000);

        Sanctum::actingAs($mgr);
        $ids = $this->queueAdvanceIds();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains(
            $theirs->id, $ids,
            "An advance queue says who needed money and what for — not another team's reading."
        );
    }

    public function test_an_admin_still_sees_the_whole_workspace(): void
    {
        [, $report, $stranger] = $this->team();
        $mine   = $this->advance($report, 1000);
        $theirs = $this->advance($stranger, 2000);

        Sanctum::actingAs($this->user('admin@example.test', 'admin'));
        $ids = $this->queueAdvanceIds();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    /* ── the gate stayed narrow everywhere else ──────────────────────── */

    public function test_a_line_manager_still_cannot_reach_payroll_people_or_salaries(): void
    {
        [$mgr, $report] = $this->team();

        Sanctum::actingAs($mgr);

        foreach ([
            ['get',  '/api/Hrm/admin/payroll-overview', []],
            ['post', '/api/Hrm/admin/set-employee-salary', ['employee_id' => $report->id, 'ctc' => 100000]],
            ['post', '/api/Hrm/admin/create-employee', []],
            ['post', '/api/Hrm/admin/reset-employee-password', []],
            ['get',  '/api/Hrm/admin/employees-list', []],
            ['post', '/api/Hrm/admin/disburse-advance', []],
        ] as [$verb, $url, $body]) {
            $r = $verb === 'get' ? $this->getJson($url) : $this->postJson($url, $body);

            // 200 with status 0, never 401 — the app wipes local storage on a 401.
            $r->assertOk();
            $this->assertSame(0, $r->json('status'), "{$url} is not a line manager's business.");
        }
    }
}
