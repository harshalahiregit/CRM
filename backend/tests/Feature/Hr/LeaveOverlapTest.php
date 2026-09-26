<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One person cannot be on leave twice on the same day.
 *
 * There was no check at all. Reproduced against the running API: an approved
 * 5–6 October, then a second application for 5 October — both accepted, 201
 * each. The employee ends up holding two claims on one day, each of which
 * deducts from the balance when approved, and attendance and payroll then
 * disagree about whether that day was worked.
 *
 * The interesting cases are the partial ones. A check that only compares start
 * dates, or only looks for an exact match, passes the first test below and lets
 * every other shape through.
 */
class LeaveOverlapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private HrEmployee $employee;
    private User $user;
    private HrLeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Leave Co', 'slug' => 'leave-co', 'status' => 'active']);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Applicant',
            'email' => 'applicant@leave.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'employee_code' => 'LV-001', 'name' => 'Applicant',
            'department' => 'Operations', 'designation' => 'Executive',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        $this->type = HrLeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Casual Leave',
            'code' => 'CL', 'category' => 'Paid', 'is_active' => true,
        ]);

        $policy = HrLeavePolicy::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Default', 'applies_to' => 'All',
            'weekends_count' => true, 'is_active' => true,
        ]);

        HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id, 'policy_id' => $policy->id,
            'year' => (int) date('Y'), 'opening_balance' => 0, 'allocated' => 40,
            'used' => 0, 'adjusted' => 0, 'carried_forward' => 0, 'available_balance' => 40,
        ]);
    }

    private function existing(string $from, string $to, string $status = 'Approved'): HrLeaveApplication
    {
        return HrLeaveApplication::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id, 'from_date' => $from, 'to_date' => $to,
            'days' => 2, 'status' => $status, 'reason' => 'existing',
        ]);
    }

    private function apply(string $from, string $to)
    {
        return $this->actingAs($this->user)->postJson('/api/hr/me/leave', [
            'leave_type_id' => $this->type->id,
            'from_date'     => $from,
            'to_date'       => $to,
            'reason'        => 'second request',
        ]);
    }

    /** @dataProvider overlappingRanges */
    public function test_an_overlapping_request_is_refused(string $from, string $to, string $shape): void
    {
        $this->existing('2026-10-05', '2026-10-06');

        $this->apply($from, $to)
            ->assertStatus(422)
            ->assertJsonFragment(['status' => 'error']);

        $this->assertSame(1, HrLeaveApplication::count(), "{$shape} must not create a second application");
    }

    public static function overlappingRanges(): array
    {
        return [
            'identical'        => ['2026-10-05', '2026-10-06', 'An identical range'],
            'one day inside'   => ['2026-10-05', '2026-10-05', 'A single day inside the range'],
            'straddles start'  => ['2026-10-03', '2026-10-05', 'A range straddling the start'],
            'straddles end'    => ['2026-10-06', '2026-10-08', 'A range straddling the end'],
            'fully encloses'   => ['2026-10-01', '2026-10-10', 'A range enclosing it entirely'],
        ];
    }

    /** Adjacent is not overlapping — the day after must still be bookable. */
    public function test_the_day_after_is_still_allowed(): void
    {
        $this->existing('2026-10-05', '2026-10-06');

        $this->apply('2026-10-07', '2026-10-07')->assertStatus(201);

        $this->assertSame(2, HrLeaveApplication::count());
    }

    /**
     * A released day is free again.
     *
     * Re-applying after a rejection is the normal thing to do, so only
     * applications that still hold the dates may block a new one.
     */
    public function test_a_cancelled_or_rejected_request_does_not_block_the_dates(): void
    {
        foreach (['Cancelled', 'Rejected'] as $status) {
            HrLeaveApplication::query()->delete();
            $this->existing('2026-10-05', '2026-10-06', $status);

            $this->apply('2026-10-05', '2026-10-06')
                ->assertStatus(201, "a {$status} request must not hold the dates");
        }
    }
}
