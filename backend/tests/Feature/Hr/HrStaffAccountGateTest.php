<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrPayrollRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two doors into HR that were standing open, and the rule that shuts both.
 *
 * A portal login is a row in `users` exactly like a staff login — same model,
 * different `role` — and it carries `internal_role` too. So every check written
 * as `in_array($user->internal_role, [...])` answered yes for a client or a
 * vendor whose internal_role happened to hold a matching string. That is not
 * hypothetical here: the seeded client Vikram Rao carries internal_role
 * 'director', which is one of the advance ladder's approval roles, and he could
 * reach the advance queue with it.
 *
 * The statutory registers were a different shape of the same problem — no role
 * check at all, only a tenant scope. Being signed in was the whole requirement
 * for reading every employee's bank account number and net pay.
 *
 * Both are pinned here rather than in HrRouteExposureTest, which asks what a
 * plain EMPLOYEE can reach. The question these ask is narrower and easier to
 * regress: does the account type get checked before the role string is trusted.
 */
class HrStaffAccountGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Gates', 'slug' => 'staff-account-gates', 'status' => 'active',
        ]);
    }

    private function user(string $role, string $email, ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role,
            'internal_role' => $internal, 'status' => 'active',
        ]);
    }

    /** Not named run(): PHPUnit\Framework\TestCase::run() is final. */
    private function payrollRun(): HrPayrollRun
    {
        return HrPayrollRun::create([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 6,
            'payroll_year' => 2026, 'status' => HrPayrollRun::DRAFT,
        ]);
    }

    /* ── Fix 2: the account type is checked before the role string ────────── */

    /**
     * The exact shape of the live account that prompted this.
     *
     * A portal identity carrying an HR role string is refused, whatever the
     * string says. Parameterised over every non-staff account type so adding a
     * portal later does not quietly reopen the door.
     *
     * @dataProvider portalRolesCarryingHrStrings
     */
    public function test_a_portal_identity_cannot_manage_the_hr_queue(string $role, string $internal): void
    {
        $user = $this->user($role, "portal-{$role}@gates.test", $internal);

        $this->assertFalse(
            $user->canManageHrQueue(),
            "A {$role} carrying internal_role '{$internal}' must not hold HR authority — "
            .'internal_role is a free string and no portal account earns the HR queue with it.'
        );
    }

    public static function portalRolesCarryingHrStrings(): array
    {
        return [
            'client claiming HR'            => ['client', 'hr_executive'],
            'client claiming director'      => ['client', 'director'],
            'vendor claiming HR'            => ['vendor', 'hr_recruiter'],
            'third party vendor claiming HR' => ['third_party_vendor', 'hr_executive'],
            'external company claiming HR'  => ['company', 'hr_executive'],
            'doctor claiming HR'            => ['doctor', 'hr_recruiter'],
        ];
    }

    /** The half that must NOT change: real staff keep exactly the authority they had. */
    public function test_staff_and_admin_authority_is_unchanged(): void
    {
        $this->assertTrue($this->user('admin', 'admin@gates.test')->canManageHrQueue(),
            'An admin manages HR.');
        $this->assertTrue($this->user('staff', 'hre@gates.test', 'hr_executive')->canManageHrQueue(),
            'An HR Executive manages HR.');
        $this->assertTrue($this->user('staff', 'rec@gates.test', 'hr_recruiter')->canManageHrQueue(),
            'An HR Recruiter manages HR.');
        $this->assertFalse($this->user('staff', 'emp@gates.test')->canManageHrQueue(),
            'A staff member with no HR role still does not manage HR.');
        $this->assertFalse($this->user('staff', 'acct@gates.test', 'accounts')->canManageHrQueue(),
            'Accounts is not HR — this was false before the change and stays false.');
    }

    /* ── Fix 2, over HTTP: the advances queue ─────────────────────────────── */

    /**
     * The advance gate reads TIER_ROLES, which is why 'director' was enough.
     *
     * An advance says what somebody is doing and how much they needed. A customer
     * contact has no business reading that, let alone standing on the rung that
     * approves it.
     */
    public function test_a_client_carrying_director_cannot_enter_the_advance_queue(): void
    {
        Sanctum::actingAs($this->user('client', 'vikram@gates.test', 'director'));

        $this->getJson('/api/hr/advances')->assertForbidden();
    }

    /** Every rung that legitimately holds the queue still holds it. */
    public function test_the_real_approval_rungs_still_reach_the_advance_queue(): void
    {
        foreach ([
            ['admin', 'a@gates.test', null],
            ['staff', 'b@gates.test', 'hr_executive'],
            ['staff', 'c@gates.test', 'accounts'],
            ['staff', 'd@gates.test', 'director'],
        ] as [$role, $email, $internal]) {
            Sanctum::actingAs($this->user($role, $email, $internal));

            $this->getJson('/api/hr/advances')->assertOk();
        }
    }

    /**
     * A line manager reaches the queue through the hierarchy, not a role string.
     *
     * Worth pinning separately: the account-type guard runs before
     * managesAnyone(), so a mistake there would look like "managers lost access"
     * rather than like a security change.
     */
    public function test_a_line_manager_still_reaches_the_advance_queue(): void
    {
        $managerUser = $this->user('staff', 'mgr@gates.test');

        $manager = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'GM-1', 'name' => 'Manager',
            'department' => 'Ops', 'designation' => 'Lead', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $managerUser->id,
        ]);

        HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'GM-2', 'name' => 'Report',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'reporting_manager_id' => $manager->id,
        ]);

        Sanctum::actingAs($managerUser);

        $this->getJson('/api/hr/advances')->assertOk();
    }

    /* ── Fix 1: the statutory registers ───────────────────────────────────── */

    /**
     * Every register, for both kinds of caller that could read them before.
     *
     * All six go through one private run() helper, so a single missed method
     * would show up here as one failing case rather than not at all.
     *
     * @dataProvider registerEndpoints
     */
    public function test_registers_are_closed_to_callers_without_hr_authority(string $path): void
    {
        $run = $this->payrollRun();

        Sanctum::actingAs($this->user('staff', 'plain@gates.test'));
        $this->getJson("/api/hr/payroll/runs/{$run->id}{$path}")->assertForbidden();

        Sanctum::actingAs($this->user('client', 'portal@gates.test', 'director'));
        $this->getJson("/api/hr/payroll/runs/{$run->id}{$path}")->assertForbidden();
    }

    /** @dataProvider registerEndpoints */
    public function test_registers_remain_open_to_hr_and_admin(string $path): void
    {
        $run = $this->payrollRun();

        Sanctum::actingAs($this->user('staff', 'hr@gates.test', 'hr_executive'));
        $this->getJson("/api/hr/payroll/runs/{$run->id}{$path}")->assertOk();

        Sanctum::actingAs($this->user('admin', 'boss@gates.test'));
        $this->getJson("/api/hr/payroll/runs/{$run->id}{$path}")->assertOk();
    }

    public static function registerEndpoints(): array
    {
        return [
            'bank advice'     => ['/bank-advice'],
            'bank advice CSV' => ['/bank-advice.csv'],
            'PF register'     => ['/registers/pf'],
            'ESIC register'   => ['/registers/esic'],
            'PT register'     => ['/registers/pt'],
            'LWF register'    => ['/registers/lwf'],
        ];
    }

    /**
     * The refusal comes BEFORE the lookup.
     *
     * Gating after findOrFail would answer 404 for a run that does not exist and
     * 403 for one that does, which tells an unauthorised caller how many payroll
     * runs the company has. Same answer either way is the point.
     */
    public function test_the_refusal_does_not_reveal_whether_a_run_exists(): void
    {
        $real = $this->payrollRun();

        Sanctum::actingAs($this->user('staff', 'prober@gates.test'));

        $this->getJson("/api/hr/payroll/runs/{$real->id}/registers/pf")->assertForbidden();
        $this->getJson('/api/hr/payroll/runs/999999/registers/pf')->assertForbidden();
    }
}
