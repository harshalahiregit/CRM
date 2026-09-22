<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\ReimbursementStatus;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reimbursement reads, brought in line with every other operational surface.
 *
 * The boundary here was holding on the verb and leaking on the noun. DECIDING
 * was already scoped — approve() and decline() pass through
 * ApprovalEngine::assertMayDecide(), whose second gate is
 * assertCanActOnEmployee(), since expense claims joined the engine — but READING
 * was not. index() filtered on tenant_id alone and find() did the same, so a
 * department-scoped user could list every claim in the workspace, open any of
 * them, put one on hold, write a note on it, and download somebody else's
 * receipts.
 *
 * That last one is the reason the assertion belongs in find() rather than in
 * each method: the attachment download is then behind exactly the authorisation
 * of the claim that owns the file, with no second rule to keep in step.
 *
 * The 404 is asserted as a status, not merely as "not a success". A 403 would
 * confirm the claim exists and sits in another department, which is the thing
 * being withheld.
 */
class ScopedReimbursementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrEmployee $mate;       // same department as the actor
    private HrEmployee $outsider;   // another department

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'scoped-reimb', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function actor(string $scope, string $email = 'actor@reimb.test'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R'.substr(md5($email.$scope), 0, 6),
            'slug' => 'r_'.substr(md5($email.$scope), 0, 6),
            'permissions' => [
                // The route group's gate. Held by every actor here, so the only
                // thing under test is the scope.
                'hr_attendance' => [StaffPermission::VIEW_GLOBAL],
                'hr_employees'  => [StaffPermission::VIEW_GLOBAL],
            ],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id,
        ]);
    }

    private function cast(string $scope = DataScope::DEPARTMENT): User
    {
        $user = $this->actor($scope);
        $this->employee('R-1', $user, 'Ops');
        $this->mate     = $this->employee('R-2', null, 'Ops');
        $this->outsider = $this->employee('R-3', null, 'Sales');

        return $user;
    }

    private function claim(HrEmployee $employee, string $status = ReimbursementStatus::PENDING): HrReimbursement
    {
        return HrReimbursement::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'title' => 'Taxi to site', 'category' => 'Travel',
            'expense_date' => '2026-09-01', 'amount_claimed' => 1200,
            'status' => $status,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@reimb.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /* ── 1. the list ──────────────────────────────────────────────────── */

    public function test_the_queue_shows_only_the_departments_own_claims(): void
    {
        $user = $this->cast();
        $mine   = $this->claim($this->mate);
        $theirs = $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/reimbursements')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_global_actor_still_sees_everything(): void
    {
        $this->cast();
        $mine   = $this->claim($this->mate);
        $theirs = $this->claim($this->outsider);

        Sanctum::actingAs($this->admin());

        $ids = collect($this->getJson('/api/hr/reimbursements')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    public function test_an_own_scoped_actor_sees_only_their_own_claim(): void
    {
        $user = $this->actor(DataScope::OWN, 'own@reimb.test');
        $me = $this->employee('O-1', $user, 'Ops');
        $other = $this->employee('O-2', null, 'Ops');

        $mine   = $this->claim($me);
        $theirs = $this->claim($other);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/reimbursements')->assertOk()->json('data'))
            ->pluck('id')->all();

        // Same department is not enough under OWN — only their own row.
        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_team_scoped_actor_sees_their_reports(): void
    {
        $user = $this->actor(DataScope::TEAM, 'team@reimb.test');
        $me = $this->employee('T-1', $user, 'Ops');
        $report = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'T-2', 'name' => 'Report',
            'department' => 'Sales', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'reporting_manager_id' => $me->id,
        ]);
        $stranger = $this->employee('T-3', null, 'Sales');

        $mine   = $this->claim($report);
        $theirs = $this->claim($stranger);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/reimbursements')->assertOk()->json('data'))
            ->pluck('id')->all();

        // The report is in ANOTHER department, so this proves the team lookup
        // rather than a department match that happens to agree.
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_filtering_by_an_out_of_scope_employee_returns_nothing(): void
    {
        $user = $this->cast();
        $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $rows = $this->getJson('/api/hr/reimbursements?employee_id='.$this->outsider->id)
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    /* ── 2. the BRANCH asymmetry, as on every other surface ───────────── */

    public function test_a_branch_scoped_actor_is_not_narrowed_on_the_list(): void
    {
        // hr_employees.branch is free text with no master, so BRANCH is
        // excluded from COLLECTION scoping everywhere and an unsupported scope
        // reads as global. Asserted so changing it stays a decision.
        $user = $this->actor(DataScope::BRANCH, 'branch@reimb.test');
        $me = $this->employee('B-1', $user, 'Ops');
        $me->update(['branch' => 'North']);

        $elsewhere = $this->employee('B-2', null, 'Sales');
        $elsewhere->update(['branch' => 'South']);
        $claim = $this->claim($elsewhere);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/reimbursements')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($claim->id, $ids);
    }

    public function test_a_branch_scoped_actor_is_still_refused_on_a_single_record(): void
    {
        // The other half: canActOnEmployee() consults every scope, branch
        // included, so the id-based paths DO narrow even though the list does not.
        $user = $this->actor(DataScope::BRANCH, 'branch2@reimb.test');
        $me = $this->employee('B-3', $user, 'Ops');
        $me->update(['branch' => 'North']);

        $elsewhere = $this->employee('B-4', null, 'Sales');
        $elsewhere->update(['branch' => 'South']);
        $claim = $this->claim($elsewhere);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/reimbursements/{$claim->id}")->assertStatus(404);
    }

    /* ── 3. every path through find() ─────────────────────────────────── */

    public function test_an_out_of_scope_claim_is_not_readable(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/reimbursements/{$claim->id}")->assertStatus(404);
    }

    public function test_an_out_of_scope_claim_cannot_be_held(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", ['reason' => 'Which project?'])
            ->assertStatus(404);
        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    public function test_an_out_of_scope_claim_cannot_be_noted_on(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/reimbursements/{$claim->id}/note", ['body' => 'Looks off.'])
            ->assertStatus(404);
    }

    public function test_an_out_of_scope_receipt_cannot_be_downloaded(): void
    {
        \Storage::fake('local');

        $user = $this->cast();

        // A REAL file on the out-of-scope claim. An invented attachment id
        // would be refused by findOrFail whether or not the scope assertion
        // existed, so the test would pass while proving nothing — it has to be
        // downloadable for the refusal to mean anything.
        $owner = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Out', 'email' => 'out@reimb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->outsider->update(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/hr/me/reimbursements', [
            'title' => 'Their dinner', 'expense_date' => '2026-09-01',
            'amount_claimed' => 900,
            'files' => [\Illuminate\Http\UploadedFile::fake()->image('receipt.jpg')],
        ])->assertCreated();

        $claim = HrReimbursement::where('employee_id', $this->outsider->id)->firstOrFail();
        $file  = $claim->attachments()->firstOrFail();

        // The owner can fetch their own receipt, so the file genuinely serves.
        $this->get("/api/hr/me/reimbursements/{$claim->id}/attachments/{$file->id}")->assertOk();

        // The out-of-scope approver cannot. The file inherits the claim's
        // authorisation rather than carrying a rule of its own.
        Sanctum::actingAs($user);
        $this->get("/api/hr/reimbursements/{$claim->id}/attachments/{$file->id}")->assertStatus(404);
    }

    public function test_an_in_scope_receipt_can_be_downloaded(): void
    {
        \Storage::fake('local');

        $user = $this->cast();

        $owner = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mate', 'email' => 'mate@reimb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->mate->update(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/hr/me/reimbursements', [
            'title' => 'Our dinner', 'expense_date' => '2026-09-01',
            'amount_claimed' => 900,
            'files' => [\Illuminate\Http\UploadedFile::fake()->image('receipt.jpg')],
        ])->assertCreated();

        $claim = HrReimbursement::where('employee_id', $this->mate->id)->firstOrFail();
        $file  = $claim->attachments()->firstOrFail();

        // Inside the boundary the receipt still serves — the refusal above is
        // the scope, not a broken download path.
        Sanctum::actingAs($user);
        $this->get("/api/hr/reimbursements/{$claim->id}/attachments/{$file->id}")->assertOk();
    }

    public function test_the_refusal_is_a_404_and_not_a_403(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->outsider);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/hr/reimbursements/{$claim->id}");

        $response->assertStatus(404);
        $this->assertNotSame(403, $response->status());
    }

    /* ── 4. inside the boundary, nothing moved ────────────────────────── */

    public function test_an_in_scope_claim_is_readable(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->mate);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/reimbursements/{$claim->id}")
            ->assertOk()
            ->assertJsonPath('data.claim.id', $claim->id);
    }

    public function test_an_in_scope_claim_can_still_be_held_and_noted(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->mate);

        Sanctum::actingAs($user);

        // Proves the 404s above come from the boundary and not from a status
        // check or a broken route.
        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", ['reason' => 'Which project?'])->assertOk();
        $this->postJson("/api/hr/reimbursements/{$claim->id}/note", ['body' => 'Chased.'])->assertOk();
    }

    public function test_the_decision_path_is_unchanged_for_an_in_scope_claim(): void
    {
        $user = $this->cast();
        $claim = $this->claim($this->mate);

        Sanctum::actingAs($user);

        // The engine still runs and still decides. This change narrowed the
        // reads; approving was already scoped by the engine's second gate.
        $this->postJson("/api/hr/reimbursements/{$claim->id}/approve", ['amount' => 1200])->assertOk();
        $this->assertSame(ReimbursementStatus::APPROVED, $claim->fresh()->status);
    }

    /* ── 5. tenant isolation ──────────────────────────────────────────── */

    public function test_another_tenants_claim_is_still_not_found(): void
    {
        $user = $this->cast();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'reimb-other', 'status' => 'active']);
        $theirEmployee = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'X',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active',
        ]);
        $claim = HrReimbursement::create([
            'tenant_id' => $other->id, 'employee_id' => $theirEmployee->id,
            'title' => 'Theirs', 'category' => 'Travel',
            'expense_date' => '2026-09-01', 'amount_claimed' => 500,
            'status' => ReimbursementStatus::PENDING,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/reimbursements/{$claim->id}")->assertStatus(404);
        $this->getJson("/api/hr/reimbursements/{$claim->id}/attachments/1")->assertStatus(404);
    }

    /* ── 6. self-service is untouched ─────────────────────────────────── */

    public function test_an_employee_still_reaches_their_own_claim(): void
    {
        // The `me` controller pins employee_id to the caller's own record and
        // is not scoped by the resolver. An employee with no staff role at all
        // must still see what they submitted.
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp', 'email' => 'emp@reimb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $employee = $this->employee('S-1', $user, 'Sales');
        $claim = $this->claim($employee);

        Sanctum::actingAs($user);

        $this->getJson('/api/hr/me/reimbursements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim->id);
    }

    public function test_a_plain_employee_still_cannot_reach_the_approver_queue(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp2', 'email' => 'emp2@reimb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->employee('S-2', $user, 'Sales');

        Sanctum::actingAs($user);

        // Still the PERMISSION gate, refusing before scope is consulted.
        // Adding a scope must not have softened it into a 404.
        $this->getJson('/api/hr/reimbursements')->assertStatus(403);
    }
}
