<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmploymentType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employment Type: the company's own vocabulary for what kind of employment
 * somebody is on.
 *
 * NOTHING IS SEEDED, and that is the point. Shipping "Permanent, Contract,
 * Intern" would make a guess look like a decision; a workspace defines its own
 * and one that has defined none behaves exactly as it did before this existed,
 * because employment_type_id is nullable and optional everywhere.
 *
 * THREE NEIGHBOURS IT IS NOT, each asserted below so a later refactor cannot
 * quietly merge them:
 *
 *   hr_hiring_requests.employment_type is a REQUISITION field that
 *   RestJobBoardChannel publishes verbatim to external job boards. Those
 *   boards expect a fixed vocabulary, so it stays a system enum.
 *
 *   hr_employees.worker_type is the org chart's three-value grouping
 *   (employee / consultant / freelancer) with a NOT NULL default.
 *
 *   hr_employees.category is the salary register's minimum-wage skill
 *   category.
 */
class EmploymentTypeMasterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'et-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'et-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function hr(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'HR',
            'email' => uniqid().'@et.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function outsider(): User
    {
        return User::create([
            'tenant_id' => $this->a->id, 'name' => 'Nobody',
            'email' => uniqid().'@et.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function type(string $name = 'Permanent', ?Tenant $t = null, bool $active = true, int $sort = 0): HrEmploymentType
    {
        return HrEmploymentType::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 4)).substr(uniqid(), -3),
            'sort_order' => $sort, 'is_active' => $active,
        ]);
    }

    private function employee(array $attrs = [], ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs));
    }

    /** Masters the employee form requires regardless of this feature. */
    private function orgMasters(?Tenant $t = null): array
    {
        $t = $t ?: $this->a;

        return [
            'department_id' => HrDepartment::create(
                ['tenant_id' => $t->id, 'name' => 'Ops'.substr(uniqid(), -4), 'is_active' => true])->id,
            'designation_id' => HrDesignation::create(
                ['tenant_id' => $t->id, 'name' => 'Analyst'.substr(uniqid(), -4), 'is_active' => true])->id,
        ];
    }

    private function hirePayload(array $over = [], ?Tenant $t = null): array
    {
        return array_merge([
            'name' => 'Asha Rao', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'skip_probation' => true, 'probation_skip_reason' => 'Not in scope.',
        ] + $this->orgMasters($t), $over);
    }

    /* ══ MASTER CRUD ══════════════════════════════════════════════════ */

    public function test_the_master_starts_empty_and_seeds_nothing(): void
    {
        Sanctum::actingAs($this->hr());

        // A brand-new workspace defines its own vocabulary. Nothing is assumed
        // on its behalf.
        $this->assertSame([], $this->getJson('/api/hr/employment-types')->assertOk()->json());
    }

    public function test_employment_types_support_authorized_crud(): void
    {
        Sanctum::actingAs($this->hr());

        $created = $this->postJson('/api/hr/employment-types', [
            'name' => 'Permanent', 'code' => 'PERM', 'sort_order' => 1,
        ])->assertStatus(201)->json();

        $id = $created['id'];

        $this->putJson("/api/hr/employment-types/{$id}", ['name' => 'Full Permanent'])->assertOk();
        $this->assertSame('Full Permanent', HrEmploymentType::find($id)->name);

        $this->deleteJson("/api/hr/employment-types/{$id}")->assertOk();
        $this->assertNull(HrEmploymentType::find($id));
    }

    public function test_a_type_can_be_deactivated_and_reactivated(): void
    {
        $type = $this->type('Contract');

        Sanctum::actingAs($this->hr());

        $this->putJson("/api/hr/employment-types/{$type->id}", ['name' => 'Contract', 'is_active' => false])->assertOk();
        $this->assertFalse((bool) $type->fresh()->is_active);

        $this->putJson("/api/hr/employment-types/{$type->id}", ['name' => 'Contract', 'is_active' => true])->assertOk();
        $this->assertTrue((bool) $type->fresh()->is_active);
    }

    public function test_the_list_is_ordered_by_the_order_the_company_chose(): void
    {
        $this->type('Zeta', null, true, 1);
        $this->type('Alpha', null, true, 2);

        Sanctum::actingAs($this->hr());
        $names = collect($this->getJson('/api/hr/employment-types')->assertOk()->json())->pluck('name')->all();

        // Sort order, not alphabetical — the dropdown reads the way HR arranged it.
        $this->assertSame(['Zeta', 'Alpha'], $names);
    }

    /**
     * The order is settable THROUGH THE API, not only in a fixture.
     *
     * The test above builds its rows with the model, so it proves the query
     * orders correctly while proving nothing about the write accepting a sort
     * order at all — a mutation dropping sort_order from the attributes
     * survived it. This sets the order over HTTP and then reads the list back.
     */
    public function test_the_order_can_be_set_and_changed_through_the_api(): void
    {
        Sanctum::actingAs($this->hr());

        $first  = $this->postJson('/api/hr/employment-types', ['name' => 'Alpha', 'sort_order' => 5])
            ->assertStatus(201)->json();
        $second = $this->postJson('/api/hr/employment-types', ['name' => 'Zeta', 'sort_order' => 1])
            ->assertStatus(201)->json();

        $this->assertSame(5, (int) HrEmploymentType::find($first['id'])->sort_order);
        $this->assertSame(1, (int) HrEmploymentType::find($second['id'])->sort_order);

        $names = fn () => collect($this->getJson('/api/hr/employment-types')->assertOk()->json())
            ->pluck('name')->all();

        $this->assertSame(['Zeta', 'Alpha'], $names());

        // Re-ordering through an edit moves it.
        $this->putJson("/api/hr/employment-types/{$first['id']}", ['name' => 'Alpha', 'sort_order' => 0])->assertOk();

        $this->assertSame(0, (int) HrEmploymentType::find($first['id'])->sort_order);
        $this->assertSame(['Alpha', 'Zeta'], $names());
    }

    public function test_a_duplicate_name_within_a_workspace_is_refused(): void
    {
        $this->type('Permanent');

        Sanctum::actingAs($this->hr());
        $this->postJson('/api/hr/employment-types', ['name' => 'permanent'])
            ->assertStatus(422)
            ->assertJsonPath('message', '“permanent” already exists.');

        $this->assertSame(1, HrEmploymentType::where('tenant_id', $this->a->id)->count());
    }

    public function test_the_same_name_is_allowed_in_another_workspace(): void
    {
        $this->type('Permanent');

        Sanctum::actingAs($this->hr($this->b));
        $this->postJson('/api/hr/employment-types', ['name' => 'Permanent'])->assertStatus(201);

        $this->assertSame(1, HrEmploymentType::where('tenant_id', $this->a->id)->count());
        $this->assertSame(1, HrEmploymentType::where('tenant_id', $this->b->id)->count());
    }

    public function test_a_type_with_employees_on_it_cannot_be_deleted(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id]);

        Sanctum::actingAs($this->hr());
        $this->deleteJson("/api/hr/employment-types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete employment type “Permanent” — 1 employee(s) are assigned to it.');

        $this->assertNotNull($type->fresh());
        $this->assertSame($type->id, (int) $employee->fresh()->employment_type_id);
    }

    public function test_deactivating_a_type_leaves_its_employees_assigned(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id]);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employment-types/{$type->id}", ['name' => 'Permanent', 'is_active' => false])->assertOk();

        $this->assertSame($type->id, (int) $employee->fresh()->employment_type_id);
        $this->assertSame('Active', $employee->fresh()->status);
    }

    public function test_the_employee_count_is_reported(): void
    {
        $type = $this->type('Permanent');
        $this->employee(['employment_type_id' => $type->id]);
        $this->employee(['employment_type_id' => $type->id]);

        Sanctum::actingAs($this->hr());
        $row = collect($this->getJson('/api/hr/employment-types')->assertOk()->json())->firstWhere('id', $type->id);

        $this->assertSame(2, $row['employee_count']);
    }

    /* ══ TENANCY AND AUTHORIZATION ════════════════════════════════════ */

    public function test_one_workspace_never_lists_anothers_types(): void
    {
        $this->type('Alpha Type');
        $this->type('Beta Type', $this->b);

        Sanctum::actingAs($this->hr());
        $names = collect($this->getJson('/api/hr/employment-types')->assertOk()->json())->pluck('name')->all();

        $this->assertSame(['Alpha Type'], $names);
    }

    public function test_another_workspaces_type_cannot_be_edited_or_deleted(): void
    {
        $theirs = $this->type('Theirs', $this->b);

        Sanctum::actingAs($this->hr());

        $this->putJson("/api/hr/employment-types/{$theirs->id}", ['name' => 'Stolen'])->assertStatus(404);
        $this->deleteJson("/api/hr/employment-types/{$theirs->id}")->assertStatus(404);

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_only_an_hr_manager_may_manage_the_master(): void
    {
        $type = $this->type('Permanent');

        Sanctum::actingAs($this->outsider());

        $this->postJson('/api/hr/employment-types', ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson("/api/hr/employment-types/{$type->id}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson("/api/hr/employment-types/{$type->id}")->assertStatus(403);

        $this->assertSame('Permanent', $type->fresh()->name);
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $type = $this->type('Permanent');

        $this->getJson('/api/hr/employment-types')->assertStatus(401);
        $this->postJson('/api/hr/employment-types', ['name' => 'X'])->assertStatus(401);
        $this->putJson("/api/hr/employment-types/{$type->id}", ['name' => 'X'])->assertStatus(401);
        $this->deleteJson("/api/hr/employment-types/{$type->id}")->assertStatus(401);
    }

    /* ══ EMPLOYEE ASSIGNMENT ══════════════════════════════════════════ */

    public function test_an_employment_type_can_be_assigned_at_hire(): void
    {
        $type = $this->type('Permanent');

        Sanctum::actingAs($this->hr());
        $this->postJson('/api/hr/employees', $this->hirePayload(['employment_type_id' => $type->id]))
            ->assertStatus(201);

        $employee = HrEmployee::where('tenant_id', $this->a->id)->firstOrFail();
        $this->assertSame($type->id, (int) $employee->employment_type_id);
    }

    public function test_hiring_without_an_employment_type_still_works(): void
    {
        // A workspace that has configured none must not be blocked from hiring.
        Sanctum::actingAs($this->hr());
        $this->postJson('/api/hr/employees', $this->hirePayload())->assertStatus(201);

        $this->assertNull(HrEmployee::where('tenant_id', $this->a->id)->firstOrFail()->employment_type_id);
    }

    public function test_an_invalid_employment_type_is_refused(): void
    {
        Sanctum::actingAs($this->hr());

        $this->postJson('/api/hr/employees', $this->hirePayload(['employment_type_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('employment_type_id');

        $this->assertSame(0, HrEmployee::count());
    }

    public function test_another_workspaces_type_cannot_be_assigned_to_an_employee(): void
    {
        $theirs = $this->type('Theirs', $this->b);

        Sanctum::actingAs($this->hr());

        $this->postJson('/api/hr/employees', $this->hirePayload(['employment_type_id' => $theirs->id]))
            ->assertStatus(422)->assertJsonValidationErrors('employment_type_id');

        $employee = $this->employee();
        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('employment_type_id');

        $this->assertNull($employee->fresh()->employment_type_id);
    }

    public function test_the_service_refuses_a_type_from_another_workspace(): void
    {
        // The second guard, where the write happens — reached by a caller that
        // never went through the request rules.
        $theirs = $this->type('Theirs', $this->b);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('Employment type not found');

        app(EmployeeService::class)->create([
            'name' => 'X', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'employment_type_id' => $theirs->id, 'skip_probation' => true,
        ], $this->a->id);
    }

    public function test_an_employment_type_can_be_changed_and_cleared(): void
    {
        $permanent = $this->type('Permanent');
        $contract = $this->type('Contract');
        $employee = $this->employee(['employment_type_id' => $permanent->id]);

        Sanctum::actingAs($this->hr());

        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type_id' => $contract->id])->assertOk();
        $this->assertSame($contract->id, (int) $employee->fresh()->employment_type_id);

        // Null puts them back to "none chosen".
        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type_id' => null])->assertOk();
        $this->assertNull($employee->fresh()->employment_type_id);
    }

    public function test_an_edit_that_mentions_neither_leaves_the_type_alone(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id]);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['phone' => '9000000000'])->assertOk();

        $this->assertSame($type->id, (int) $employee->fresh()->employment_type_id);
    }

    public function test_an_inactive_type_stays_assigned_and_can_be_resaved(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id]);
        $type->update(['is_active' => false]);

        Sanctum::actingAs($this->hr());

        // The master feed is active-only, so the UI keeps a retired value
        // visible and marked. The backend must therefore still accept it, or
        // every subsequent edit of this employee would fail.
        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type_id' => $type->id])->assertOk();
        $this->assertSame($type->id, (int) $employee->fresh()->employment_type_id);
    }

    /* ══ EXISTING DATA ════════════════════════════════════════════════ */

    public function test_existing_employees_remain_readable_with_no_type(): void
    {
        $legacy = $this->employee();

        Sanctum::actingAs($this->hr());

        $this->getJson("/api/hr/employees/{$legacy->id}")->assertOk()->assertJsonPath('employment_type_id', null);
        $this->getJson('/api/hr/employees')->assertOk();

        // Nothing is backfilled — there was never a legacy string to map from.
        $this->assertNull($legacy->fresh()->employment_type_id);
    }

    public function test_an_employee_without_a_type_can_still_be_edited(): void
    {
        $legacy = $this->employee();

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$legacy->id}", ['phone' => '9111111111'])->assertOk();

        $this->assertSame('9111111111', $legacy->fresh()->phone);
        $this->assertNull($legacy->fresh()->employment_type_id);
    }

    /* ══ MASTERS FEED ═════════════════════════════════════════════════ */

    public function test_the_shared_master_feed_offers_active_types_only(): void
    {
        $live = $this->type('Permanent');
        $this->type('Retired', null, false);

        Sanctum::actingAs($this->hr());
        $feed = $this->getJson('/api/hr/master-data')->assertOk()->json('employment_types');

        $this->assertCount(1, $feed);
        $this->assertSame($live->id, $feed[0]['id']);
    }

    public function test_the_master_feed_never_crosses_workspaces(): void
    {
        $this->type('Alpha Type');
        $this->type('Beta Type', $this->b);

        Sanctum::actingAs($this->hr());
        $names = collect($this->getJson('/api/hr/master-data')->assertOk()->json('employment_types'))
            ->pluck('name')->all();

        $this->assertSame(['Alpha Type'], $names);
    }

    /* ══ THE NEIGHBOURS THIS IS NOT ═══════════════════════════════════ */

    public function test_the_requisition_employment_type_stays_a_system_vocabulary(): void
    {
        // hr_hiring_requests.employment_type is published verbatim to external
        // job boards by RestJobBoardChannel, which is why it is an enum and not
        // this master. A test rather than a comment, because merging the two
        // would break publishing silently.
        $src = file_get_contents(base_path('app/Http/Controllers/Api/Hr/PublicHiringRequestController.php'));

        $this->assertStringContainsString(
            "'employment_type'     => 'nullable|in:Full-time,Part-time,Contract,Internship'",
            $src
        );
    }

    public function test_worker_type_remains_the_org_charts_own_concept(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id, 'worker_type' => 'consultant']);

        // Two separate questions on one record. Changing the employment type
        // must not disturb the org chart's grouping.
        Sanctum::actingAs($this->hr());
        $other = $this->type('Contract');
        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type_id' => $other->id])->assertOk();

        $this->assertSame('consultant', $employee->fresh()->worker_type);
        $this->assertSame($other->id, (int) $employee->fresh()->employment_type_id);
    }

    public function test_setting_worker_type_does_not_disturb_the_employment_type(): void
    {
        $type = $this->type('Permanent');
        $employee = $this->employee(['employment_type_id' => $type->id, 'worker_type' => 'employee']);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['worker_type' => 'freelancer'])->assertOk();

        $this->assertSame('freelancer', $employee->fresh()->worker_type);
        $this->assertSame($type->id, (int) $employee->fresh()->employment_type_id);
    }

    /* ══ ONBOARDING / IMPORT COMPATIBILITY ════════════════════════════ */

    public function test_the_service_path_never_mints_an_employment_type(): void
    {
        // OrgLink creates a department or designation master from an unmatched
        // name, because those paths carry free text and hiring must not be
        // blocked. Employment type has NO name column and no free-text path,
        // so there is nothing to mint from and nothing that could.
        app(EmployeeService::class)->create([
            'name' => 'Converted', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department' => 'Some Team', 'designation' => 'Contractor',
            'skip_probation' => true,
        ], $this->a->id);

        $this->assertSame(0, HrEmploymentType::where('tenant_id', $this->a->id)->count());
    }

    public function test_an_onboarding_conversion_leaves_the_type_unset(): void
    {
        // Conversion carries the job posting's department and position, neither
        // of which is an employment type. The hire completes with none, and HR
        // sets it afterwards — no external string is guessed at.
        $employee = app(EmployeeService::class)->create([
            'name' => 'Converted', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department' => 'Ops', 'designation' => 'Analyst',
            'skip_probation' => true,
        ], $this->a->id);

        $this->assertNull($employee->employment_type_id);
        $this->assertNotNull($employee->id, 'conversion must not be blocked by a missing employment type');
    }

    /* ══ SCHEMA ═══════════════════════════════════════════════════════ */

    public function test_the_employee_column_is_nullable_and_unseeded(): void
    {
        $this->assertTrue(\Schema::hasColumn('hr_employees', 'employment_type_id'));
        $this->assertTrue(\Schema::hasTable('hr_employment_types'));

        // Nothing anywhere created a row on migrate.
        $this->assertSame(0, DB::table('hr_employment_types')->count());
    }
}
