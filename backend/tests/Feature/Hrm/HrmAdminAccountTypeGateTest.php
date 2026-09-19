<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceTierService;
use App\Support\Hr\AdvanceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The attendance app's admin door, and the role string that used to open it.
 *
 * All twenty /api/Hrm/admin/* methods admit through HrmAdminController::deny(),
 * which asks three questions — isAdmin, canManageHrQueue, holdsAnyTierRole. The
 * first two pin `users.role` themselves. The third did not: it matched
 * AdvanceTierService::TIER_ROLES against internal_role, a free string that every
 * account carries, so a CLIENT whose internal_role read 'director' held an
 * approval rung and reached the dashboard, the employee list, payroll, salaries
 * and the advance approvals from the phone.
 *
 * The web queue was already shut by EnsureCanAccessAdvances. This is the same
 * rule reaching the door the app uses.
 *
 * Note what is NOT asserted here: nothing about authentication. The app signs in
 * the way it always did, and these tests act as an already-authenticated user on
 * purpose — the question is only what that user may then do.
 */
class HrmAdminAccountTypeGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'App Gates', 'slug' => 'hrm-admin-gates', 'status' => 'active',
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

    /**
     * The app answers HTTP 200 for a refusal — it treats 401 as a dead session
     * and would sign somebody out mid-shift. The verdict is in the body, so
     * asserting on the status code would pass while the door stood open.
     */
    private function assertRefused($response, string $why): void
    {
        $response->assertOk()->assertJsonPath('status', 0);
        $this->assertSame('You do not have access to this.', $response->json('message'), $why);
    }

    private function assertAdmitted($response, string $why): void
    {
        $this->assertNotSame('You do not have access to this.', $response->json('message'), $why);
    }

    /* ── the rule itself ──────────────────────────────────────────────── */

    /**
     * Every non-staff account type, against every tier role string.
     *
     * Parameterised rather than written out, because the failure this guards
     * against is somebody adding a sixth portal and nobody noticing it inherited
     * the ladder.
     *
     * @dataProvider portalAccountsOnTierRoles
     */
    public function test_a_portal_identity_holds_no_tier_rung(string $role, string $internal): void
    {
        $user = $this->user($role, "tier-{$role}-{$internal}@gates.test", $internal);

        $this->assertFalse(
            app(AdvanceTierService::class)->holdsAnyTierRole($user),
            "A {$role} carrying internal_role '{$internal}' must hold no rung on the advance ladder."
        );
    }

    public static function portalAccountsOnTierRoles(): array
    {
        $cases = [];

        foreach (['client', 'vendor', 'third_party_vendor', 'company', 'doctor'] as $role) {
            foreach (['accounts', 'accountant', 'finance', 'director', 'md', 'ceo'] as $internal) {
                $cases["{$role} as {$internal}"] = [$role, $internal];
            }
        }

        return $cases;
    }

    /** The half that must not move: real staff on a rung keep it. */
    public function test_staff_on_a_rung_keep_it(): void
    {
        $service = app(AdvanceTierService::class);

        foreach (['accounts', 'accountant', 'finance', 'director', 'md', 'ceo'] as $internal) {
            $this->assertTrue(
                $service->holdsAnyTierRole($this->user('staff', "staff-{$internal}@gates.test", $internal)),
                "A staff member with internal_role '{$internal}' stands on the ladder as before."
            );
        }

        $this->assertFalse(
            $service->holdsAnyTierRole($this->user('staff', 'plain@gates.test')),
            'A staff member on no rung still holds none — unchanged.'
        );
        $this->assertFalse(
            $service->holdsAnyTierRole($this->user('admin', 'boss@gates.test')),
            'An admin holds no rung BY ROLE and never did; they stand in via isAdmin() elsewhere.'
        );
    }

    /* ── the app's admin screens ──────────────────────────────────────── */

    /**
     * deny() guards fifteen of the twenty methods; these four stand for the
     * kinds of thing behind it — headcount, the staff directory, the roles a
     * person may be given, and payroll.
     *
     * @dataProvider denyGatedEndpoints
     */
    public function test_portal_identities_cannot_reach_the_app_admin_screens(string $uri): void
    {
        foreach (['client', 'vendor', 'third_party_vendor', 'company', 'doctor'] as $role) {
            Sanctum::actingAs($this->user($role, "portal-{$role}@gates.test", 'director'));

            $this->assertRefused(
                $this->getJson($uri),
                "A {$role} carrying 'director' must not reach {$uri}."
            );
        }
    }

    /** @dataProvider denyGatedEndpoints */
    public function test_staff_and_admin_still_reach_the_app_admin_screens(string $uri): void
    {
        foreach ([['admin', null], ['staff', 'hr_executive'], ['staff', 'accounts'], ['staff', 'director']] as $i => [$role, $internal]) {
            Sanctum::actingAs($this->user($role, "ok-{$i}@gates.test", $internal));

            $this->assertAdmitted(
                $this->getJson($uri),
                ($internal ?? $role)." must still reach {$uri} — this fix takes nothing from staff."
            );
        }
    }

    public static function denyGatedEndpoints(): array
    {
        return [
            'dashboard'       => ['/api/Hrm/admin/dashboard'],
            'employee list'   => ['/api/Hrm/admin/employees-list'],
            'assignable roles' => ['/api/Hrm/admin/assignable-roles'],
            'payroll overview' => ['/api/Hrm/admin/payroll-overview'],
        ];
    }

    /* ── the approval endpoints ───────────────────────────────────────── */

    /**
     * The advance approval endpoint specifically.
     *
     * It admits through denyApprover(), which is deny() widened by one person —
     * the line manager. A portal identity satisfies neither, and the assertion
     * is on the refusal MESSAGE rather than on failure generally: before the fix
     * a client got as far as "The advance id field is required", which is a
     * validation error and would satisfy a looser check.
     */
    public function test_portal_identities_cannot_use_the_app_advance_approval(): void
    {
        $employee = $this->employee('AP-1');

        $advance = HrAdvance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'purpose' => 'Site visit', 'amount_requested' => 5000, 'advance_type' => 'travel',
            'status' => AdvanceStage::PENDING,
        ]);

        foreach (['client', 'vendor', 'third_party_vendor', 'company', 'doctor'] as $role) {
            Sanctum::actingAs($this->user($role, "approver-{$role}@gates.test", 'director'));

            $this->assertRefused(
                $this->postJson('/api/Hrm/admin/approve-reject-advance', [
                    'advance_id' => $advance->id,
                    'status'     => 'approved',
                ]),
                "A {$role} carrying 'director' must not decide an advance from the app."
            );
        }

        $this->assertSame(
            AdvanceStage::PENDING,
            $advance->fresh()->status,
            'The advance must be untouched — a refused decision may not have moved it.'
        );
    }

    /** Same endpoint, the people who are supposed to use it. */
    public function test_staff_approvers_still_reach_the_app_advance_approval(): void
    {
        $employee = $this->employee('AP-2');

        $advance = HrAdvance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'purpose' => 'Site visit', 'amount_requested' => 5000, 'advance_type' => 'travel',
            'status' => AdvanceStage::PENDING,
        ]);

        foreach ([['admin', null], ['staff', 'accounts'], ['staff', 'director']] as $i => [$role, $internal]) {
            Sanctum::actingAs($this->user($role, "sok-{$i}@gates.test", $internal));

            $this->assertAdmitted(
                $this->postJson('/api/Hrm/admin/approve-reject-advance', [
                    'advance_id' => $advance->id,
                    'status'     => 'approved',
                ]),
                ($internal ?? $role).' must still reach the approval endpoint.'
            );
        }
    }

    /**
     * A line manager reaches the approval queue through the hierarchy.
     *
     * Pinned because denyApprover() checks managesAnyone() BEFORE falling back to
     * deny(), so a mistake in the rung check would surface here as "managers lost
     * the app" rather than as a security change.
     */
    public function test_a_line_manager_still_reaches_the_approval_queue(): void
    {
        $managerUser = $this->user('staff', 'linemgr@gates.test');
        $manager     = $this->employee('LM-1', $managerUser);
        $this->employee('LM-2', null, $manager->id);

        Sanctum::actingAs($managerUser);

        $this->assertAdmitted(
            $this->getJson('/api/Hrm/admin/pending-approvals'),
            'A real line manager approves their reports from the phone — unchanged.'
        );
    }

    /* ── scoping callers must narrow, never widen ─────────────────────── */

    /**
     * holdsAnyTierRole() is also read by two methods that SCOPE rather than
     * admit. A false there must mean "not an overseer" and fall through to the
     * hierarchy lookup — which for an account with no employee record resolves
     * to nothing. If it ever resolved to everything, this fix would have widened
     * access while appearing to narrow it.
     */
    public function test_a_non_overseer_is_scoped_to_nothing_rather_than_everything(): void
    {
        $employee = $this->employee('SC-1');

        HrAdvance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'purpose' => 'Other', 'amount_requested' => 1000, 'advance_type' => 'travel',
            'status' => AdvanceStage::PENDING,
        ]);

        $outsider = $this->user('client', 'outsider@gates.test', 'director');

        $rows = app(AdvanceTierService::class)
            ->scopeQueue(HrAdvance::query()->where('tenant_id', $this->tenant->id), $outsider)
            ->get();

        $this->assertCount(0, $rows, 'An account off the ladder with no employee record sees nothing.');
    }
}
