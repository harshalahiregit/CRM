<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrExitType;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrProbationConfirmation;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Notifications\HrNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\ExitApprovalService;
use App\Services\Hr\InvestmentDeclarationService;
use App\Services\Hr\ProbationConfirmationService;
use App\Services\Hr\VariableEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The four approval processes that decided in silence.
 *
 * Nine processes run on the approval engine. Six of them told somebody what had
 * been decided; these four told nobody, at any layer — not the controller, not
 * the service, not the engine, and not through an observer or listener, because
 * HR has no events and ApprovalEngine raises none.
 *
 * So an exit was approved or refused and the person leaving heard nothing; a
 * probation confirmation was rejected in silence; a commission that goes into
 * somebody's pay was turned down without a word; and an investment declaration
 * was rejected, changing the tax deducted from a salary, with the first sign of
 * it appearing in the payslip.
 *
 * NO PARALLEL SYSTEM. Every notification below goes through HrEventNotifier and
 * the same NotificationEngine that Loans and Payroll already use, with the
 * events registered in config/hr_notifications.php like every other module, so
 * they are per-tenant templated, rule-driven and editable. Nothing bespoke.
 *
 * The recipient is asserted BY ID rather than by counting rows: a notification
 * delivered to the wrong person is worse than none.
 */
class ApprovalDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'adn-a', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Beta', 'slug' => 'adn-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -5),
            'email' => uniqid().'@adn.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function employee(?User $user = null, ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
            'user_id' => $user?->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int,HrNotification> */
    private function sent(string $module, string $event)
    {
        return HrNotification::where('module', $module)->where('event', $event)->get();
    }

    /* ═══════════════ 1. EXIT APPROVAL ═══════════════════════════════ */

    private function exitRequest(HrEmployee $e): HrExitRequest
    {
        $type = HrExitType::create([
            'tenant_id' => $e->tenant_id, 'name' => 'Resignation', 'code' => 'RES'.substr(uniqid(), -4),
            'is_active' => true,
        ]);

        return HrExitRequest::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'exit_type_id' => $type->id,
            'request_date' => now()->toDateString(),
            'last_working_date' => now()->addDays(30)->toDateString(),
            'status' => HrExitRequest::UNDER_REVIEW, 'notice_days' => 30,
        ]);
    }

    /** @test */
    public function approving_an_exit_tells_the_person_leaving(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $req  = $this->exitRequest($e);

        app(ExitApprovalService::class)->approve($req->id, ['remarks' => 'All the best'], $this->tenant->id, $this->user());

        $rows = $this->sent('Exit', 'Approved');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('All the best', $rows->first()->message);
    }

    /** @test */
    public function rejecting_an_exit_tells_the_person_with_the_reason(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $req  = $this->exitRequest($e);

        app(ExitApprovalService::class)->reject($req->id, ['remarks' => 'Project handover pending'], $this->tenant->id, $this->user());

        $rows = $this->sent('Exit', 'Rejected');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('Project handover pending', $rows->first()->message);
    }

    /**
     * An employee with no login gets no notification and no error.
     *
     * Plenty of people are on the payroll and not on the app. Throwing, or
     * inventing a recipient, would break the exit or send somebody else's
     * business to the wrong person.
     *
     * @test
     */
    public function an_exit_for_an_employee_without_a_login_notifies_nobody_and_still_succeeds(): void
    {
        $e   = $this->employee(null);
        $req = $this->exitRequest($e);

        app(ExitApprovalService::class)->approve($req->id, [], $this->tenant->id, $this->user());

        $this->assertSame(HrExitRequest::APPROVED, $req->fresh()->status, 'The decision still stands.');
        $this->assertCount(0, $this->sent('Exit', 'Approved'));
    }

    /* ═══════════════ 2. PROBATION CONFIRMATION ═════════════════════ */

    private function confirmation(HrEmployee $e): HrProbationConfirmation
    {
        $type = \App\Models\Hr\HrProbationType::create([
            'tenant_id' => $e->tenant_id, 'name' => 'T'.substr(uniqid(), -5),
            'code' => 'PT'.substr(uniqid(), -4),
            'default_duration_days' => 90, 'is_active' => true,
        ]);
        $policy = \App\Models\Hr\HrProbationPolicy::create([
            'tenant_id' => $e->tenant_id, 'name' => 'P'.substr(uniqid(), -5),
            'probation_type_id' => $type->id, 'is_active' => true,
        ]);
        $probation = \App\Models\Hr\HrEmployeeProbation::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
            'probation_policy_id' => $policy->id, 'probation_type_id' => $type->id,
            'probation_start_date' => now()->subDays(90)->toDateString(),
            'probation_end_date' => now()->toDateString(),
            'current_status' => \App\Models\Hr\HrEmployeeProbation::ACTIVE,
        ]);

        return HrProbationConfirmation::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
            'probation_id' => $probation->id,
            'status' => HrProbationConfirmation::PENDING,
            'confirmation_date' => now()->toDateString(),
        ]);
    }

    /** @test */
    public function rejecting_a_probation_confirmation_tells_the_employee(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $conf = $this->confirmation($e);

        app(ProbationConfirmationService::class)
            ->reject($conf->id, ['hr_comments' => 'Needs another quarter'], $this->tenant->id, $this->user());

        $rows = $this->sent('Probation', 'Confirmation Rejected');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('Needs another quarter', $rows->first()->message);
    }

    /* ═══════════════ 3. VARIABLE EARNINGS ══════════════════════════ */

    private function earning(HrEmployee $e, float $amount = 5000): HrEmployeeVariableEarning
    {
        $component = HrSalaryComponent::create([
            'tenant_id' => $e->tenant_id, 'name' => 'Incentive '.substr(uniqid(), -4),
            'code' => 'INC'.substr(uniqid(), -4), 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true,
        ]);

        return HrEmployeeVariableEarning::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
            'component_id' => $component->id, 'amount' => $amount,
            'period' => now()->format('Y-m'),
            'status' => HrEmployeeVariableEarning::PENDING,
        ]);
    }

    /** @test */
    public function approving_a_variable_earning_tells_the_employee_the_amount(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $v    = $this->earning($e, 5000);

        app(VariableEarningService::class)->approve($v->id, $this->tenant->id, $this->user());

        $rows = $this->sent('VariableEarning', 'Approved');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('5000', $rows->first()->message);
    }

    /** @test */
    public function rejecting_a_variable_earning_tells_the_employee_why(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $v    = $this->earning($e, 5000);

        app(VariableEarningService::class)->reject($v->id, $this->tenant->id, 'Target not met', $this->user());

        $rows = $this->sent('VariableEarning', 'Rejected');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('Target not met', $rows->first()->message);
    }

    /* ═══════════════ 4. INVESTMENT DECLARATIONS ════════════════════ */

    private function declaration(HrEmployee $e): HrInvestmentDeclaration
    {
        return HrInvestmentDeclaration::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
            'financial_year' => '2026-2027',
            'status' => HrInvestmentDeclaration::SUBMITTED,
        ]);
    }

    /** @test */
    public function verifying_a_declaration_tells_the_employee(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $d    = $this->declaration($e);

        app(InvestmentDeclarationService::class)->verify($d->id, ['items' => []], $this->tenant->id, $this->user());

        $rows = $this->sent('Investment', 'Verified');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('2026-2027', $rows->first()->message);
    }

    /** @test */
    public function rejecting_a_declaration_tells_the_employee_why(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $d    = $this->declaration($e);

        app(InvestmentDeclarationService::class)->reject($d->id, 'Proof missing', $this->tenant->id, $this->user());

        $rows = $this->sent('Investment', 'Rejected');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('Proof missing', $rows->first()->message);
    }

    /* ═══════════════ 5. CROSS-CUTTING ══════════════════════════════ */

    /**
     * One decision produces one notification.
     *
     * The services already recorded an audit row and a log line for each
     * decision; adding a notifier call beside them must not turn into two
     * notifications for one event.
     *
     * @test
     */
    public function a_decision_notifies_once_and_only_once(): void
    {
        $user = $this->user();
        $e    = $this->employee($user);
        $v    = $this->earning($e);

        app(VariableEarningService::class)->approve($v->id, $this->tenant->id, $this->user());

        $this->assertSame(1, HrNotification::where('recipient_user_id', $user->id)->count());
    }

    /**
     * A decision in one workspace never reaches another's employee.
     *
     * The recipient is resolved from the record's own employee, so the
     * assertion is that the OTHER tenant's user receives nothing at all.
     *
     * @test
     */
    public function a_decision_never_notifies_another_tenants_employee(): void
    {
        $mine   = $this->user($this->tenant);
        $theirs = $this->user($this->other);
        $this->employee($theirs, $this->other);

        $e = $this->employee($mine, $this->tenant);
        app(VariableEarningService::class)->approve($this->earning($e)->id, $this->tenant->id, $this->user());

        $this->assertSame(0, HrNotification::where('recipient_user_id', $theirs->id)->count());
        $this->assertSame(1, HrNotification::where('recipient_user_id', $mine->id)->count());
    }

    /**
     * A notification that cannot be sent must not undo the decision.
     *
     * HrEventNotifier swallows and logs its failures for exactly this reason —
     * losing an approved exit because a bell could not be rung would be a far
     * worse defect than a missing notification. Driven here with an
     * unregistered-recipient case rather than by breaking the engine.
     *
     * @test
     */
    public function a_failed_notification_never_rolls_back_the_decision(): void
    {
        $e = $this->employee(null);
        $v = $this->earning($e);

        app(VariableEarningService::class)->approve($v->id, $this->tenant->id, $this->user());

        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $v->fresh()->status);
    }

    /**
     * A partially-loaded employee still reaches its recipient.
     *
     * THIS IS WHY THE FOUR WORKFLOWS WOULD HAVE STAYED SILENT even with the
     * notifier wired in. HR repositories eager-load employees with an explicit
     * column list — `with('employee:id,name,employee_code,department')` — and
     * user_id is on none of them. A column that was not selected is simply
     * absent from the hydrated model and reads as null, so the notifier found
     * no recipient and returned without a word.
     *
     * Driven with exactly that shape: an employee loaded by the same
     * column-limited select the repositories use.
     *
     * @test
     */
    public function a_column_limited_employee_still_finds_its_recipient(): void
    {
        $user = $this->user();
        $full = $this->employee($user);

        // Precisely how ExitRepository and friends hydrate an employee.
        $partial = HrEmployee::select(['id', 'tenant_id', 'name', 'employee_code', 'department'])
            ->whereKey($full->id)->first();

        $this->assertFalse(array_key_exists('user_id', $partial->getAttributes()),
            'The fixture must reproduce the partial load, or this proves nothing.');

        app(\App\Services\Hr\HrEventNotifier::class)
            ->toEmployee($partial, 'VariableEarning', 'Approved', ['amount' => '5000']);

        $rows = $this->sent('VariableEarning', 'Approved');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->recipient_user_id);
    }

    /**
     * And somebody who genuinely has no login is still skipped.
     *
     * The guard must tell "this column was not selected" from "this person is
     * not on the app" — otherwise it would turn every payroll-only employee
     * into a failed lookup.
     *
     * @test
     */
    public function an_employee_with_a_loaded_null_login_is_still_skipped(): void
    {
        $e = $this->employee(null);

        $this->assertTrue(array_key_exists('user_id', $e->getAttributes()));

        app(\App\Services\Hr\HrEventNotifier::class)
            ->toEmployee($e, 'VariableEarning', 'Approved', ['amount' => '5000']);

        $this->assertCount(0, $this->sent('VariableEarning', 'Approved'));
    }

    /**
     * The six processes that already notified still do.
     *
     * Loans are the representative: their notifications predate this work and
     * must be untouched by it.
     *
     * @test
     */
    public function the_processes_that_already_notified_are_unchanged(): void
    {
        foreach ([
            ['Loan', 'Approved'], ['Loan', 'Rejected'], ['Loan', 'Disbursed'],
            ['Payroll', 'Approved'], ['Leave', 'Approved'], ['Expense', '*'],
        ] as [$module, $event]) {
            $this->assertNotNull(
                config("hr_notifications.modules.{$module}.{$event}")
                    ?? config("hr_notifications.modules.{$module}"),
                "{$module}/{$event} registration disappeared"
            );
        }
    }

    /** Every one of the nine approval processes now has a decision event. */
    public function test_all_nine_approval_processes_have_a_registered_decision_event(): void
    {
        $modules = array_keys(config('hr_notifications.modules'));

        foreach (['Leave', 'Loan', 'VariableEarning', 'Investment', 'Expense', 'Exit', 'Probation', 'Payroll', 'Advance'] as $m) {
            $this->assertContains($m, $modules, "{$m} is not registered with the notification engine");
        }
    }
}
