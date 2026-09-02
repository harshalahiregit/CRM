<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An employee's identity is unique within a tenant, and codes are never reused.
 *
 * Two defects this pins down.
 *
 * `employee_code` had a GLOBAL unique index while both generators counted within
 * a tenant, so two tenants each produced SNE-2026-001 and the second insert threw
 * inside a transaction, surfacing as a generic 500.
 *
 * EmployeeService generated codes with `count() + 1`, which reuses a code as soon
 * as anyone is deleted: five employees, delete the third, and the next create asks
 * for -005 while -005 still exists.
 */
class EmployeeIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => 'T ' . $slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function employee(int $tenantId, string $code, ?int $userId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id'    => $tenantId,
            'user_id'      => $userId,
            'employee_code' => $code,
            'name'         => 'Person ' . $code,
            'department'   => 'Ops',
            'designation'  => 'Analyst',
            'joining_date' => now()->toDateString(),
            'status'       => 'Active',
        ]);
    }

    public function test_two_tenants_may_hold_the_same_employee_code(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('bravo');

        $this->employee($a->id, 'SNE-2026-001');
        $this->employee($b->id, 'SNE-2026-001');

        $this->assertSame(2, HrEmployee::where('employee_code', 'SNE-2026-001')->count());
    }

    public function test_one_tenant_may_not_reuse_a_code(): void
    {
        $a = $this->tenant('alpha');
        $this->employee($a->id, 'SNE-2026-001');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->employee($a->id, 'SNE-2026-001');
    }

    public function test_one_login_may_not_be_two_employees_in_a_tenant(): void
    {
        $a = $this->tenant('alpha');
        $this->employee($a->id, 'SNE-2026-001', userId: 7);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->employee($a->id, 'SNE-2026-002', userId: 7);
    }

    /** Most employees have no login yet; that must stay allowed. */
    public function test_many_employees_may_have_no_login(): void
    {
        $a = $this->tenant('alpha');
        $this->employee($a->id, 'SNE-2026-001');
        $this->employee($a->id, 'SNE-2026-002');
        $this->employee($a->id, 'SNE-2026-003');

        $this->assertSame(3, HrEmployee::whereNull('user_id')->count());
    }

    /** The same login may be an employee in each of two tenants. */
    public function test_one_login_may_be_an_employee_in_two_tenants(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('bravo');

        $this->employee($a->id, 'SNE-2026-001', userId: 7);
        $this->employee($b->id, 'SNE-2026-001', userId: 7);

        $this->assertSame(2, HrEmployee::where('user_id', 7)->count());
    }

    /** The regression: a deletion must not make the next code collide. */
    public function test_a_deleted_employee_does_not_free_its_code_for_reuse(): void
    {
        $a = $this->tenant('alpha');

        $made = [];
        for ($i = 0; $i < 5; $i++) {
            $made[] = $this->employee($a->id, HrEmployee::nextEmployeeCode($a->id));
        }
        $this->assertSame('SNE-' . date('Y') . '-005', $made[4]->employee_code);

        $made[2]->delete();

        // What count()+1 would have produced, and why it was wrong.
        $byCount = 'SNE-' . date('Y') . '-' . str_pad((string) (HrEmployee::where('tenant_id', $a->id)->count() + 1), 3, '0', STR_PAD_LEFT);
        $this->assertTrue(
            HrEmployee::where('tenant_id', $a->id)->where('employee_code', $byCount)->exists(),
            'The old count()+1 scheme should collide here — if it does not, this test no longer covers the bug.'
        );

        $next = HrEmployee::nextEmployeeCode($a->id);
        $this->assertSame('SNE-' . date('Y') . '-006', $next);
        $this->assertFalse(HrEmployee::where('tenant_id', $a->id)->where('employee_code', $next)->exists());
    }

    /** Sequences advance per tenant, not globally. */
    public function test_each_tenant_has_its_own_sequence(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('bravo');

        $this->employee($a->id, HrEmployee::nextEmployeeCode($a->id));
        $this->employee($a->id, HrEmployee::nextEmployeeCode($a->id));

        $this->assertSame('SNE-' . date('Y') . '-001', HrEmployee::nextEmployeeCode($b->id));
    }

    public function test_the_global_unique_index_is_gone(): void
    {
        $names = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='hr_employees'"))
            ->pluck('name');

        $this->assertContains('hr_employees_tenant_code_unique', $names);
        $this->assertContains('hr_employees_tenant_user_unique', $names);
        $this->assertNotContains('hr_employees_employee_code_unique', $names);
    }
}
