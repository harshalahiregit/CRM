<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The last unguarded clause on the attendance app's approver door.
 *
 * denyApprover() is deny() widened by one person — the line manager — and it
 * asks that question FIRST:
 *
 *     if ($user instanceof User && $this->managesAnyone($user)) return null;
 *
 * managesAnyone() asks a database question rather than a role question: does any
 * employee row point its reporting_manager_id at this login's employee record.
 * That is true or false for any row in `users`, portal logins included, so it
 * did not inherit the account-type boundary the way the other two clauses
 * (canManageHrQueue, holdsAnyTierRole) now do.
 *
 * It was not reachable — it needs a client, vendor, company or doctor account
 * linked to an HrEmployee that has direct reports, and that linking is an HR
 * action. These tests build exactly that situation on purpose, because "no data
 * currently looks like this" is a fact about today, not a guarantee.
 *
 * The five approval endpoints behind denyApprover(): pending-approvals,
 * approve-reject-leave, -advance, -raise, -reimbursement.
 */
class HrmApproverBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL_ROLES = ['client', 'vendor', 'third_party_vendor', 'company', 'doctor'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Approver', 'slug' => 'hrm-approver-boundary', 'status' => 'active',
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

    private function employee(string $code, ?User $user = null, ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    /** Give this login a real line-management position: an employee record with a report. */
    private function makeManager(User $user, string $code): void
    {
        $manager = $this->employee($code, $user);
        $this->employee($code.'-R', null, $manager->id);
    }

    /** The app answers HTTP 200 for a refusal, so the verdict is in the body. */
    private function assertRefused($response, string $why): void
    {
        $response->assertOk();
        $this->assertSame('You do not have access to this.', $response->json('message'), $why);
    }

    private function assertAdmitted($response, string $why): void
    {
        $this->assertNotSame('You do not have access to this.', $response->json('message'), $why);
    }

    /* ── the hole, built deliberately ─────────────────────────────────── */

    /**
     * A portal login wearing a real line-management position.
     *
     * Every precondition managesAnyone() cares about is satisfied — an employee
     * record, a direct report, the same tenant. Only the account type refuses.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_account_managing_a_real_employee_is_still_refused(string $role): void
    {
        $user = $this->user($role, "mgr-{$role}@approver.test");
        $this->makeManager($user, 'PM-'.strtoupper(substr($role, 0, 3)));

        Sanctum::actingAs($user);

        $this->assertRefused($this->getJson('/api/Hrm/admin/pending-approvals'),
            "A {$role} with a report beneath them is still not an HR approver.");
    }

    /**
     * And with an HR-looking internal_role on top, so no clause can be the one
     * that lets them in.
     *
     * @dataProvider portalRoles
     */
    public function test_a_forged_hr_role_string_does_not_bypass_the_boundary(string $role): void
    {
        foreach (['hr_executive', 'hr_recruiter', 'director', 'accounts', 'department_head'] as $internal) {
            $user = $this->user($role, "forge-{$role}-{$internal}@approver.test", $internal);
            $this->makeManager($user, 'FG-'.substr(md5($role.$internal), 0, 6));

            Sanctum::actingAs($user);

            $this->assertRefused($this->getJson('/api/Hrm/admin/pending-approvals'),
                "A {$role} carrying '{$internal}' AND managing somebody must still be refused.");
        }
    }

    /** The helper itself, independent of routing. */
    public function test_portal_accounts_do_not_pass_manages_anyone(): void
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\Api\Hrm\HrmAdminController::class, 'managesAnyone');
        $method->setAccessible(true);
        $controller = app(\App\Http\Controllers\Api\Hrm\HrmAdminController::class);

        foreach (self::PORTAL_ROLES as $role) {
            $user = $this->user($role, "direct-{$role}@approver.test", 'hr_executive');
            $this->makeManager($user, 'DR-'.strtoupper(substr($role, 0, 3)));

            $this->assertFalse($method->invoke($controller, $user),
                "managesAnyone() must refuse a {$role} regardless of the hierarchy beneath them.");
        }
    }

    /** A staff line manager passes it, which is the behaviour being preserved. */
    public function test_a_staff_line_manager_still_passes_manages_anyone(): void
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\Api\Hrm\HrmAdminController::class, 'managesAnyone');
        $method->setAccessible(true);
        $controller = app(\App\Http\Controllers\Api\Hrm\HrmAdminController::class);

        $user = $this->user('staff', 'realmgr@approver.test');
        $this->makeManager($user, 'SM-1');

        $this->assertTrue($method->invoke($controller, $user));

        $lonely = $this->user('staff', 'lonely@approver.test');
        $this->employee('SM-2', $lonely);

        $this->assertFalse($method->invoke($controller, $lonely),
            'A staff member with an employee record but nobody reporting to them is not a manager — unchanged.');
    }

    /* ── nothing legitimate was taken away ────────────────────────────── */

    public function test_a_staff_line_manager_still_reaches_the_approval_queue(): void
    {
        $user = $this->user('staff', 'queue-mgr@approver.test');
        $this->makeManager($user, 'QM-1');

        Sanctum::actingAs($user);

        $this->assertAdmitted($this->getJson('/api/Hrm/admin/pending-approvals'),
            'A real line manager approves their reports from the phone — this must not change.');
    }

    public function test_admin_and_hr_still_reach_the_approval_queue(): void
    {
        foreach ([['admin', null], ['staff', 'hr_executive'], ['staff', 'accounts'], ['staff', 'director']] as $i => [$role, $internal]) {
            Sanctum::actingAs($this->user($role, "ok-{$i}@approver.test", $internal));

            $this->assertAdmitted($this->getJson('/api/Hrm/admin/pending-approvals'),
                ($internal ?? $role).' must still reach the approval queue.');
        }
    }

    /**
     * deny() is the other door and was already guarded. Re-checked so a change
     * to one is never mistaken for cover over the other.
     */
    public function test_the_wider_admin_door_is_unchanged(): void
    {
        Sanctum::actingAs($this->user('staff', 'dash-hr@approver.test', 'hr_executive'));
        $this->assertAdmitted($this->getJson('/api/Hrm/admin/dashboard'), 'HR still reaches the app dashboard.');

        Sanctum::actingAs($this->user('staff', 'dash-plain@approver.test'));
        $this->assertRefused($this->getJson('/api/Hrm/admin/dashboard'),
            'A plain staff member never reached it and still does not.');

        $mgr = $this->user('staff', 'dash-mgr@approver.test');
        $this->makeManager($mgr, 'DM-1');
        Sanctum::actingAs($mgr);
        $this->assertRefused($this->getJson('/api/Hrm/admin/dashboard'),
            'A line manager reaches the APPROVAL queue, not payroll and the employee list — unchanged.');
    }

    public static function portalRoles(): array
    {
        return array_combine(
            self::PORTAL_ROLES,
            array_map(fn ($r) => [$r], self::PORTAL_ROLES),
        );
    }
}
