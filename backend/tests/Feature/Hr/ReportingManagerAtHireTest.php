<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hiring somebody now records WHO they report to, not just what that person is called.
 *
 * hr_employees.reporting_manager_id has existed and been fillable throughout, and
 * had exactly one writer in the codebase: EmployeeMovementService, the transfer
 * flow. Nothing set it at hire. The add-employee form offered a "Reporting
 * Manager" dropdown that wrote reporting_manager_name — a free string — so a
 * person's manager only became a real edge in the graph if they were later
 * moved. Hired and left alone, they had none.
 *
 * That edge is what OrgChartService groups the tenant by, what
 * AdvanceTierService walks to find the manager rung of the approval ladder, and
 * what HrmAdminController reads to decide whose requests a line manager sees in
 * the phone app. All three were reading a column almost nobody wrote.
 *
 * Both fields are kept and neither is derived from the other: the id is the
 * identity, the name is the only thing that can hold a manager who is not an
 * employee record at all (the seeded data has a "CEO" who is nobody's row).
 */
class ReportingManagerAtHireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private HrEmployee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Hire', 'slug' => 'reporting-manager-hire', 'status' => 'active',
        ]);

        $this->hr = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'HR', 'email' => 'hr@hire.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'internal_role' => 'hr_executive', 'status' => 'active',
        ]);

        $this->manager = $this->employee('MGR-1', 'Meera Manager');

        Sanctum::actingAs($this->hr);
    }

    private function employee(string $code, string $name, ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => $name,
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'reporting_manager_id' => $managerId,
        ]);
    }

    /** The org masters the hire form now submits by id. */
    private function orgMasters(): array
    {
        return [
            'department_id' => \App\Models\Hr\HrDepartment::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'name' => 'Ops'], ['is_active' => true])->id,
            'designation_id' => \App\Models\Hr\HrDesignation::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'name' => 'Analyst'], ['is_active' => true])->id,
        ];
    }

    private function hirePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Joiner',
        ] + $this->orgMasters() + [
            'joining_date' => '2026-01-01', 'status' => 'Active', 'work_state' => 'Maharashtra',
            'skip_probation' => true, 'probation_skip_reason' => 'not applicable',
        ], $overrides);
    }

    /* ── the hire ─────────────────────────────────────────────────────── */

    public function test_a_hire_records_the_selected_manager_as_an_identity(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_id'   => $this->manager->id,
            'reporting_manager_name' => $this->manager->name,
        ]))->assertCreated();

        $hired = HrEmployee::where('name', 'New Joiner')->firstOrFail();

        $this->assertSame((int) $this->manager->id, (int) $hired->reporting_manager_id,
            'The hierarchy edge every other feature reads must be set at hire.');
        $this->assertSame('Meera Manager', $hired->reporting_manager_name,
            'And the display name is still stored beside it.');
    }

    /**
     * The edge has to be usable by the features that read it, or setting it is
     * bookkeeping. This is the org-chart relation resolving for a new hire.
     */
    public function test_the_new_hire_appears_beneath_their_manager(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_id' => $this->manager->id,
        ]))->assertCreated();

        $hired = HrEmployee::where('name', 'New Joiner')->firstOrFail();

        $this->assertTrue($hired->reportingManager()->exists());
        $this->assertSame('Meera Manager', $hired->reportingManager->name);
        $this->assertTrue(
            HrEmployee::where('reporting_manager_id', $this->manager->id)->where('id', $hired->id)->exists(),
            'The manager must be able to find their report — this is the query the approval queue runs.'
        );
    }

    /* ── a hire without a manager is still legal ──────────────────────── */

    /**
     * Deliberately NOT made mandatory.
     *
     * The product has always allowed a hire with no manager, and the first
     * employee in a tenant genuinely has none. Requiring one would break both,
     * and inventing one would be worse than leaving it null.
     */
    public function test_a_hire_without_a_manager_still_succeeds(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload())->assertCreated();

        $hired = HrEmployee::where('name', 'New Joiner')->firstOrFail();
        $this->assertNull($hired->reporting_manager_id, 'Absent stays absent — nothing is guessed.');
    }

    /** A manager who is not an employee record can still be named. */
    public function test_a_manager_who_is_not_an_employee_can_still_be_named(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_name' => 'CEO',
        ]))->assertCreated();

        $hired = HrEmployee::where('name', 'New Joiner')->firstOrFail();

        $this->assertSame('CEO', $hired->reporting_manager_name);
        $this->assertNull($hired->reporting_manager_id);
    }

    /* ── validation ───────────────────────────────────────────────────── */

    public function test_a_manager_that_does_not_exist_is_rejected(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors('reporting_manager_id');

        $this->assertDatabaseMissing('hr_employees', ['name' => 'New Joiner']);
    }

    public function test_a_manager_from_another_tenant_is_rejected(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-hire', 'status' => 'active']);
        $foreign = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'Foreign',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_id' => $foreign->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('reporting_manager_id');
    }

    public function test_an_employee_cannot_be_their_own_manager(): void
    {
        $person = $this->employee('SELF-1', 'Solo');

        $this->putJson("/api/hr/employees/{$person->id}", [
            'reporting_manager_id' => $person->id,
        ])->assertStatus(422);

        $this->assertNull($person->fresh()->reporting_manager_id);
    }

    /**
     * A loop would not produce a wrong org chart, it would produce one that never
     * finishes building — OrgChartService walks down from the roots.
     */
    public function test_a_circular_reporting_line_is_rejected(): void
    {
        $top    = $this->employee('C-1', 'Top');
        $middle = $this->employee('C-2', 'Middle', $top->id);
        $bottom = $this->employee('C-3', 'Bottom', $middle->id);

        // Top reporting to Bottom would close the loop Top → Bottom → Middle → Top.
        $this->putJson("/api/hr/employees/{$top->id}", [
            'reporting_manager_id' => $bottom->id,
        ])->assertStatus(422);

        $this->assertNull($top->fresh()->reporting_manager_id);
        $this->assertSame((int) $middle->id, (int) $bottom->fresh()->reporting_manager_id,
            'The rest of the chain is untouched by a rejected edit.');
    }

    public function test_a_legitimate_manager_change_is_allowed(): void
    {
        $person  = $this->employee('MOV-1', 'Mover', $this->manager->id);
        $another = $this->employee('MGR-2', 'Second Manager');

        $this->putJson("/api/hr/employees/{$person->id}", [
            'reporting_manager_id' => $another->id,
        ])->assertOk();

        $this->assertSame((int) $another->id, (int) $person->fresh()->reporting_manager_id);
    }

    public function test_a_manager_can_be_cleared(): void
    {
        $person = $this->employee('CLR-1', 'Clearable', $this->manager->id);

        $this->putJson("/api/hr/employees/{$person->id}", [
            'reporting_manager_id' => null,
        ])->assertOk();

        $this->assertNull($person->fresh()->reporting_manager_id);
    }

    /* ── nothing existing is disturbed ────────────────────────────────── */

    /**
     * No backfill. An employee hired before this change keeps exactly the
     * hierarchy they had, including none at all.
     */
    public function test_existing_employees_are_not_backfilled(): void
    {
        $legacyNone = $this->employee('OLD-1', 'Legacy No Manager');
        $legacySet  = $this->employee('OLD-2', 'Legacy With Manager', $this->manager->id);
        $legacyName = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'OLD-3', 'name' => 'Legacy Name Only',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'reporting_manager_name' => 'Meera Manager',
        ]);

        // Hire somebody new — the act that could plausibly trigger a backfill.
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'reporting_manager_id' => $this->manager->id,
        ]))->assertCreated();

        $this->assertNull($legacyNone->fresh()->reporting_manager_id);
        $this->assertSame((int) $this->manager->id, (int) $legacySet->fresh()->reporting_manager_id);
        $this->assertNull($legacyName->fresh()->reporting_manager_id,
            'A name that happens to match an employee must NOT be resolved into an id behind anyone\'s back.');
        $this->assertSame('Meera Manager', $legacyName->fresh()->reporting_manager_name);
    }

    /** Editing something unrelated must not disturb the manager either way. */
    public function test_an_unrelated_edit_leaves_the_manager_alone(): void
    {
        $person = $this->employee('UNR-1', 'Unrelated', $this->manager->id);

        $senior = \App\Models\Hr\HrDesignation::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Senior Analyst', 'is_active' => true,
        ]);

        $this->putJson("/api/hr/employees/{$person->id}", ['designation_id' => $senior->id])->assertOk();

        $fresh = $person->fresh();
        $this->assertSame('Senior Analyst', $fresh->designation);
        $this->assertSame((int) $this->manager->id, (int) $fresh->reporting_manager_id);
    }

    /* ── the other creation paths are unchanged ───────────────────────── */

    /**
     * provisionEmployeeFor() creates a record for an existing LOGIN. It is not a
     * hire — nobody chose a manager — so it must keep producing a record with
     * none, rather than being dragged into this change.
     */
    public function test_provisioning_a_record_for_a_login_is_unchanged(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Linked Person', 'email' => 'linked@hire.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $employee = app(EmployeeIdentityService::class)->provisionEmployeeFor($user);

        $this->assertSame('Linked Person', $employee->name);
        $this->assertNull($employee->reporting_manager_id,
            'Provisioning is not a hire and must not invent a reporting line.');
    }

    /**
     * The second hire path now carries an identity too.
     *
     * This assertion used to be the opposite — it pinned the gap, and said that
     * if hr_onboarding ever gained an id column then OnboardingService must be
     * updated to pass it through. Both have now happened, so it pins the pair
     * instead: a column with no propagation would be the worse of the two
     * states, because it would look finished.
     *
     * The behaviour itself is covered end to end by OnboardingReportingManagerTest.
     */
    public function test_the_onboarding_hire_path_carries_the_manager_identity(): void
    {
        $columns = collect(\DB::select("PRAGMA table_info('hr_onboarding')"))->pluck('name');

        $this->assertTrue($columns->contains('reporting_manager_name'),
            'The name stays — it is the only thing that can hold a non-employee manager.');
        $this->assertTrue($columns->contains('reporting_manager_id'));

        $service = file_get_contents(app_path('Services/Hr/OnboardingService.php'));
        $this->assertSame(
            2,
            substr_count($service, "'reporting_manager_id'   => \$onboarding->reporting_manager_id"),
            'BOTH employee-create paths in OnboardingService must pass the id through, '
            .'or a hire completed by the other route silently loses its reporting line.'
        );
    }
}
