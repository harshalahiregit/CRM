<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An employee's department and designation are master records, not typed text.
 *
 * hr_employees has carried department_id and designation_id since the
 * organization tables shipped, and the employee form wrote neither. It stored
 * whatever string arrived, so "Operations", "operations" and "Ops" were three
 * departments, renaming one changed nothing about the people in it, and the
 * org chart — which reads the FK — saw almost nobody.
 *
 * TWO DIFFERENT STRICTNESSES, on purpose, and the tests hold both:
 *
 *   THE HTTP PATH IS STRICT. A person choosing from a dropdown must choose a
 *   real master belonging to their own workspace, so the request validates
 *   department_id and designation_id and the name is no longer accepted at
 *   all — it is copied from the master. One editable source instead of two
 *   that drifted.
 *
 *   THE SERVICE PATH IS TOLERANT, and already was. Support\Hr\OrgLink runs
 *   on a saving() hook for every employee write and resolves the name to a
 *   master case- and space-insensitively, CREATING one when nothing matches.
 *   Onboarding conversion carries the job posting's free text, the SangoeTrack
 *   importer carries a label from their system, and EmployeeIdentityService
 *   falls back to "Unassigned" — refusing any of those would break hiring to
 *   enforce tidiness. That behaviour is unchanged here and is pinned below.
 *
 * Which is precisely why the form had to stop accepting typed names: on that
 * path a typo would MINT a department. The request rules now refuse it before
 * OrgLink is ever reached, so the tolerance stays where it is useful and is
 * gone where it was doing harm.
 *
 * DEPARTMENT IS NOT DESIGNATION. Separate columns, separate masters, and
 * separate tests that changing one leaves the other alone.
 */
class EmployeeOrgMasterIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'orgm-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'orgm-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function hr(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'HR',
            'email' => uniqid().'@orgm.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function outsider(): User
    {
        return User::create([
            'tenant_id' => $this->a->id, 'name' => 'Nobody',
            'email' => uniqid().'@orgm.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function dept(string $name = 'Engineering', ?Tenant $t = null, bool $active = true): HrDepartment
    {
        return HrDepartment::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).substr(uniqid(), -3), 'is_active' => $active,
        ]);
    }

    private function desig(string $name = 'Engineer', ?Tenant $t = null, bool $active = true): HrDesignation
    {
        return HrDesignation::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).substr(uniqid(), -3), 'is_active' => $active,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Asha Rao',
            'joining_date' => '2026-01-01',
            'status' => 'Active',
            // required_unless on the request; probation is its own module and
            // is not what this suite is about.
            'skip_probation' => true,
            'probation_skip_reason' => 'Not in scope for this test.',
        ], $over);
    }

    /**
     * A row from before OrgLink shipped: names, no links.
     *
     * Written with a query rather than the model on purpose — OrgLink runs on
     * saving() and would populate both ids, so a genuinely unlinked row is not
     * reachable through Eloquent at all. This is the shape real historical
     * data has, and it must keep loading.
     */
    private function preLinkEmployee(array $attrs = [], ?Tenant $t = null): HrEmployee
    {
        $employee = $this->legacyEmployee($attrs, $t);

        \Illuminate\Support\Facades\DB::table('hr_employees')
            ->where('id', $employee->id)
            ->update(['department_id' => null, 'designation_id' => null]);

        return $employee->fresh();
    }

    /** An employee created straight through the model — the legacy shape. */
    private function legacyEmployee(array $attrs = [], ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Legacy',
            'employee_code' => 'L'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ], $attrs));
    }

    /* ── 1 & 2. the happy path writes BOTH halves ─────────────────────── */

    public function test_creating_an_employee_stores_the_master_ids_and_their_names(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Senior Engineer');

        Sanctum::actingAs($this->hr());
        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $dept->id, 'designation_id' => $desig->id,
        ]))->assertStatus(201);

        $employee = HrEmployee::where('tenant_id', $this->a->id)->firstOrFail();

        $this->assertSame($dept->id, (int) $employee->department_id);
        $this->assertSame($desig->id, (int) $employee->designation_id);
        // The name is a copy taken FROM the master, not something the caller sent.
        $this->assertSame('Engineering', $employee->department);
        $this->assertSame('Senior Engineer', $employee->designation);
    }

    public function test_the_master_spelling_wins_over_anything_the_caller_sends(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');

        Sanctum::actingAs($this->hr());
        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $dept->id, 'designation_id' => $desig->id,
            // Ignored: the name is not part of the write contract any more.
            'department' => 'ENGINEERING (typo dept)', 'designation' => 'whatever',
        ]))->assertStatus(201);

        $employee = HrEmployee::where('tenant_id', $this->a->id)->firstOrFail();

        $this->assertSame('Engineering', $employee->department);
        $this->assertSame('Engineer', $employee->designation);
    }

    /* ── 3, 4, 15. bad references are refused ─────────────────────────── */

    public function test_a_nonexistent_department_or_designation_is_refused(): void
    {
        $dept = $this->dept();
        $desig = $this->desig();

        Sanctum::actingAs($this->hr());

        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => 999999, 'designation_id' => $desig->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $dept->id, 'designation_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors('designation_id');

        $this->assertSame(0, HrEmployee::count());
    }

    public function test_free_text_alone_cannot_create_an_employee(): void
    {
        $this->dept('Engineering');
        $this->desig('Engineer');

        Sanctum::actingAs($this->hr());

        // The exact drift this phase exists to stop: a typed department that
        // would previously have been stored verbatim with a null FK.
        $this->postJson('/api/hr/employees', $this->payload([
            'department' => 'Engineering', 'designation' => 'Engineer',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id', 'designation_id']);

        $this->assertSame(0, HrEmployee::count());
    }

    /* ── 5, 6, 17. tenant isolation ───────────────────────────────────── */

    public function test_another_workspaces_department_or_designation_cannot_be_assigned(): void
    {
        $mineDept = $this->dept('Engineering');
        $mineDesig = $this->desig('Engineer');
        $theirDept = $this->dept('Their Dept', $this->b);
        $theirDesig = $this->desig('Their Role', $this->b);

        Sanctum::actingAs($this->hr());

        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $theirDept->id, 'designation_id' => $mineDesig->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $mineDept->id, 'designation_id' => $theirDesig->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('designation_id');

        $this->assertSame(0, HrEmployee::count());
    }

    public function test_an_update_cannot_reach_another_workspaces_masters(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');
        $employee = $this->legacyEmployee([
            'department_id' => $dept->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        $theirDept = $this->dept('Their Dept', $this->b);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['department_id' => $theirDept->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->assertSame($dept->id, (int) $employee->fresh()->department_id);
        $this->assertSame('Engineering', $employee->fresh()->department);
    }

    /**
     * The same, for the other half.
     *
     * Written separately rather than folded into the test above because the
     * two are validated by two separate rules: a mutation dropping the tenant
     * scope from designation_id alone survived a department-only test, which
     * is exactly the half-fix this pair exists to catch.
     */
    public function test_an_update_cannot_reach_another_workspaces_designation(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');
        $employee = $this->legacyEmployee([
            'department_id' => $dept->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        $theirDesig = $this->desig('Their Role', $this->b);

        Sanctum::actingAs($this->hr());
        // The REQUEST RULE is what must refuse this, so the validation error is
        // asserted by name rather than accepting any rejection. The service's
        // own tenant check is a second, independent guard and is proven
        // separately by test_the_service_refuses_an_id_from_another_workspace;
        // accepting either here would let one of the two be removed unnoticed.
        $this->putJson("/api/hr/employees/{$employee->id}", ['designation_id' => $theirDesig->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('designation_id');

        $fresh = $employee->fresh();
        $this->assertSame($desig->id, (int) $fresh->designation_id);
        $this->assertSame('Engineer', $fresh->designation);
    }

    /* ── 8, 9, 10. updates ────────────────────────────────────────────── */

    public function test_an_update_moves_the_employee_to_the_new_department(): void
    {
        $from = $this->dept('Engineering');
        $to = $this->dept('Platform');
        $desig = $this->desig('Engineer');

        $employee = $this->legacyEmployee([
            'department_id' => $from->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['department_id' => $to->id])->assertOk();

        $fresh = $employee->fresh();
        $this->assertSame($to->id, (int) $fresh->department_id);
        $this->assertSame('Platform', $fresh->department);

        // DEPARTMENT IS NOT DESIGNATION — the other half must not move.
        $this->assertSame($desig->id, (int) $fresh->designation_id);
        $this->assertSame('Engineer', $fresh->designation);
    }

    public function test_an_update_changes_the_designation_without_touching_the_department(): void
    {
        $dept = $this->dept('Engineering');
        $from = $this->desig('Engineer');
        $to = $this->desig('Principal Engineer');

        $employee = $this->legacyEmployee([
            'department_id' => $dept->id, 'department' => 'Engineering',
            'designation_id' => $from->id, 'designation' => 'Engineer',
        ]);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['designation_id' => $to->id])->assertOk();

        $fresh = $employee->fresh();
        $this->assertSame($to->id, (int) $fresh->designation_id);
        $this->assertSame('Principal Engineer', $fresh->designation);

        $this->assertSame($dept->id, (int) $fresh->department_id);
        $this->assertSame('Engineering', $fresh->department);
    }

    public function test_an_update_that_mentions_neither_leaves_both_alone(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');
        $employee = $this->legacyEmployee([
            'department_id' => $dept->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['phone' => '9000000000'])->assertOk();

        $fresh = $employee->fresh();
        $this->assertSame($dept->id, (int) $fresh->department_id);
        $this->assertSame($desig->id, (int) $fresh->designation_id);
        $this->assertSame('Engineering', $fresh->department);
        $this->assertSame('Engineer', $fresh->designation);
    }

    /* ── 7. active / inactive ─────────────────────────────────────────── */

    public function test_an_employee_keeps_a_department_that_was_later_retired(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');
        $employee = $this->legacyEmployee([
            'department_id' => $dept->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        $dept->update(['is_active' => false]);

        $fresh = $employee->fresh();
        $this->assertSame($dept->id, (int) $fresh->department_id);
        $this->assertSame('Engineering', $fresh->department);

        // And re-saving the record does not lose it. The master feed is
        // active-only, so the UI keeps the retired value visible and marked;
        // the backend must therefore still accept it, or every edit of this
        // employee would fail.
        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['department_id' => $dept->id])->assertOk();

        $this->assertSame($dept->id, (int) $employee->fresh()->department_id);
    }

    /* ── 11, 12. conversion and onboarding stay tolerant ──────────────── */

    public function test_a_service_created_employee_upgrades_a_matching_name_to_its_master(): void
    {
        $dept = $this->dept('Engineering');
        $desig = $this->desig('Engineer');

        // How the onboarding conversion calls it: names only, from the job
        // posting and the offer.
        $employee = app(EmployeeService::class)->create([
            'name' => 'Converted', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department' => 'engineering', 'designation' => 'ENGINEER',
            'skip_probation' => true,
        ], $this->a->id);

        // Matched case-insensitively and linked to the EXISTING master rather
        // than minting a second one.
        $this->assertSame($dept->id, (int) $employee->department_id);
        $this->assertSame($desig->id, (int) $employee->designation_id);

        // The typed name is preserved as typed. OrgLink sets the link and does
        // not rewrite the text — only the form path, which copies from the
        // master, produces the canonical spelling.
        $this->assertSame('engineering', $employee->department);
        $this->assertSame('ENGINEER', $employee->designation);

        $this->assertSame(1, HrDepartment::where('tenant_id', $this->a->id)->count());
    }

    /**
     * An unmatched name becomes a master, and that is existing behaviour.
     *
     * OrgLink creates the record rather than storing a dangling string,
     * because the alternative is what the product had — a department that
     * exists on people and nowhere an admin can see it. This suite pins that
     * it still happens on the SERVICE path, which is what hiring uses.
     *
     * It is also exactly why the HTTP form no longer accepts typed names: on
     * that path a typo would mint a department, and the request rules now stop
     * it before OrgLink ever sees one.
     */
    public function test_a_service_created_employee_with_an_unknown_name_gets_a_new_master(): void
    {
        $this->dept('Engineering');

        $employee = app(EmployeeService::class)->create([
            'name' => 'Converted', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department' => 'Some Team That Is Not A Master', 'designation' => 'Contractor',
            'skip_probation' => true,
        ], $this->a->id);

        $this->assertNotNull($employee->department_id, 'hiring must not be blocked by an unknown name');
        $this->assertSame('Some Team That Is Not A Master', $employee->department);

        // The new master belongs to THIS workspace and is visible in Org Setup.
        $created = HrDepartment::find($employee->department_id);
        $this->assertSame($this->a->id, (int) $created->tenant_id);
        $this->assertSame('Some Team That Is Not A Master', $created->name);
    }

    public function test_the_service_never_matches_a_name_across_workspaces(): void
    {
        // The master exists, but in the OTHER workspace.
        $theirs = $this->dept('Engineering', $this->b);

        $employee = app(EmployeeService::class)->create([
            'name' => 'Converted', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department' => 'Engineering', 'designation' => 'Engineer',
            'skip_probation' => true,
        ], $this->a->id);

        // Never the other workspace's record. A same-named master is created
        // locally instead, which is the tenant-safe outcome.
        $this->assertNotSame($theirs->id, (int) $employee->department_id);
        $this->assertSame($this->a->id, (int) HrDepartment::find($employee->department_id)->tenant_id);
        $this->assertSame('Engineering', $employee->department);
    }

    public function test_the_service_refuses_an_id_from_another_workspace(): void
    {
        $theirDept = $this->dept('Their Dept', $this->b);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('Department not found');

        app(EmployeeService::class)->create([
            'name' => 'X', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department_id' => $theirDept->id, 'skip_probation' => true,
        ], $this->a->id);
    }

    /* ── 13. movement/transfer is unchanged ───────────────────────────── */

    public function test_a_transfer_still_writes_both_halves(): void
    {
        $from = $this->dept('Engineering');
        $to = $this->dept('Platform');
        $desig = $this->desig('Engineer');

        $employee = $this->legacyEmployee([
            'department_id' => $from->id, 'department' => 'Engineering',
            'designation_id' => $desig->id, 'designation' => 'Engineer',
        ]);

        // EmployeeMovementService has resolved both halves correctly since
        // transfers shipped and is deliberately untouched by this phase.
        app(\App\Services\Hr\EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_department_id' => $to->id,
            'effective_date' => '2026-02-01', 'reason' => 'Reorg',
        ], $this->a->id);

        $fresh = $employee->fresh();
        $this->assertSame($to->id, (int) $fresh->department_id);
        $this->assertSame('Platform', $fresh->department);
        $this->assertSame($desig->id, (int) $fresh->designation_id);
    }

    /* ── 16. legacy records stay readable ─────────────────────────────── */

    public function test_an_employee_with_no_master_ids_still_loads_and_lists(): void
    {
        $legacy = $this->preLinkEmployee(['department' => 'Ops', 'designation' => 'Analyst']);

        Sanctum::actingAs($this->hr());

        $listed = $this->getJson('/api/hr/employees')->assertOk()->json();
        $rows = $listed['data'] ?? $listed;
        $this->assertNotEmpty($rows);

        $this->getJson("/api/hr/employees/{$legacy->id}")->assertOk();

        $fresh = $legacy->fresh();
        $this->assertNull($fresh->department_id);
        $this->assertSame('Ops', $fresh->department);
    }

    public function test_a_legacy_employee_can_be_edited_without_naming_its_masters(): void
    {
        $legacy = $this->preLinkEmployee();

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$legacy->id}", ['phone' => '9111111111'])->assertOk();

        $fresh = $legacy->fresh();
        $this->assertSame('9111111111', $fresh->phone);
        // Re-linked by OrgLink on the way through, which is its existing job
        // and not something this phase introduced. What matters is that the
        // employee still saves and keeps its own department name.
        $this->assertSame('Ops', $fresh->department);
    }

    /* ── 18. permissions and tenancy on the route ─────────────────────── */

    public function test_only_an_hr_manager_may_write_an_employee(): void
    {
        $dept = $this->dept();
        $desig = $this->desig();
        $employee = $this->legacyEmployee();

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/employees', $this->payload([
            'department_id' => $dept->id, 'designation_id' => $desig->id,
        ]))->assertStatus(403);

        $this->putJson("/api/hr/employees/{$employee->id}", ['department_id' => $dept->id])->assertStatus(403);

        $this->assertSame(1, HrEmployee::count());
    }

    public function test_another_workspaces_employee_cannot_be_edited(): void
    {
        $theirs = $this->legacyEmployee([], $this->b);
        $dept = $this->dept();

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$theirs->id}", ['department_id' => $dept->id])->assertStatus(404);

        // Untouched: still their own workspace's department, never mine.
        $this->assertNotSame($dept->id, (int) $theirs->fresh()->department_id);
        $this->assertSame($this->b->id, (int) HrDepartment::find($theirs->fresh()->department_id)->tenant_id);
    }
}
