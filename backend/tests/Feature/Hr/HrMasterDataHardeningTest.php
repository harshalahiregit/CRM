<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeShift;
use App\Models\Hr\HrJobRole;
use App\Models\Hr\HrLoanType;
use App\Models\Hr\HrProbationPolicy;
use App\Models\Hr\HrShift;
use App\Models\Hr\HrShiftRotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression protection for the five HR master-data modules.
 *
 * Loan Types, Probation Policies, Exit Policies, Job Roles and Shift Rotations
 * were all built long before this file and all work. Nothing here changes any
 * of them — these tests exist because the properties that make them SAFE were
 * held by nothing at all, and a refactor could have removed any of them
 * silently:
 *
 *   TENANT ISOLATION — every list, read, write and delete is scoped, and a
 *   record belonging to another workspace answers 404 rather than 403, because
 *   a 403 would confirm the id exists.
 *
 *   REFERENCE PROTECTION — a loan type with loans under it, a job role with
 *   employees in it and a rotation assigned to somebody all refuse deletion
 *   and say to deactivate instead. Historical records stay readable.
 *
 *   THE PERMISSION GATE — canManageHrQueue() on every write path.
 *
 * WHAT IS DELIBERATELY ASSERTED AS-IS rather than "fixed": probation and exit
 * policies have no delete endpoint at all, only an active/inactive toggle, so
 * their reference protection is that deletion is not reachable. That is the
 * existing product contract and these tests pin it rather than arguing with
 * it.
 */
class HrMasterDataHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'md-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'md-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    /** Somebody who may manage HR masters — the gate is canManageHrQueue(). */
    private function hr(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'HR',
            'email' => uniqid().'@md.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** Staff with no HR capability at all. Authenticated, and still refused. */
    private function outsider(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Nobody',
            'email' => uniqid().'@md.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function employee(?Tenant $t = null, array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs));
    }

    private function asHr(?Tenant $t = null): void
    {
        Sanctum::actingAs($this->hr($t));
    }

    /* ══════════════════════════════════════════════════════════════════
     | A. LOAN TYPES — /hr/loans/types
     ══════════════════════════════════════════════════════════════════ */

    private function loanType(array $data = [], ?Tenant $t = null): HrLoanType
    {
        return HrLoanType::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Personal Loan',
            'code' => 'PL', 'is_advance' => false, 'max_amount' => 100000,
            'requires_approval' => true, 'is_active' => true,
        ], $data));
    }

    public function test_loan_types_can_be_listed_created_updated_and_deactivated(): void
    {
        $this->asHr();

        $created = $this->postJson('/api/hr/loans/types', [
            'name' => 'Festival Advance', 'code' => 'FA', 'is_advance' => true,
            'max_amount' => 50000, 'requires_approval' => true,
        ])->assertStatus(201)->json();

        $id = $created['id'];

        $this->putJson("/api/hr/loans/types/{$id}", ['name' => 'Festival Advance', 'max_amount' => 75000])
            ->assertOk();

        $this->assertSame('75000.00', (string) HrLoanType::find($id)->max_amount);

        // Deactivation is an update of is_active — there is no separate route.
        $this->putJson("/api/hr/loans/types/{$id}", ['name' => 'Festival Advance', 'is_active' => false])->assertOk();
        $this->assertFalse(HrLoanType::find($id)->is_active);

        $listed = $this->getJson('/api/hr/loans/types')->assertOk()->json('data');
        $this->assertCount(1, $listed);
    }

    public function test_the_loan_type_list_can_be_filtered_to_active_only(): void
    {
        $this->loanType(['name' => 'Live One']);
        $this->loanType(['name' => 'Retired One', 'is_active' => false]);

        $this->asHr();

        // What a new-loan selector asks for: only types still on offer.
        $active = $this->getJson('/api/hr/loans/types?is_active=1')->assertOk()->json('data');

        $this->assertCount(1, $active);
        $this->assertSame('Live One', $active[0]['name']);
    }

    public function test_a_loan_type_name_is_unique_within_a_workspace_but_not_across_them(): void
    {
        $this->loanType(['name' => 'Personal Loan']);

        $this->asHr();
        // 409, from the (tenant_id, name) unique index rather than a service
        // check — LoanService has no assertUniqueName. The request IS rejected,
        // which is what matters here; the message quality is reported separately.
        $this->postJson('/api/hr/loans/types', ['name' => 'Personal Loan'])
            ->assertStatus(409);

        // The same name in another workspace is somebody else's business.
        $this->asHr($this->b);
        $this->postJson('/api/hr/loans/types', ['name' => 'Personal Loan'])->assertStatus(201);

        $this->assertSame(1, HrLoanType::where('tenant_id', $this->a->id)->count());
        $this->assertSame(1, HrLoanType::where('tenant_id', $this->b->id)->count());
    }

    public function test_a_loan_type_from_another_workspace_cannot_be_touched(): void
    {
        $theirs = $this->loanType(['name' => 'Theirs'], $this->b);

        $this->asHr();

        // 404, not 403 — a 403 would confirm the id is real.
        $this->putJson("/api/hr/loans/types/{$theirs->id}", ['name' => 'Stolen'])->assertStatus(404);
        $this->deleteJson("/api/hr/loans/types/{$theirs->id}")->assertStatus(404);

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_one_workspace_never_sees_anothers_loan_types(): void
    {
        $this->loanType(['name' => 'Alpha Loan']);
        $this->loanType(['name' => 'Beta Loan'], $this->b);

        $this->asHr();
        $mine = $this->getJson('/api/hr/loans/types')->assertOk()->json('data');

        $this->assertCount(1, $mine);
        $this->assertSame('Alpha Loan', $mine[0]['name']);
    }

    public function test_a_loan_type_with_loans_under_it_cannot_be_deleted(): void
    {
        $type = $this->loanType();
        $employee = $this->employee();

        $loan = HrEmployeeLoan::create([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'loan_type_id' => $type->id, 'principal' => 10000, 'interest_rate' => 0,
            'emi' => 1000, 'tenure_months' => 10, 'total_payable' => 10000,
            'total_repaid' => 0, 'outstanding' => 10000, 'status' => HrEmployeeLoan::SUBMITTED,
        ]);

        $this->asHr();
        $this->deleteJson("/api/hr/loans/types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Loans exist under this type. Deactivate it instead of deleting it.');

        // The type and the loan both survive, and the loan still resolves its
        // type — historical records stay readable.
        $this->assertNotNull($type->fresh());
        $this->assertNotNull($loan->fresh());
        $this->assertSame('Personal Loan', $loan->fresh()->loanType->name);
    }

    public function test_deactivating_a_loan_type_leaves_its_loans_alone(): void
    {
        $type = $this->loanType();
        $employee = $this->employee();

        $loan = HrEmployeeLoan::create([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'loan_type_id' => $type->id, 'principal' => 10000, 'interest_rate' => 0,
            'emi' => 1000, 'tenure_months' => 10, 'total_payable' => 10000,
            'total_repaid' => 0, 'outstanding' => 10000, 'status' => HrEmployeeLoan::SUBMITTED,
        ]);

        $this->asHr();
        $this->putJson("/api/hr/loans/types/{$type->id}", ['name' => 'Personal Loan', 'is_active' => false])->assertOk();

        $fresh = $loan->fresh();
        $this->assertSame('10000.00', (string) $fresh->principal);
        $this->assertSame($type->id, (int) $fresh->loan_type_id);
        $this->assertSame(HrEmployeeLoan::SUBMITTED, $fresh->status);
    }

    public function test_an_unused_loan_type_can_be_deleted(): void
    {
        $type = $this->loanType();

        $this->asHr();
        $this->deleteJson("/api/hr/loans/types/{$type->id}")->assertOk();

        $this->assertNull($type->fresh());
    }

    public function test_only_an_hr_manager_may_manage_loan_types(): void
    {
        $type = $this->loanType();

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/loans/types', ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson("/api/hr/loans/types/{$type->id}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson("/api/hr/loans/types/{$type->id}")->assertStatus(403);

        $this->assertSame('Personal Loan', $type->fresh()->name);
    }

    /* ══════════════════════════════════════════════════════════════════
     | B. PROBATION POLICIES — /hr/probation/policies
     ══════════════════════════════════════════════════════════════════ */

    private function probationType(?Tenant $t = null): int
    {
        return DB::table('hr_probation_types')->insertGetId([
            'tenant_id' => ($t ?: $this->a)->id, 'code' => 'STD'.substr(uniqid(), -4),
            'name' => 'Standard', 'default_duration_days' => 180,
            'confirmation_required' => true, 'review_required' => true,
            'extension_allowed' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_probation_policies_support_authorized_crud_and_status(): void
    {
        $typeId = $this->probationType();

        $this->asHr();

        $id = $this->postJson('/api/hr/probation/policies', [
            'name' => 'Standard Probation', 'probation_type_id' => $typeId,
            'review_frequency' => 'Monthly', 'notice_days' => 30, 'extension_limit' => 2,
        ])->assertStatus(201)->json('id');

        $this->putJson("/api/hr/probation/policies/{$id}", [
            'name' => 'Standard Probation', 'probation_type_id' => $typeId, 'notice_days' => 45,
        ])->assertOk();

        $this->assertSame(45, (int) HrProbationPolicy::find($id)->notice_days);

        $this->patchJson("/api/hr/probation/policies/{$id}/status", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) HrProbationPolicy::find($id)->is_active);

        $this->getJson("/api/hr/probation/policies/{$id}")->assertOk();
    }

    public function test_a_probation_policy_name_is_unique_within_a_workspace(): void
    {
        $typeId = $this->probationType();
        $this->asHr();

        $payload = ['name' => 'Standard', 'probation_type_id' => $typeId];
        $this->postJson('/api/hr/probation/policies', $payload)->assertStatus(201);
        $this->postJson('/api/hr/probation/policies', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A probation policy named “Standard” already exists.');

        // Another workspace may use the same name.
        $this->asHr($this->b);
        $this->postJson('/api/hr/probation/policies', [
            'name' => 'Standard', 'probation_type_id' => $this->probationType($this->b),
        ])->assertStatus(201);
    }

    public function test_a_probation_policy_rejects_configuration_from_another_workspace(): void
    {
        $this->asHr();

        // A probation type that belongs to Beta must not be selectable here.
        $this->postJson('/api/hr/probation/policies', [
            'name' => 'Cross', 'probation_type_id' => $this->probationType($this->b),
        ])->assertStatus(422)->assertJsonPath('message', 'Selected probation type is invalid.');

        $this->assertSame(0, HrProbationPolicy::where('tenant_id', $this->a->id)->count());
    }

    public function test_a_probation_policy_requires_a_name_and_a_type(): void
    {
        $this->asHr();

        $this->postJson('/api/hr/probation/policies', [])->assertStatus(422);
        $this->postJson('/api/hr/probation/policies', ['name' => 'No type'])->assertStatus(422);
        $this->postJson('/api/hr/probation/policies', [
            'name' => 'Bad frequency', 'probation_type_id' => $this->probationType(),
            'review_frequency' => 'Hourly',
        ])->assertStatus(422);
    }

    public function test_another_workspaces_probation_policy_is_not_found(): void
    {
        $theirs = HrProbationPolicy::create([
            'tenant_id' => $this->b->id, 'name' => 'Theirs',
            'probation_type_id' => $this->probationType($this->b),
            'review_frequency' => 'Monthly', 'notice_days' => 0,
            'extension_limit' => 1, 'is_active' => true,
        ]);

        $this->asHr();

        $this->getJson("/api/hr/probation/policies/{$theirs->id}")->assertStatus(404);
        $this->putJson("/api/hr/probation/policies/{$theirs->id}", [
            'name' => 'Stolen', 'probation_type_id' => $this->probationType(),
        ])->assertStatus(404);
        $this->patchJson("/api/hr/probation/policies/{$theirs->id}/status", ['is_active' => false])->assertStatus(404);

        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->assertTrue((bool) $theirs->fresh()->is_active);
    }

    public function test_deactivating_a_probation_policy_keeps_existing_probations_readable(): void
    {
        $typeId = $this->probationType();
        $policy = HrProbationPolicy::create([
            'tenant_id' => $this->a->id, 'name' => 'Standard', 'probation_type_id' => $typeId,
            'review_frequency' => 'Monthly', 'notice_days' => 0, 'extension_limit' => 1, 'is_active' => true,
        ]);
        $employee = $this->employee();

        $probationId = DB::table('hr_employee_probations')->insertGetId([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'probation_type_id' => $typeId, 'probation_policy_id' => $policy->id,
            'joining_date' => '2026-01-01',
            'probation_start_date' => '2026-01-01', 'probation_end_date' => '2026-07-01',
            'confirmation_due_date' => '2026-07-01', 'current_status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asHr();
        $this->patchJson("/api/hr/probation/policies/{$policy->id}/status", ['is_active' => false])->assertOk();

        $row = DB::table('hr_employee_probations')->find($probationId);
        $this->assertNotNull($row);
        $this->assertSame($policy->id, (int) $row->probation_policy_id);
        $this->assertNotNull($policy->fresh(), 'the policy must survive so history stays readable');
    }

    public function test_probation_policies_expose_no_delete_route(): void
    {
        $policy = HrProbationPolicy::create([
            'tenant_id' => $this->a->id, 'name' => 'Standard',
            'probation_type_id' => $this->probationType(),
            'review_frequency' => 'Monthly', 'notice_days' => 0, 'extension_limit' => 1, 'is_active' => true,
        ]);

        $this->asHr();

        // Reference protection here is that destruction is not reachable at
        // all: the only lifecycle is the active/inactive toggle. 405 from the
        // router, because GET/PUT/PATCH exist on this URI and DELETE does not.
        $this->deleteJson("/api/hr/probation/policies/{$policy->id}")->assertStatus(405);
        $this->assertNotNull($policy->fresh());
    }

    public function test_only_an_hr_manager_may_manage_probation_policies(): void
    {
        $typeId = $this->probationType();
        $policy = HrProbationPolicy::create([
            'tenant_id' => $this->a->id, 'name' => 'Standard', 'probation_type_id' => $typeId,
            'review_frequency' => 'Monthly', 'notice_days' => 0, 'extension_limit' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/probation/policies', ['name' => 'X', 'probation_type_id' => $typeId])->assertStatus(403);
        $this->putJson("/api/hr/probation/policies/{$policy->id}", ['name' => 'X', 'probation_type_id' => $typeId])->assertStatus(403);
        $this->patchJson("/api/hr/probation/policies/{$policy->id}/status", ['is_active' => false])->assertStatus(403);

        $this->assertTrue((bool) $policy->fresh()->is_active);
    }

    /* ══════════════════════════════════════════════════════════════════
     | C. EXIT POLICIES — /hr/exit/policies
     ══════════════════════════════════════════════════════════════════ */

    private function exitPolicy(array $data = [], ?Tenant $t = null): int
    {
        return DB::table('hr_exit_policies')->insertGetId(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Standard Exit',
            'notice_days' => 30, 'buyout_allowed' => false, 'recovery_allowed' => false,
            'leave_encashment' => false, 'gratuity_applicable' => false,
            'clearance_required' => true, 'exit_interview_required' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $data));
    }

    public function test_exit_policies_support_authorized_crud_and_status(): void
    {
        $this->asHr();

        $id = $this->postJson('/api/hr/exit/policies', [
            'name' => 'Standard Exit', 'notice_days' => 60, 'clearance_required' => true,
        ])->assertStatus(201)->json('id');

        $this->putJson("/api/hr/exit/policies/{$id}", ['name' => 'Standard Exit', 'notice_days' => 90])->assertOk();
        $this->assertSame(90, (int) DB::table('hr_exit_policies')->find($id)->notice_days);

        $this->patchJson("/api/hr/exit/policies/{$id}/status", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) DB::table('hr_exit_policies')->find($id)->is_active);

        $this->getJson("/api/hr/exit/policies/{$id}")->assertOk();
    }

    public function test_an_exit_policy_name_is_unique_within_a_workspace(): void
    {
        $this->asHr();

        $this->postJson('/api/hr/exit/policies', ['name' => 'Standard Exit'])->assertStatus(201);
        $this->postJson('/api/hr/exit/policies', ['name' => 'Standard Exit'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An exit policy named “Standard Exit” already exists.');

        $this->asHr($this->b);
        $this->postJson('/api/hr/exit/policies', ['name' => 'Standard Exit'])->assertStatus(201);
    }

    public function test_an_exit_policy_rejects_an_invalid_notice_period(): void
    {
        $this->asHr();

        $this->postJson('/api/hr/exit/policies', [])->assertStatus(422);
        $this->postJson('/api/hr/exit/policies', ['name' => 'Bad', 'notice_days' => -1])->assertStatus(422);
        $this->postJson('/api/hr/exit/policies', ['name' => 'Bad', 'notice_days' => 400])->assertStatus(422);
    }

    public function test_another_workspaces_exit_policy_is_not_found(): void
    {
        $theirs = $this->exitPolicy(['name' => 'Theirs'], $this->b);

        $this->asHr();

        $this->getJson("/api/hr/exit/policies/{$theirs}")->assertStatus(404);
        $this->putJson("/api/hr/exit/policies/{$theirs}", ['name' => 'Stolen'])->assertStatus(404);
        $this->patchJson("/api/hr/exit/policies/{$theirs}/status", ['is_active' => false])->assertStatus(404);

        $row = DB::table('hr_exit_policies')->find($theirs);
        $this->assertSame('Theirs', $row->name);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_one_workspace_never_lists_anothers_exit_policies(): void
    {
        $this->exitPolicy(['name' => 'Alpha Exit']);
        $this->exitPolicy(['name' => 'Beta Exit'], $this->b);

        $this->asHr();
        $rows = $this->getJson('/api/hr/exit/policies')->assertOk()->json();
        $names = collect($rows['data'] ?? $rows)->pluck('name')->all();

        $this->assertContains('Alpha Exit', $names);
        $this->assertNotContains('Beta Exit', $names);
    }

    public function test_deactivating_an_exit_policy_keeps_existing_exit_requests_valid(): void
    {
        $policyId = $this->exitPolicy();
        $employee = $this->employee();

        $typeId = DB::table('hr_exit_types')->insertGetId([
            'tenant_id' => $this->a->id, 'name' => 'Resignation', 'code' => 'RES',
            'notice_required' => true, 'default_notice_days' => 30,
            'clearance_required' => true, 'fnf_required' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $requestId = DB::table('hr_exit_requests')->insertGetId([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'exit_type_id' => $typeId, 'exit_policy_id' => $policyId,
            'request_date' => '2026-01-01', 'last_working_date' => '2026-02-01',
            'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asHr();
        $this->patchJson("/api/hr/exit/policies/{$policyId}/status", ['is_active' => false])->assertOk();

        $row = DB::table('hr_exit_requests')->find($requestId);
        $this->assertNotNull($row);
        $this->assertSame($policyId, (int) $row->exit_policy_id);
        $this->assertNotNull(DB::table('hr_exit_policies')->find($policyId));
    }

    public function test_exit_policies_expose_no_delete_route(): void
    {
        $policyId = $this->exitPolicy();

        $this->asHr();

        // 405 from the router: GET/PUT/PATCH are registered on this URI and
        // DELETE is not. The status is incidental — what is pinned is that no
        // destructive route exists and the record survives.
        $this->deleteJson("/api/hr/exit/policies/{$policyId}")->assertStatus(405);

        $this->assertNotNull(DB::table('hr_exit_policies')->find($policyId));
    }

    public function test_only_an_hr_manager_may_manage_exit_policies(): void
    {
        $policyId = $this->exitPolicy();

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/exit/policies', ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson("/api/hr/exit/policies/{$policyId}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->patchJson("/api/hr/exit/policies/{$policyId}/status", ['is_active' => false])->assertStatus(403);

        $this->assertTrue((bool) DB::table('hr_exit_policies')->find($policyId)->is_active);
    }

    /* ══════════════════════════════════════════════════════════════════
     | D. JOB ROLES — /hr/org-roles
     |
     | An org-chart role, NOT a designation and NOT an access permission.
     | hr_employees carries job_role_id and designation_id as separate columns
     | and these tests keep them separate.
     ══════════════════════════════════════════════════════════════════ */

    public function test_job_roles_support_authorized_crud(): void
    {
        $this->asHr();

        $this->postJson('/api/hr/org-roles', ['name' => 'Team Lead', 'code' => 'TL'])->assertStatus(201);
        $role = HrJobRole::where('tenant_id', $this->a->id)->firstOrFail();

        $this->putJson("/api/hr/org-roles/{$role->id}", ['name' => 'Squad Lead'])->assertOk();
        $this->assertSame('Squad Lead', $role->fresh()->name);

        $this->deleteJson("/api/hr/org-roles/{$role->id}")->assertOk();
        $this->assertNull($role->fresh());
    }

    public function test_a_job_role_name_is_unique_within_a_workspace_case_insensitively(): void
    {
        $this->asHr();

        $this->postJson('/api/hr/org-roles', ['name' => 'Team Lead'])->assertStatus(201);

        // assertUniqueName lowercases both sides.
        $this->postJson('/api/hr/org-roles', ['name' => 'team lead'])
            ->assertStatus(422)
            ->assertJsonPath('message', '“team lead” already exists.');

        $this->asHr($this->b);
        $this->postJson('/api/hr/org-roles', ['name' => 'Team Lead'])->assertStatus(201);

        $this->assertSame(1, HrJobRole::where('tenant_id', $this->a->id)->count());
        $this->assertSame(1, HrJobRole::where('tenant_id', $this->b->id)->count());
    }

    public function test_another_workspaces_job_role_is_not_found(): void
    {
        $theirs = HrJobRole::create(['tenant_id' => $this->b->id, 'name' => 'Theirs', 'is_active' => true]);

        $this->asHr();

        $this->putJson("/api/hr/org-roles/{$theirs->id}", ['name' => 'Stolen'])->assertStatus(404);
        $this->deleteJson("/api/hr/org-roles/{$theirs->id}")->assertStatus(404);

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_one_workspace_never_lists_anothers_job_roles(): void
    {
        HrJobRole::create(['tenant_id' => $this->a->id, 'name' => 'Alpha Role', 'is_active' => true]);
        HrJobRole::create(['tenant_id' => $this->b->id, 'name' => 'Beta Role', 'is_active' => true]);

        $this->asHr();
        $names = collect($this->getJson('/api/hr/org-roles')->assertOk()->json())->pluck('name')->all();

        $this->assertSame(['Alpha Role'], $names);
    }

    public function test_a_job_role_assigned_to_employees_cannot_be_deleted(): void
    {
        $role = HrJobRole::create(['tenant_id' => $this->a->id, 'name' => 'Team Lead', 'is_active' => true]);
        $employee = $this->employee(null, ['job_role_id' => $role->id]);

        $this->asHr();
        $this->deleteJson("/api/hr/org-roles/{$role->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete role “Team Lead” — 1 employee(s) are assigned to it.');

        $this->assertNotNull($role->fresh());
        $this->assertSame($role->id, (int) $employee->fresh()->job_role_id);
    }

    public function test_deactivating_a_job_role_leaves_its_employees_assigned(): void
    {
        $role = HrJobRole::create(['tenant_id' => $this->a->id, 'name' => 'Team Lead', 'is_active' => true]);
        $employee = $this->employee(null, ['job_role_id' => $role->id, 'designation' => 'Analyst']);

        $this->asHr();
        $this->putJson("/api/hr/org-roles/{$role->id}", ['name' => 'Team Lead', 'is_active' => false])->assertOk();

        $fresh = $employee->fresh();
        $this->assertSame($role->id, (int) $fresh->job_role_id);
        $this->assertSame('Active', $fresh->status);
        // The designation is a different concept and is untouched by any of this.
        $this->assertSame('Analyst', $fresh->designation);
    }

    public function test_only_an_hr_manager_may_manage_job_roles(): void
    {
        $role = HrJobRole::create(['tenant_id' => $this->a->id, 'name' => 'Team Lead', 'is_active' => true]);

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/org-roles', ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson("/api/hr/org-roles/{$role->id}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson("/api/hr/org-roles/{$role->id}")->assertStatus(403);

        $this->assertSame('Team Lead', $role->fresh()->name);
    }

    /* ══════════════════════════════════════════════════════════════════
     | E. SHIFT ROTATIONS — /hr/shifts/rotations
     |
     | A rotation is a PLAN made of shifts, not a shift. The two vocabularies
     | stay separate and nothing here touches hr_shifts semantics.
     ══════════════════════════════════════════════════════════════════ */

    private function shift(string $name = 'General', ?Tenant $t = null): HrShift
    {
        return HrShift::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).substr(uniqid(), -3),
            'shift_type' => HrShift::FIXED, 'full_day_hours' => 8, 'half_day_hours' => 4,
            'is_active' => true,
        ]);
    }

    private function rotation(array $data = [], ?Tenant $t = null): HrShiftRotation
    {
        return HrShiftRotation::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Two Week',
            'code' => 'TW', 'is_active' => true,
        ], $data));
    }

    public function test_shift_rotations_support_authorized_crud_with_steps(): void
    {
        $morning = $this->shift('Morning');
        $night = $this->shift('Night');

        $this->asHr();

        $created = $this->postJson('/api/hr/shifts/rotations', [
            'name' => 'Two Week', 'code' => 'TW',
            'steps' => [
                ['shift_id' => $morning->id, 'duration_days' => 7],
                ['shift_id' => $night->id, 'duration_days' => 7],
            ],
        ])->assertStatus(201)->json();

        $id = $created['id'] ?? $created['data']['id'];

        $this->assertSame(2, DB::table('hr_shift_rotation_steps')->where('rotation_id', $id)->count());

        $this->putJson("/api/hr/shifts/rotations/{$id}", [
            'name' => 'Three Week',
            'steps' => [['shift_id' => $morning->id, 'duration_days' => 21]],
        ])->assertOk();

        $this->assertSame('Three Week', HrShiftRotation::find($id)->name);
        // Steps are replaced wholesale, not appended.
        $this->assertSame(1, DB::table('hr_shift_rotation_steps')->where('rotation_id', $id)->count());

        $this->getJson('/api/hr/shifts/rotations')->assertOk();
    }

    public function test_a_rotation_cannot_be_built_from_another_workspaces_shift(): void
    {
        $theirShift = $this->shift('Theirs', $this->b);

        $this->asHr();
        $this->postJson('/api/hr/shifts/rotations', [
            'name' => 'Cross', 'steps' => [['shift_id' => $theirShift->id, 'duration_days' => 7]],
        ])->assertStatus(404);

        $this->assertSame(0, HrShiftRotation::where('tenant_id', $this->a->id)->count());
    }

    public function test_rotation_step_data_is_validated(): void
    {
        $shift = $this->shift();
        $this->asHr();

        $this->postJson('/api/hr/shifts/rotations', [])->assertStatus(422);
        $this->postJson('/api/hr/shifts/rotations', [
            'name' => 'Bad', 'steps' => [['duration_days' => 7]],
        ])->assertStatus(422);
        $this->postJson('/api/hr/shifts/rotations', [
            'name' => 'Bad', 'steps' => [['shift_id' => $shift->id, 'duration_days' => 0]],
        ])->assertStatus(422);
    }

    public function test_another_workspaces_rotation_is_not_found(): void
    {
        $theirs = $this->rotation(['name' => 'Theirs'], $this->b);

        $this->asHr();

        $this->putJson("/api/hr/shifts/rotations/{$theirs->id}", ['name' => 'Stolen'])->assertStatus(404);
        $this->deleteJson("/api/hr/shifts/rotations/{$theirs->id}")->assertStatus(404);

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_one_workspace_never_lists_anothers_rotations(): void
    {
        $this->rotation(['name' => 'Alpha Rotation']);
        $this->rotation(['name' => 'Beta Rotation'], $this->b);

        $this->asHr();
        $body = $this->getJson('/api/hr/shifts/rotations')->assertOk()->json();
        $names = collect($body['data'] ?? $body)->pluck('name')->all();

        $this->assertContains('Alpha Rotation', $names);
        $this->assertNotContains('Beta Rotation', $names);
    }

    public function test_a_rotation_assigned_to_employees_cannot_be_deleted(): void
    {
        $rotation = $this->rotation();
        $employee = $this->employee();

        HrEmployeeShift::create([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'rotation_id' => $rotation->id, 'effective_from' => '2026-01-01',
        ]);

        $this->asHr();
        $this->deleteJson("/api/hr/shifts/rotations/{$rotation->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This rotation is assigned to employees. Deactivate it instead of deleting it.');

        $this->assertNotNull($rotation->fresh());
        $this->assertSame(1, HrEmployeeShift::where('rotation_id', $rotation->id)->count());
    }

    public function test_deactivating_a_rotation_leaves_assignments_intact(): void
    {
        $rotation = $this->rotation();
        $employee = $this->employee();

        $assignment = HrEmployeeShift::create([
            'tenant_id' => $this->a->id, 'employee_id' => $employee->id,
            'rotation_id' => $rotation->id, 'effective_from' => '2026-01-01',
        ]);

        $this->asHr();
        $this->putJson("/api/hr/shifts/rotations/{$rotation->id}", ['name' => 'Two Week', 'is_active' => false])->assertOk();

        $this->assertFalse((bool) $rotation->fresh()->is_active);
        $this->assertNotNull($assignment->fresh());
        $this->assertNull($assignment->fresh()->effective_to, 'an assignment must not be closed by a master edit');
    }

    public function test_an_unassigned_rotation_can_be_deleted_with_its_steps(): void
    {
        $shift = $this->shift();
        $this->asHr();

        $created = $this->postJson('/api/hr/shifts/rotations', [
            'name' => 'Disposable', 'steps' => [['shift_id' => $shift->id, 'duration_days' => 7]],
        ])->assertStatus(201)->json();

        $id = $created['id'] ?? $created['data']['id'];

        $this->deleteJson("/api/hr/shifts/rotations/{$id}")->assertOk();

        $this->assertNull(HrShiftRotation::find($id));
        $this->assertSame(0, DB::table('hr_shift_rotation_steps')->where('rotation_id', $id)->count());
        // The shift the plan referred to is a separate master and survives.
        $this->assertNotNull($shift->fresh());
    }

    public function test_only_an_hr_manager_may_manage_rotations(): void
    {
        $rotation = $this->rotation();

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/shifts/rotations', ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson("/api/hr/shifts/rotations/{$rotation->id}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson("/api/hr/shifts/rotations/{$rotation->id}")->assertStatus(403);

        $this->assertSame('Two Week', $rotation->fresh()->name);
    }

    /* ══════════════════════════════════════════════════════════════════
     | Cross-cutting
     ══════════════════════════════════════════════════════════════════ */

    public function test_every_master_write_path_refuses_an_unauthenticated_caller(): void
    {
        foreach ([
            '/api/hr/loans/types',
            '/api/hr/probation/policies',
            '/api/hr/exit/policies',
            '/api/hr/org-roles',
            '/api/hr/shifts/rotations',
        ] as $path) {
            $this->postJson($path, ['name' => 'X'])->assertStatus(401);
        }
    }
}
