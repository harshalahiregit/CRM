<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AdvanceTierService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\Approval\ApprovalState;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The advance ladder, frozen at submission — Phase 9.
 *
 * Advances are the one process whose ladder is NOT configured in Settings. Its
 * three rungs are fixed by AdvanceStage, and their membership is resolved by
 * AdvanceTierService: the manager rung is the employee's OWN reporting manager,
 * and accounts and director go by internal_role — which none of the engine's
 * approver types can express. So advances are registered for their SNAPSHOT and
 * their history, not for configuration, and AdvanceTierService remains the
 * authority on who may act.
 *
 * What the engine contributes is the thing that was broken. ladderFor() read
 * live settings on every call, so raising advance_manager_limit while a request
 * sat at the manager rung silently shortened its ladder — and that manager's
 * approval became final on an amount two more people were supposed to see.
 *
 * The line this draws: the THRESHOLDS are frozen, the AMOUNT is not. An
 * approver may still reduce the figure as they sign, and a smaller advance
 * genuinely needs fewer rungs. That is existing behaviour and it is asserted
 * here too, so the freeze cannot quietly swallow it.
 */
class AdvanceLadderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'advsnap', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U'.substr($email, 0, 4),
            'email' => $email, 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs));
    }

    private function limits(?float $manager, ?float $accounts): void
    {
        $s = app(SettingsService::class);
        $s->set($this->tenant->id, HrSetting::GROUP, 'advance_manager_limit', $manager);
        $s->set($this->tenant->id, HrSetting::GROUP, 'advance_accounts_limit', $accounts);
    }

    /** Raise an advance through the real submission path, so it gets snapped. */
    private function raise(HrEmployee $employee, float $amount, User $actor): HrAdvance
    {
        return app(AdvanceService::class)->request($employee, [
            'advance_type' => 'Salary', 'category' => 'General',
            'amount_requested' => $amount, 'purpose' => 'Travel',
        ], $actor);
    }

    private function tiers(): AdvanceTierService
    {
        return app(AdvanceTierService::class);
    }

    private function snapshotFor(HrAdvance $a): ?HrApprovalRequest
    {
        return HrApprovalRequest::where('subject_type', HrAdvance::class)
            ->where('subject_id', $a->id)->latest('id')->first();
    }

    /* ── 1. a submitted advance is snapshotted ───────────────────────── */

    public function test_submitting_an_advance_freezes_the_thresholds(): void
    {
        $this->limits(10000, 50000);

        $employee = $this->employee();
        $advance = $this->raise($employee, 30000, $this->user('hr@adv.test', 'admin'));

        $snapshot = $this->snapshotFor($advance);

        $this->assertNotNull($snapshot, 'A submitted advance must carry a snapshot.');
        $this->assertSame(ApprovalProcess::ADVANCE, $snapshot->process);
        $this->assertSame(ApprovalState::PENDING, $snapshot->state);
        $this->assertSame($employee->id, $snapshot->employee_id);

        // The rungs carry the thresholds that shaped them.
        $steps = collect($snapshot->steps_snapshot)->keyBy('name');
        $this->assertSame(10000.0, (float) $steps[AdvanceStage::ACCOUNTS]['conditions']['min_amount']);
        $this->assertSame(50000.0, (float) $steps[AdvanceStage::DIRECTOR]['conditions']['min_amount']);
    }

    /* ── 2. THE FIX: settings changes cannot reach an in-flight advance ── */

    public function test_raising_a_limit_does_not_shorten_an_in_flight_ladder(): void
    {
        $this->limits(10000, 50000);

        $employee = $this->employee();
        $advance = $this->raise($employee, 30000, $this->user('hr2@adv.test', 'admin'));

        // 30,000 is above the manager limit and below the accounts limit.
        $this->assertSame(
            [AdvanceStage::MANAGER, AdvanceStage::ACCOUNTS],
            $this->tiers()->ladderFor($advance->fresh())
        );

        // Somebody raises the manager limit to 100,000 mid-flight. Before the
        // snapshot this shortened the ladder to [manager] and the manager's
        // signature became final on its own.
        $this->limits(100000, 50000);

        $this->assertSame(
            [AdvanceStage::MANAGER, AdvanceStage::ACCOUNTS],
            $this->tiers()->ladderFor($advance->fresh()),
            'A settings change must not rewrite a ladder somebody is halfway through.'
        );
    }

    public function test_lowering_a_limit_does_not_lengthen_an_in_flight_ladder(): void
    {
        $this->limits(50000, 100000);

        $employee = $this->employee();
        $advance = $this->raise($employee, 30000, $this->user('hr3@adv.test', 'admin'));

        // Within the manager limit: one rung.
        $this->assertSame([AdvanceStage::MANAGER], $this->tiers()->ladderFor($advance->fresh()));

        // The limit is cut afterwards. The request keeps the policy it was
        // raised under — the drift has to be refused in both directions, or it
        // is not a freeze.
        $this->limits(1000, 5000);

        $this->assertSame(
            [AdvanceStage::MANAGER],
            $this->tiers()->ladderFor($advance->fresh())
        );
    }

    /* ── 3. new advances DO pick up the new settings ──────────────────── */

    public function test_a_new_advance_uses_the_current_settings(): void
    {
        $this->limits(10000, 50000);
        $actor = $this->user('hr4@adv.test', 'admin');

        $old = $this->raise($this->employee(), 30000, $actor);
        $this->assertSame([AdvanceStage::MANAGER, AdvanceStage::ACCOUNTS], $this->tiers()->ladderFor($old->fresh()));

        // Policy changes. The OLD request keeps its ladder; a NEW one gets the
        // new policy — which is the whole point of freezing per request rather
        // than globally.
        $this->limits(100000, 200000);

        $new = $this->raise($this->employee(), 30000, $actor);

        $this->assertSame([AdvanceStage::MANAGER], $this->tiers()->ladderFor($new->fresh()));
        $this->assertSame([AdvanceStage::MANAGER, AdvanceStage::ACCOUNTS], $this->tiers()->ladderFor($old->fresh()));
    }

    /* ── 4. the amount still re-shapes the ladder ─────────────────────── */

    public function test_the_amount_still_re_shapes_the_ladder_within_frozen_limits(): void
    {
        $this->limits(10000, 50000);

        $employee = $this->employee();
        $advance = $this->raise($employee, 80000, $this->user('hr5@adv.test', 'admin'));

        // 80,000: all three rungs.
        $this->assertSame(AdvanceStage::LADDER, $this->tiers()->ladderFor($advance->fresh()));

        // An approver reduces it to 9,000 — within the frozen manager limit.
        // Existing behaviour truncates the ladder, and freezing the THRESHOLDS
        // must not freeze the amount as well.
        $advance->update(['amount_approved' => 9000]);

        $this->assertSame(
            [AdvanceStage::MANAGER],
            $this->tiers()->ladderFor($advance->fresh()),
            'A reduced amount still shortens the ladder — only the thresholds are frozen.'
        );
    }

    /* ── 5. the ladder still governs who acts, and in what order ──────── */

    public function test_a_tier_cannot_be_skipped(): void
    {
        $this->limits(0, 0);            // no shortcuts: all three rungs

        $managerUser = $this->user('mgr@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 80000, $this->user('hr6@adv.test', 'admin'));

        // Accounts cannot act while the manager rung is outstanding — the
        // existing AdvanceTierService rule, unchanged.
        $accounts = $this->user('acc@adv.test', 'staff', 'accounts');

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage("waiting on the employee's own reporting manager");
        app(AdvanceService::class)->approve($advance->fresh(), $accounts, null, null);
    }

    public function test_the_manager_rung_is_the_employees_own_manager(): void
    {
        $this->limits(0, 0);

        $managerUser = $this->user('mgr2@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 80000, $this->user('hr7@adv.test', 'admin'));

        app(AdvanceService::class)->approve($advance->fresh(), $managerUser, null, null);

        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->fresh()->status);
    }

    /* ── 6. intermediate approval does not disburse ───────────────────── */

    public function test_an_intermediate_approval_does_not_make_the_advance_payable(): void
    {
        $this->limits(0, 0);            // all three rungs

        $managerUser = $this->user('mgr3@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 80000, $this->user('hr8@adv.test', 'admin'));

        app(AdvanceService::class)->approve($advance->fresh(), $managerUser, null, null);

        $fresh = $advance->fresh();
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $fresh->status);
        $this->assertNull($fresh->decided_at, 'Only the final rung stamps the decision.');

        // Disbursing needs the ladder finished — the existing guard, untouched.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('only be disbursed once every tier has approved it');
        app(AdvanceService::class)->disburse($fresh, $this->user('acc2@adv.test', 'admin'), 'bank_transfer', 'REF-1', null);
    }

    public function test_the_terminal_rung_reaches_approved_and_closes_the_round(): void
    {
        $this->limits(100000, 200000);   // one rung for this amount

        $managerUser = $this->user('mgr4@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 30000, $this->user('hr9@adv.test', 'admin'));

        app(AdvanceService::class)->approve($advance->fresh(), $managerUser, null, null);

        $fresh = $advance->fresh();
        $this->assertSame(AdvanceStage::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->decided_at);

        // The round is closed, so the frozen thresholds stop applying to a
        // request nobody is deciding any more.
        $this->assertSame(ApprovalState::APPROVED, $this->snapshotFor($advance)->state);
    }

    /* ── 7. rejection and cancellation close the round ────────────────── */

    public function test_declining_closes_the_round_and_keeps_the_domain_state(): void
    {
        $this->limits(0, 0);

        $managerUser = $this->user('mgr5@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 80000, $this->user('hr10@adv.test', 'admin'));

        app(AdvanceService::class)->decline($advance->fresh(), $managerUser, 'not this month');

        $this->assertSame(AdvanceStage::DECLINED, $advance->fresh()->status);
        $this->assertSame(ApprovalState::REJECTED, $this->snapshotFor($advance)->state);
    }

    public function test_cancelling_closes_the_round(): void
    {
        $this->limits(0, 0);

        $employeeUser = $this->user('emp@adv.test', 'staff');
        $employee = $this->employee(['user_id' => $employeeUser->id]);

        $advance = $this->raise($employee, 80000, $this->user('hr11@adv.test', 'admin'));

        app(AdvanceService::class)->cancel($advance->fresh(), $employeeUser);

        $this->assertSame(AdvanceStage::CANCELLED, $advance->fresh()->status);
        $this->assertSame(ApprovalState::CANCELLED, $this->snapshotFor($advance)->state);
    }

    /* ── 8. duplicate decisions remain protected ──────────────────────── */

    public function test_one_person_cannot_approve_two_rungs(): void
    {
        $this->limits(0, 0);

        // An admin can stand in on any rung, so without the not-twice rule a
        // single admin could climb the whole ladder alone.
        $admin = $this->user('admin@adv.test', 'admin');
        $employee = $this->employee();

        $advance = $this->raise($employee, 80000, $this->user('hr12@adv.test', 'admin'));

        app(AdvanceService::class)->approve($advance->fresh(), $admin, null, null);
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->fresh()->status);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('already approved this advance at an earlier stage');
        app(AdvanceService::class)->approve($advance->fresh(), $admin, null, null);
    }

    public function test_nobody_approves_their_own_advance(): void
    {
        $this->limits(0, 0);

        $selfUser = $this->user('self@adv.test', 'admin');
        $self = $this->employee(['user_id' => $selfUser->id]);

        $advance = $this->raise($self, 80000, $selfUser);

        // No exception to this one, however senior.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('cannot approve your own advance');
        app(AdvanceService::class)->approve($advance->fresh(), $selfUser, null, null);
    }

    /* ── 9. employee scope, through the existing resolver ─────────────── */

    public function test_the_snapshot_records_the_employee_for_scope(): void
    {
        $this->limits(0, 0);

        $employee = $this->employee();
        $advance = $this->raise($employee, 80000, $this->user('hr13@adv.test', 'admin'));

        // An advance IS employee-specific, unlike a payroll run, so the round
        // carries the employee and ScopeResolver has something to check.
        $this->assertSame($employee->id, $this->snapshotFor($advance)->employee_id);
    }

    /* ── 10. advances are not offered as a configurable ladder ────────── */

    public function test_advances_are_registered_but_not_configurable(): void
    {
        $admin = $this->user('admin2@adv.test', 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        // Registered for the snapshot, absent from Settings: offering a ladder
        // that did not actually govern would be worse than offering none.
        $this->assertNotContains(ApprovalProcess::ADVANCE, $keys);
        $this->assertTrue(ApprovalProcess::exists(ApprovalProcess::ADVANCE));
        $this->assertFalse(ApprovalProcess::isConfigurable(ApprovalProcess::ADVANCE));

        // And the configuration endpoints refuse it rather than pretending.
        $this->getJson('/api/hr/approval-workflows/advance')->assertStatus(422);
    }

    public function test_the_other_processes_are_still_configurable(): void
    {
        $admin = $this->user('admin3@adv.test', 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        foreach ([
            ApprovalProcess::LEAVE, ApprovalProcess::LOAN, ApprovalProcess::VARIABLE_EARNING,
            ApprovalProcess::INVESTMENT_DECLARATION, ApprovalProcess::REIMBURSEMENT,
            ApprovalProcess::EXIT_REQUEST, ApprovalProcess::PROBATION_CONFIRMATION,
            ApprovalProcess::PAYROLL_RUN,
        ] as $p) {
            $this->assertContains($p, $keys);
        }
    }

    /* ── 11. the attendance app path gets the same frozen ladder ──────── */

    public function test_the_app_service_path_reads_the_same_frozen_thresholds(): void
    {
        $this->limits(10000, 50000);

        $managerUser = $this->user('mgrapp@adv.test', 'staff');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $advance = $this->raise($employee, 30000, $this->user('hr14@adv.test', 'admin'));

        // The limit is raised mid-flight.
        $this->limits(100000, 50000);

        /*
         | HrmAdminController decides advances by calling
         | AdvanceService::approve() directly, and that reaches
         | AdvanceTierService underneath. Because the snapshot is read THERE
         | rather than in a CRM controller, the phone gets the frozen ladder
         | too — without one line of the attendance app changing.
         */
        app(AdvanceService::class)->approve($advance->fresh(), $managerUser, null, null);

        $this->assertSame(
            AdvanceStage::MANAGER_APPROVED,
            $advance->fresh()->status,
            'The manager rung must not have become final under the raised limit.'
        );
    }
}
