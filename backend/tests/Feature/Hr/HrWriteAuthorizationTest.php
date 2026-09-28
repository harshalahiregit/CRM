<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrLoanType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nine HR writes that asked who you were and never asked what you were allowed
 * to do.
 *
 * Most of HR sits in one `auth:sanctum` group and relies on a canManageHrQueue()
 * call inside each method. These nine did not make it. Every one was reproduced
 * against the running API as a staff member holding the "Employee" role: another
 * person's bank account, IFSC, PAN and Aadhaar rewritten; a job posting's
 * external reference rewritten; a candidate's interview round scored; and — the
 * one that was an escalation rather than an oversight — a ₹50,000 loan booked
 * against another employee and driven to Approved in two calls, because a loan
 * type with requires_approval = false is auto-approved by submit() and the gated
 * approve() endpoint is never touched.
 *
 * Tenant scoping was never the problem and is not what these test. Every one of
 * the nine already derived the tenant from the actor or asserted it. What was
 * missing was authority: any insider could act on anybody.
 *
 * Two assertions per case, because a 403 on its own does not prove much: the
 * refusal, and that nothing was written. A gate placed after the work would
 * satisfy the first and fail the second.
 */
class HrWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrEmployee $someoneElse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Writes', 'slug' => 'hr-write-authz', 'status' => 'active',
        ]);

        $this->someoneElse = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'WZ-1', 'name' => 'Meera',
            'department' => 'Finance', 'designation' => 'Manager', 'joining_date' => '2023-01-01',
            'status' => 'Active', 'work_state' => 'Maharashtra',
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

    /** The account the whole file is about: real staff, no HR authority. */
    private function actAsPlainEmployee(): User
    {
        $u = $this->user('staff', 'plain@writes.test');
        $this->assertFalse($u->canManageHrQueue(), 'Fixture check: this account must hold no HR authority.');
        Sanctum::actingAs($u);

        return $u;
    }

    private function loanFor(HrEmployee $employee, bool $requiresApproval = true): HrEmployeeLoan
    {
        $type = HrLoanType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Salary Advance', 'code' => 'SA'.($requiresApproval ? 'Y' : 'N'),
            'is_advance' => true, 'requires_approval' => $requiresApproval, 'is_active' => true,
        ]);

        return HrEmployeeLoan::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'loan_type_id' => $type->id, 'principal' => 50000, 'tenure_months' => 1,
            'status' => HrEmployeeLoan::DRAFT,
        ]);
    }

    private function declaration(): HrInvestmentDeclaration
    {
        return HrInvestmentDeclaration::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->someoneElse->id,
            'financial_year' => '2026-27', 'status' => HrInvestmentDeclaration::DRAFT,
        ]);
    }

    private function jobPosting(): HrJobPosting
    {
        return HrJobPosting::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Analyst', 'department' => 'Ops',
            'designation' => 'Analyst', 'location' => 'Pune', 'status' => 'draft', 'openings' => 1,
        ]);
    }

    private function interviewRound(): HrInterviewRound
    {
        $candidate = HrCandidate::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Asha', 'email' => 'asha@writes.test', 'status' => 'applied',
        ]);

        return HrInterviewRound::create([
            'tenant_id' => $this->tenant->id, 'candidate_id' => $candidate->id,
            'round_name' => 'Technical', 'mode' => 'online', 'status' => 'scheduled',
        ]);
    }

    /* ── 1 & 2 — employees ────────────────────────────────────────────── */

    public function test_a_plain_employee_cannot_create_an_employee(): void
    {
        $this->actAsPlainEmployee();
        $before = HrEmployee::count();

        $this->postJson('/api/hr/employees', [
            'name' => 'Sneaked In',
            // Valid ids, so the 403 below is the permission gate refusing and
            // not the request rules rejecting a malformed payload.
            'department_id' => \App\Models\Hr\HrDepartment::create(
                ['tenant_id' => $this->tenant->id, 'name' => 'Ops', 'is_active' => true])->id,
            'designation_id' => \App\Models\Hr\HrDesignation::create(
                ['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'is_active' => true])->id,
            'joining_date' => '2026-01-01', 'status' => 'Active', 'work_state' => 'Maharashtra',
            'skip_probation' => true, 'probation_skip_reason' => 'n/a',
        ])->assertForbidden();

        $this->assertSame($before, HrEmployee::count(), 'No employee may be created by an unauthorised caller.');
        $this->assertDatabaseMissing('hr_employees', ['name' => 'Sneaked In']);
    }

    /**
     * The one that was reproduced against live data.
     *
     * Asserts the VALUES did not land, not merely that the response was 403 —
     * the original defect wrote them and returned 200.
     */
    public function test_a_plain_employee_cannot_rewrite_another_persons_bank_and_identity_details(): void
    {
        $this->actAsPlainEmployee();

        $this->putJson("/api/hr/employees/{$this->someoneElse->id}/detail", [
            'bank_account_number' => '000111222333',
            'ifsc_code'           => 'HDFC0000001',
            'pan_number'          => 'ABCDE1234F',
            'aadhaar_number'      => '999988887777',
        ])->assertForbidden();

        $this->assertDatabaseMissing('hr_employee_details', ['bank_account_number' => '000111222333']);
        $this->assertDatabaseMissing('hr_employee_details', ['pan_number' => 'ABCDE1234F']);
        $this->assertDatabaseMissing('hr_employee_details', ['aadhaar_number' => '999988887777']);
    }

    /**
     * Reading still works — for the two people entitled to read it.
     *
     * This used to assert that a plain employee could read ANOTHER person's
     * detail, with the note "reading the form is not what was wrong". That was
     * true of the change this file was written for: nine WRITES, and the read
     * was left alone as out of scope rather than ruled on.
     *
     * The read was its own hole. Proved against the running API with a token for
     * an account holding no permission role: it returned a colleague's bank
     * account number, IFSC, PAN, Aadhaar and UAN in full, byte-identical to the
     * administrator's response. The same fields this file exists to stop being
     * REWRITTEN were freely readable.
     *
     * The expectation is corrected rather than deleted, and the original intent
     * is kept: reading the form must keep working. It keeps working for the
     * person whose record it is, and for HR.
     */
    public function test_reading_another_persons_detail_is_refused_but_your_own_is_not(): void
    {
        $actor = $this->actAsPlainEmployee();

        $this->getJson("/api/hr/employees/{$this->someoneElse->id}/detail")->assertForbidden();

        $own = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $actor->id,
            'employee_code' => 'OWN-001', 'name' => 'Their Own Record',
            'department' => 'Operations', 'designation' => 'Executive',
            'joining_date' => '2025-01-01', 'status' => 'Active',
        ]);

        $this->getJson("/api/hr/employees/{$own->id}/detail")->assertOk();
    }

    /* ── 3, 4, 5 — loans ──────────────────────────────────────────────── */

    public function test_a_plain_employee_cannot_create_a_loan_for_anybody(): void
    {
        $this->actAsPlainEmployee();
        $type = HrLoanType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Advance', 'code' => 'ADV',
            'is_advance' => true, 'requires_approval' => true, 'is_active' => true,
        ]);

        $this->postJson('/api/hr/loans', [
            'employee_id' => $this->someoneElse->id, 'loan_type_id' => $type->id,
            'principal' => 50000, 'tenure_months' => 1,
        ])->assertForbidden();

        $this->assertSame(0, HrEmployeeLoan::count(), 'No loan may be created by an unauthorised caller.');
    }

    public function test_a_plain_employee_cannot_submit_a_loan(): void
    {
        $loan = $this->loanFor($this->someoneElse);
        $this->actAsPlainEmployee();

        $this->postJson("/api/hr/loans/{$loan->id}/submit")->assertForbidden();

        $this->assertSame(HrEmployeeLoan::DRAFT, $loan->fresh()->status, 'The loan must not have moved.');
    }

    public function test_a_plain_employee_cannot_cancel_a_loan(): void
    {
        $loan = $this->loanFor($this->someoneElse);
        $this->actAsPlainEmployee();

        $this->postJson("/api/hr/loans/{$loan->id}/cancel")->assertForbidden();

        $this->assertSame(HrEmployeeLoan::DRAFT, $loan->fresh()->status);
    }

    /**
     * The escalation, pinned as the two calls that produced it.
     *
     * With requires_approval = false, submit() sets Approved directly. Gating
     * approve() alone never closed this, because this route never went near it.
     */
    public function test_the_auto_approval_route_cannot_be_reached_by_a_plain_employee(): void
    {
        $loan = $this->loanFor($this->someoneElse, requiresApproval: false);
        $this->actAsPlainEmployee();

        $this->postJson("/api/hr/loans/{$loan->id}/submit")->assertForbidden();

        $fresh = $loan->fresh();
        $this->assertSame(HrEmployeeLoan::DRAFT, $fresh->status,
            'An unauthorised caller must not be able to self-approve through submit().');
        $this->assertNull($fresh->approved_at, 'Nothing may have been approved.');
    }

    /* ── 6 & 7 — investment declarations ──────────────────────────────── */

    public function test_a_plain_employee_cannot_edit_a_tax_declaration(): void
    {
        $declaration = $this->declaration();
        $this->actAsPlainEmployee();

        $this->putJson("/api/hr/payroll/declarations/{$declaration->id}", [
            'previous_employer_income' => 999999,
            'remarks'                  => 'tampered',
        ])->assertForbidden();

        $fresh = $declaration->fresh();
        $this->assertNull($fresh->previous_employer_income, 'Declared income feeds TDS and must be untouched.');
        $this->assertNotSame('tampered', $fresh->remarks);
    }

    public function test_a_plain_employee_cannot_submit_a_tax_declaration(): void
    {
        $declaration = $this->declaration();
        $this->actAsPlainEmployee();

        $this->postJson("/api/hr/payroll/declarations/{$declaration->id}/submit")->assertForbidden();

        $this->assertSame(HrInvestmentDeclaration::DRAFT, $declaration->fresh()->status);
    }

    /* ── 8 — interview evaluation ─────────────────────────────────────── */

    public function test_a_plain_employee_cannot_score_an_interview_round(): void
    {
        $round = $this->interviewRound();
        $this->actAsPlainEmployee();

        $this->postJson("/api/hr/interviews/{$round->id}/questions/evaluate", [
            'answers' => [['id' => 1, 'score' => 10]],
        ])->assertForbidden();
    }

    /* ── 9 — external job id ──────────────────────────────────────────── */

    public function test_a_plain_employee_cannot_rewrite_a_job_postings_external_id(): void
    {
        $job = $this->jobPosting();
        $this->actAsPlainEmployee();

        $this->patchJson("/api/hr/jobs/{$job->id}/external-id", [
            'platform' => 'naukri', 'external_id' => 'HIJACKED',
        ])->assertForbidden();

        $this->assertNotContains('HIJACKED', (array) ($job->fresh()->external_job_ids ?? []));
    }

    /* ── portal identities ────────────────────────────────────────────── */

    /**
     * Portal accounts are rows in `users` too, so the same nine endpoints have to
     * refuse them. They already did after the account-type work, and this keeps
     * that true for these routes specifically.
     *
     * @dataProvider portalRoles
     */
    public function test_portal_identities_cannot_perform_hr_writes(string $role): void
    {
        Sanctum::actingAs($this->user($role, "portal-{$role}@writes.test", 'hr_executive'));

        // Refused, by whichever layer gets there first.
        //
        // This asserted 403 exactly. Portal identities used to reach the
        // authorization check because ScopeResolver handed them GLOBAL scope —
        // they had no staff role, and "no role means global" was written for
        // colleagues who predate the roles table. They now resolve to OWN, so the
        // employee is out of their scope and assertTenant answers 404 first,
        // which that controller documents as deliberate: "out of scope should
        // look like not there".
        //
        // 404 refuses at least as hard as 403 and leaks less, so the assertion
        // accepts either. What must not move is the line below it: nothing is
        // written. That is what this test is actually for.
        foreach ([
            $this->putJson("/api/hr/employees/{$this->someoneElse->id}/detail", ['bank_account_number' => '123']),
            $this->postJson('/api/hr/loans', ['employee_id' => $this->someoneElse->id]),
        ] as $response) {
            $this->assertContains($response->status(), [403, 404],
                "A {$role} account must be refused; got {$response->status()}.");
        }

        $this->assertDatabaseMissing('hr_employee_details', ['bank_account_number' => '123']);
    }

    public static function portalRoles(): array
    {
        return [
            'client'             => ['client'],
            'vendor'             => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
            'company'            => ['company'],
            'doctor'             => ['doctor'],
        ];
    }

    /* ── the half that must not change ────────────────────────────────── */

    /**
     * Every one of the nine, for the people who are supposed to use them.
     *
     * Asserts only that the gate did not fire — the endpoints have their own
     * validation and business rules, and those are unchanged and not this test's
     * business. Anything but 403 means authority was granted.
     *
     * @dataProvider hrActors
     */
    public function test_hr_and_admin_are_not_refused_by_any_of_the_nine(string $role, ?string $internal): void
    {
        $loan        = $this->loanFor($this->someoneElse);
        $declaration = $this->declaration();
        $job         = $this->jobPosting();
        $round       = $this->interviewRound();

        Sanctum::actingAs($this->user($role, "ok-{$role}-{$internal}@writes.test", $internal));

        $calls = [
            'store'          => fn () => $this->postJson('/api/hr/employees', []),
            'updateDetail'   => fn () => $this->putJson("/api/hr/employees/{$this->someoneElse->id}/detail", []),
            'loan save'      => fn () => $this->postJson('/api/hr/loans', []),
            'loan submit'    => fn () => $this->postJson("/api/hr/loans/{$loan->id}/submit"),
            'loan cancel'    => fn () => $this->postJson("/api/hr/loans/{$loan->id}/cancel"),
            'decl save'      => fn () => $this->putJson("/api/hr/payroll/declarations/{$declaration->id}", []),
            'decl submit'    => fn () => $this->postJson("/api/hr/payroll/declarations/{$declaration->id}/submit"),
            'evaluate'       => fn () => $this->postJson("/api/hr/interviews/{$round->id}/questions/evaluate", ['answers' => []]),
            'external id'    => fn () => $this->patchJson("/api/hr/jobs/{$job->id}/external-id", ['platform' => 'naukri', 'external_id' => 'X1']),
        ];

        foreach ($calls as $name => $call) {
            $this->assertNotSame(403, $call()->status(),
                "{$role}/{$internal} must not be refused by {$name} — this pass takes nothing from HR.");
        }
    }

    public static function hrActors(): array
    {
        return [
            'admin'        => ['admin', null],
            'hr executive' => ['staff', 'hr_executive'],
            'hr recruiter' => ['staff', 'hr_recruiter'],
        ];
    }

    /** An authorised write still works end to end — the gate is a gate, not a wall. */
    public function test_an_authorised_write_still_persists(): void
    {
        Sanctum::actingAs($this->user('staff', 'hr@writes.test', 'hr_executive'));

        $this->putJson("/api/hr/employees/{$this->someoneElse->id}/detail", [
            'bank_account_number' => '555666777888',
            'pan_number'          => 'ZYXWV9876K',
        ])->assertOk();

        $this->assertDatabaseHas('hr_employee_details', [
            'employee_id'         => $this->someoneElse->id,
            'bank_account_number' => '555666777888',
        ]);
    }
}
