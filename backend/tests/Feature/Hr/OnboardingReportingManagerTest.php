<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Models\Hr\HrOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The second hire path, brought level with the first.
 *
 * Candidate → onboarding → employee is as much a hire as the add-employee form,
 * and it already had a step called "Reporting Manager Assigned". That step was a
 * checkbox: `step_manager_assigned` is a boolean and `reporting_manager_name` is
 * a free string that no endpoint ever set, so the employee it produced carried a
 * name at best and never an id. The hierarchy edge that OrgChartService,
 * AdvanceTierService and the phone app's approval queue all walk was not set by
 * this path at all.
 *
 * hr_employee_onboardings.manager_id was considered first and cannot serve: that
 * record is created BY createFromEmployee(), needs an HrEmployee to exist, and
 * seeds its own manager_name FROM the employee. It is downstream of the hire,
 * and its manager_id is dormant with no writer anywhere. Asking it to decide
 * something needed during the hire would invert the flow — so hr_onboarding, the
 * record that IS the hire, gained the column instead.
 */
class OnboardingReportingManagerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private HrEmployee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Onb', 'slug' => 'onboarding-manager', 'status' => 'active',
        ]);

        $this->hr = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'HR', 'email' => 'hr@onb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'internal_role' => 'hr_executive', 'status' => 'active',
        ]);

        $this->manager = $this->employee('OMGR-1', 'Meera Manager');

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

    private function onboarding(string $name = 'Asha Candidate'): HrOnboarding
    {
        $candidate = HrCandidate::create([
            'tenant_id' => $this->tenant->id, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@onb.test', 'status' => 'applied',
        ]);

        return HrOnboarding::create([
            'tenant_id' => $this->tenant->id, 'candidate_id' => $candidate->id,
            'candidate_name' => $name, 'position' => 'Analyst', 'department' => 'Ops',
            'joining_date' => '2026-02-01', 'status' => 'Pending',
        ]);
    }

    private function assignManager(HrOnboarding $o, $managerId)
    {
        return $this->patchJson("/api/hr/onboarding/{$o->id}/step", [
            'step' => 'manager_assigned', 'reporting_manager_id' => $managerId,
        ]);
    }

    /** Drive every step so the service creates the employee. */
    private function completeAllSteps(HrOnboarding $o): void
    {
        foreach (['doc_verification', 'joining_confirmed', 'emp_id_generated', 'dept_assigned', 'record_created'] as $step) {
            $this->patchJson("/api/hr/onboarding/{$o->id}/step", ['step' => $step])->assertOk();
        }
    }

    /* ── the manager step now records who ─────────────────────────────── */

    public function test_the_manager_step_records_an_identity_not_just_a_tick(): void
    {
        $o = $this->onboarding();

        $this->assignManager($o, $this->manager->id)->assertOk();

        $fresh = $o->fresh();
        $this->assertSame((int) $this->manager->id, (int) $fresh->reporting_manager_id);
        $this->assertSame('Meera Manager', $fresh->reporting_manager_name,
            'The name is kept in step so every existing read of it still works.');
        $this->assertTrue((bool) $fresh->step_manager_assigned,
            'Choosing a manager is what completes the step.');
    }

    public function test_clearing_the_manager_reopens_the_step(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();

        $this->assignManager($o, null)->assertOk();

        $fresh = $o->fresh();
        $this->assertNull($fresh->reporting_manager_id);
        $this->assertFalse((bool) $fresh->step_manager_assigned);
    }

    /** The other five steps keep toggling exactly as they always did. */
    public function test_the_other_steps_still_plain_toggle(): void
    {
        $o = $this->onboarding();

        $this->patchJson("/api/hr/onboarding/{$o->id}/step", ['step' => 'dept_assigned'])->assertOk();
        $this->assertTrue((bool) $o->fresh()->step_dept_assigned);

        $this->patchJson("/api/hr/onboarding/{$o->id}/step", ['step' => 'dept_assigned'])->assertOk();
        $this->assertFalse((bool) $o->fresh()->step_dept_assigned);
    }

    /* ── propagation into the employee ────────────────────────────────── */

    public function test_the_hire_carries_the_manager_through_to_the_employee(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $this->assertSame((int) $this->manager->id, (int) $hired->reporting_manager_id,
            'The whole point: the onboarding hire now sets the hierarchy edge.');
        $this->assertSame('Meera Manager', $hired->reporting_manager_name);
    }

    public function test_the_hired_employees_manager_relation_resolves(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $this->assertTrue($hired->reportingManager()->exists());
        $this->assertSame('Meera Manager', $hired->reportingManager->name);
    }

    /**
     * The query the phone app's approval queue and the advance ladder actually
     * run. Setting the column is only worth anything if this finds the person.
     */
    public function test_the_manager_can_find_their_new_report(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $reports = HrEmployee::where('tenant_id', $this->tenant->id)
            ->where('reporting_manager_id', $this->manager->id)
            ->pluck('id');

        $this->assertTrue($reports->contains($hired->id));
    }

    /* ── validation ───────────────────────────────────────────────────── */

    public function test_a_manager_that_does_not_exist_is_rejected(): void
    {
        $o = $this->onboarding();

        $this->assignManager($o, 999999)
            ->assertStatus(422)->assertJsonValidationErrors('reporting_manager_id');

        $this->assertNull($o->fresh()->reporting_manager_id);
    }

    public function test_a_manager_from_another_tenant_is_rejected(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-onb', 'status' => 'active']);
        $foreign = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'F-1', 'name' => 'Foreign',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        $o = $this->onboarding();

        $this->assignManager($o, $foreign->id)
            ->assertStatus(422)->assertJsonValidationErrors('reporting_manager_id');

        $this->assertNull($o->fresh()->reporting_manager_id);
    }

    /**
     * Self-reference and cycles cannot arise on this path, and that is a property
     * worth stating rather than leaving to chance: the employee does not exist
     * while the manager is being chosen, and arrives as a leaf with no reports.
     * EmployeeService::assertManagerIsUsable() guards the edit path where they
     * genuinely can arise.
     */
    public function test_the_new_hire_is_a_leaf_so_no_cycle_can_be_created(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $this->assertNotSame((int) $hired->id, (int) $hired->reporting_manager_id);
        $this->assertSame(0, HrEmployee::where('reporting_manager_id', $hired->id)->count());

        // And the edit path still refuses to close a loop through them. The hire
        // already reports to the manager, so pointing the manager back at the
        // hire is the loop — it is rejected on that single edit, not on a later
        // one.
        $this->putJson("/api/hr/employees/{$this->manager->id}", [
            'reporting_manager_id' => $hired->id,
        ])->assertStatus(422);

        $this->assertNull($this->manager->fresh()->reporting_manager_id);
        $this->assertSame((int) $this->manager->id, (int) $hired->fresh()->reporting_manager_id,
            'The rejected edit leaves the hire\'s own reporting line untouched.');
    }

    /* ── a hire with no manager is still legal ────────────────────────── */

    public function test_onboarding_without_a_manager_still_completes(): void
    {
        $o = $this->onboarding('Nomanager Person');

        $this->patchJson("/api/hr/onboarding/{$o->id}/step", ['step' => 'manager_assigned'])->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $this->assertNull($hired->reporting_manager_id, 'Absent stays absent — nothing is invented.');
    }

    /* ── nothing existing is disturbed ────────────────────────────────── */

    public function test_existing_employees_and_onboardings_are_not_backfilled(): void
    {
        $legacyEmployee = $this->employee('LEG-1', 'Legacy Person');
        $legacyOnboarding = $this->onboarding('Legacy Candidate');
        $legacyOnboarding->update(['reporting_manager_name' => 'Meera Manager']);

        // Run a brand-new hire — the act that could plausibly trigger a backfill.
        $o = $this->onboarding('Brand New');
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $this->assertNull($legacyEmployee->fresh()->reporting_manager_id);
        $this->assertNull($legacyOnboarding->fresh()->reporting_manager_id,
            'A name that happens to match an employee is NOT resolved into an id.');
        $this->assertSame('Meera Manager', $legacyOnboarding->fresh()->reporting_manager_name);
    }

    /**
     * hr_employee_onboardings is downstream and must stay that way: it reads the
     * manager off the employee, and its dormant manager_id is not made
     * authoritative by any of this.
     */
    public function test_the_downstream_onboarding_record_is_unchanged(): void
    {
        $o = $this->onboarding();
        $this->assignManager($o, $this->manager->id)->assertOk();
        $this->completeAllSteps($o);

        $hired = HrEmployee::where('candidate_id', $o->candidate_id)->firstOrFail();

        $record = app(\App\Services\Hr\EmployeeOnboardingService::class)
            ->createFromEmployee($hired->id, $this->hr);

        $this->assertInstanceOf(HrEmployeeOnboarding::class, $record);
        $this->assertSame('Meera Manager', $record->manager_name,
            'It still derives the name from the employee, exactly as before.');
        $this->assertNull($record->manager_id,
            'Its manager_id stays dormant — this pass did not start writing it.');
    }

    /* ── the non-hire paths are untouched ─────────────────────────────── */

    public function test_login_provisioning_is_unchanged(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Linked', 'email' => 'linked@onb.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $employee = app(EmployeeIdentityService::class)->provisionEmployeeFor($user);

        $this->assertNull($employee->reporting_manager_id,
            'Provisioning a record for a login is not a hire and invents no reporting line.');
    }
}
