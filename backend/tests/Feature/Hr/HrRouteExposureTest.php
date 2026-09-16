<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a plain employee can reach in the HR module.
 *
 * Most of HR sits in one `auth:sanctum` group and is protected by a
 * canManageHrQueue() call inside each controller. That works right up until a
 * controller does not make the call — and eight of the forty-two do not. Four of
 * those are the My* controllers, which are supposed to be open and filter to the
 * caller's own record. The rest are company-wide reads with no gate at all.
 *
 * This pins which is which, so a new controller added to that group without a
 * check is caught here rather than by somebody browsing another person's
 * equipment list.
 */
class HrRouteExposureTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private HrEmployee $someoneElse;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'exposure', 'status' => 'active']);

        $this->staff = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'ravi@exposure.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active', 'user_id' => $this->staff->id,
        ]);

        $this->someoneElse = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E2', 'name' => 'Meera',
            'department' => 'Finance', 'designation' => 'Manager',
            'joining_date' => '2023-01-01', 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->staff);
    }

    /** Their own record, their own requests — open by design. */
    public function test_an_employee_can_still_reach_their_own_things(): void
    {
        foreach (['/api/hr/me/advances', '/api/hr/me/leave', '/api/hr/me/settings'] as $url) {
            $this->assertNotSame(
                403,
                $this->getJson($url)->status(),
                "{$url} must stay open — it is how somebody sees their own record.",
            );
        }
    }

    /**
     * Another person's issued equipment is not a plain employee's business.
     *
     * The asset register records serial numbers of laptops and phones by holder.
     * It sat in the auth-only group with no check inside the controller.
     */
    public function test_another_employees_asset_register_is_not_readable(): void
    {
        $this->getJson("/api/hr/employees/{$this->someoneElse->id}/assets")
            ->assertForbidden();
    }

    /** Company-wide HR figures — headcount, attrition, attendance. */
    public function test_the_hr_dashboard_is_not_readable_by_everyone(): void
    {
        $this->getJson('/api/hr/dashboard')->assertForbidden();
    }
}
