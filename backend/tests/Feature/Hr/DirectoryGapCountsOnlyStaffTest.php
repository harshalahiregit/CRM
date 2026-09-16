<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\DirectoryReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The directory gap panel counts people, not portal accounts.
 *
 * It read every active login, so a customer contact and a third-party
 * contractor were reported as "has a login, no employee record" — which is true
 * of both, correctly and permanently. A customer is not on your payroll and
 * never will be; a contractor works for somebody else.
 *
 * On the live workspace that read seven where three were real. A count that is
 * mostly false alarms is one people stop reading, and the three that mattered —
 * staff who genuinely were missing from HR and would therefore be missed by
 * every payroll run — were sitting in the middle of it.
 *
 * The filter matches hr:reconcile-logins, which has always provisioned only
 * staff and admin. The screen and the command now answer the same question.
 */
class DirectoryGapCountsOnlyStaffTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'dir-gap', 'status' => 'active']);
        $this->tenantId = $tenant->id;
    }

    private function login(string $email, string $role): User
    {
        return User::create([
            'tenant_id' => $this->tenantId, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('x'), 'role' => $role, 'status' => 'active',
        ]);
    }

    /** External identities are not gaps, and must not be listed as any. */
    public function test_customers_and_contractors_are_not_reported_as_missing_employees(): void
    {
        $this->login('customer@gap.test', 'client');
        $this->login('contractor@gap.test', 'third_party_vendor');
        $this->login('supplier@gap.test', 'purchase_vendor');
        $this->login('doctor@gap.test', 'doctor');

        $report = app(DirectoryReconciliationService::class)->report($this->tenantId);
        $emails = array_column($report['without_employee'], 'email');

        $this->assertSame([], $emails, 'external accounts were counted as missing employee records');
        $this->assertSame(0, $report['summary']['without_employee']);
    }

    /** Staff and admin without a record are real gaps and must still show. */
    public function test_staff_and_admin_without_a_record_are_still_reported(): void
    {
        $this->login('priya@gap.test', 'staff');
        $this->login('boss@gap.test', 'admin');
        $this->login('customer@gap.test', 'client');

        $report = app(DirectoryReconciliationService::class)->report($this->tenantId);
        $emails = array_column($report['without_employee'], 'email');

        sort($emails);
        $this->assertSame(['boss@gap.test', 'priya@gap.test'], $emails);
        $this->assertSame(2, $report['summary']['without_employee']);
    }

    /** A staff member WITH a record is not a gap. */
    public function test_a_linked_staff_member_is_not_a_gap(): void
    {
        $user = $this->login('linked@gap.test', 'staff');

        HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => 'SNE-900', 'name' => 'Linked',
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active', 'user_id' => $user->id,
        ]);

        $report = app(DirectoryReconciliationService::class)->report($this->tenantId);

        $this->assertSame(0, $report['summary']['without_employee']);
    }

    /** The other direction is untouched: an employee with no login still counts. */
    public function test_an_employee_with_no_login_is_still_reported(): void
    {
        HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => 'SNE-901', 'name' => 'No Login',
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $report = app(DirectoryReconciliationService::class)->report($this->tenantId);

        $this->assertSame(1, $report['summary']['without_login']);
    }
}
