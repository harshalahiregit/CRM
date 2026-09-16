<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee's department is a record, whatever route created them.
 *
 * `department_id` existed on the table from the start and nothing ever wrote it,
 * so the org chart and the reporting rollups — which read the FK — saw an empty
 * company while every list screen, reading the text column, looked correct. The
 * bug was invisible from the screen where the data was entered.
 */
class OrgLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = Tenant::create(['name' => 'S', 'slug' => 'org-link', 'status' => 'active'])->id;
    }

    private function employee(array $over = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id'    => $this->tenantId,
            'employee_code' => 'E'.random_int(1000, 9999),
            'name'         => 'Ravi',
            'department'   => 'Operations',
            'designation'  => 'Analyst',
            'joining_date' => '2024-01-01',
            'status'       => 'Active',
        ], $over));
    }

    public function test_a_name_with_no_record_creates_one_and_links_to_it(): void
    {
        $employee = $this->employee();

        $this->assertNotNull($employee->department_id);
        $this->assertNotNull($employee->designation_id);
        $this->assertSame('Operations', HrDepartment::find($employee->department_id)->name);
        $this->assertSame('Analyst', HrDesignation::find($employee->designation_id)->name);
    }

    /** The reason there were three "Operations" — casing and stray spaces. */
    public function test_spelling_variants_resolve_to_one_record(): void
    {
        $a = $this->employee(['department' => 'Operations']);
        $b = $this->employee(['department' => ' operations ']);
        $c = $this->employee(['department' => 'OPERATIONS']);

        $this->assertSame($a->department_id, $b->department_id);
        $this->assertSame($a->department_id, $c->department_id);
        $this->assertSame(1, HrDepartment::where('tenant_id', $this->tenantId)->where('name', 'like', '%perations%')->count());
    }

    public function test_an_existing_record_is_reused_not_duplicated(): void
    {
        $dept = HrDepartment::create(['tenant_id' => $this->tenantId, 'name' => 'Engineering', 'is_active' => true]);

        $this->assertSame($dept->id, $this->employee(['department' => 'engineering'])->department_id);
        $this->assertSame(1, HrDepartment::where('tenant_id', $this->tenantId)->count());
    }

    public function test_changing_the_department_moves_the_link(): void
    {
        $employee = $this->employee(['department' => 'Operations']);
        $before = $employee->department_id;

        $employee->update(['department' => 'Sales']);

        $this->assertNotSame($before, $employee->fresh()->department_id);
        $this->assertSame('Sales', HrDepartment::find($employee->fresh()->department_id)->name);
    }

    /** A deliberate link must not be undone by a stale text column. */
    public function test_an_explicit_link_is_left_alone(): void
    {
        $chosen = HrDepartment::create(['tenant_id' => $this->tenantId, 'name' => 'Special Projects', 'is_active' => true]);

        $employee = $this->employee(['department' => 'Operations', 'department_id' => $chosen->id]);

        $this->assertSame($chosen->id, $employee->department_id);
    }

    public function test_one_tenants_department_is_never_reused_by_another(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'org-link-2', 'status' => 'active'])->id;

        $mine = $this->employee(['department' => 'Finance']);
        $theirs = $this->employee(['tenant_id' => $other, 'department' => 'Finance']);

        $this->assertNotSame($mine->department_id, $theirs->department_id);
    }

    public function test_a_blank_department_links_to_nothing_rather_than_inventing_one(): void
    {
        $employee = $this->employee(['department' => '', 'designation' => 'Analyst']);

        $this->assertNull($employee->department_id);
        $this->assertSame(0, HrDepartment::where('tenant_id', $this->tenantId)->count());
    }
}
