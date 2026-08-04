<?php

namespace Tests\Feature\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Services\SangoeTrack\LeaveSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leave sync mirrors the attendance split: this class owns the mapping, the
 * existing Leave services own every rule. These tests pin that — day counts come
 * from computeDays(), approval goes through LeaveApprovalService so the balance
 * ledger is written, and a re-sync of an unchanged leave writes nothing.
 */
class SangoeTrackLeaveSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sangoetrack.enabled'      => true,
            'sangoetrack.base_url'     => 'https://track.test/api',
            'sangoetrack.email'        => 'sync@test.com',
            'sangoetrack.password'     => 'secret',
            'sangoetrack.workspace_id' => 7,
        ]);

        Cache::flush();
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id'                => self::TENANT,
            'name'                     => 'Asha Menon',
            'email'                    => 'asha'.uniqid().'@test.com',
            'employee_code'            => 'E'.random_int(100000, 999999),
            'department'               => 'Engineering',
            'designation'              => 'Developer',
            'status'                   => 'Active',
            'joining_date'             => '2020-01-01',
            'sangoetrack_user_id'      => 55,
            'sangoetrack_workspace_id' => 7,
        ], $attrs));
    }

    private function leaveType(string $name = 'Casual Leave'): HrLeaveType
    {
        return HrLeaveType::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'code' => strtoupper(substr($name, 0, 2)).random_int(10, 99),
            'category' => 'Paid', 'paid' => true, 'yearly_limit' => 12, 'is_active' => true,
        ]);
    }

    /** An active balance is what lets an approval write its ledger entry. */
    private function balance(HrEmployee $employee, HrLeaveType $type, float $available = 10): HrEmployeeLeaveBalance
    {
        $policy = HrLeavePolicy::create([
            'tenant_id' => self::TENANT, 'name' => 'Standard '.random_int(100, 999),
            'year' => 2026, 'weekends_count' => false, 'is_active' => true,
        ]);

        return HrEmployeeLeaveBalance::create([
            'tenant_id' => self::TENANT, 'employee_id' => $employee->id,
            'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id,
            'year' => 2026, 'allocated' => $available, 'used' => 0,
            'available_balance' => $available, 'is_active' => true,
        ]);
    }

    private function fakeLeaves(array $rows): void
    {
        Http::fake([
            'track.test/api/login'      => Http::response(['token' => 'jwt-abc'], 200),
            'track.test/api/Hrm/leaves' => Http::response(['data' => $rows], 200),
        ]);
    }

    private function sync(): LeaveSyncService
    {
        return app(LeaveSyncService::class);
    }

    /* ── mapping ─────────────────────────────────────────────────────── */

    public function test_a_remote_leave_becomes_a_crm_application(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        $this->fakeLeaves([[
            'id' => 9001, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Pending',
        ]]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['failed']);

        $app = HrLeaveApplication::firstOrFail();
        $this->assertSame(9001, (int) $app->sangoetrack_leave_id);
        $this->assertSame($employee->id, $app->employee_id);
        $this->assertSame(HrLeaveApplication::SUBMITTED, $app->status);
    }

    /** Days come from computeDays(), which skips weekends unless the policy says otherwise. */
    public function test_day_count_uses_the_existing_calculator(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        // Mon 7th - Fri 11th = 5 working days; the range also covers no weekend.
        $this->fakeLeaves([[
            'id' => 9002, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-11', 'status' => 'Pending',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame('5.0', (string) HrLeaveApplication::firstOrFail()->days);
    }

    public function test_a_half_day_is_half_a_day(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        $this->fakeLeaves([[
            'id' => 9003, 'leave_type' => 'Casual Leave', 'half_day' => true,
            'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'status' => 'Pending',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame('0.5', (string) HrLeaveApplication::firstOrFail()->days);
    }

    public function test_a_leave_type_with_no_crm_equivalent_is_skipped_not_invented(): void
    {
        $employee = $this->employee();
        $this->leaveType('Casual Leave');

        $this->fakeLeaves([[
            'id' => 9004, 'leave_type' => 'Sabbatical',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'status' => 'Pending',
        ]]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('hr_leave_applications', 0);
        $this->assertSame(1, HrLeaveType::count(), 'a sync must never create master data');
    }

    public function test_a_row_without_an_external_id_is_skipped(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        $this->fakeLeaves([
            ['leave_type' => 'Casual Leave', 'from_date' => '2026-09-07', 'to_date' => '2026-09-07'],
            ['id' => 9005, 'leave_type' => 'Casual Leave', 'from_date' => '2026-09-09', 'to_date' => '2026-09-09'],
        ]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['skipped']);
    }

    /* ── approval reuses the existing service ────────────────────────── */

    public function test_an_approved_leave_deducts_balance_through_the_approval_service(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $balance  = $this->balance($employee, $type, 10);

        $this->fakeLeaves([[
            'id' => 9006, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Approved',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(HrLeaveApplication::APPROVED, HrLeaveApplication::firstOrFail()->status);
        $this->assertEqualsWithDelta(8.0, (float) $balance->fresh()->available_balance, 0.001);
    }

    /** The whole point of the no-churn pattern: never deduct twice. */
    public function test_resyncing_an_approved_leave_does_not_deduct_again(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $balance  = $this->balance($employee, $type, 10);

        $this->fakeLeaves([[
            'id' => 9007, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Approved',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');
        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, HrLeaveApplication::count());
        $this->assertEqualsWithDelta(8.0, (float) $balance->fresh()->available_balance, 0.001);
    }

    public function test_a_rejected_leave_leaves_balance_untouched(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $balance  = $this->balance($employee, $type, 10);

        $this->fakeLeaves([[
            'id' => 9008, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Rejected',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(HrLeaveApplication::REJECTED, HrLeaveApplication::firstOrFail()->status);
        $this->assertEqualsWithDelta(10.0, (float) $balance->fresh()->available_balance, 0.001);
    }

    /**
     * Approving with no CRM balance would make the deduction vanish, so the
     * application stays pending instead and the discrepancy stays visible.
     */
    public function test_an_approved_leave_without_a_crm_balance_stays_pending(): void
    {
        $employee = $this->employee();
        $this->leaveType('Casual Leave');   // no balance assigned

        $this->fakeLeaves([[
            'id' => 9009, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Approved',
        ]]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['synced']);
        $this->assertSame(HrLeaveApplication::SUBMITTED, HrLeaveApplication::firstOrFail()->status);
    }

    /* ── idempotency ─────────────────────────────────────────────────── */

    public function test_resyncing_an_unchanged_leave_writes_nothing_new(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        $this->fakeLeaves([[
            'id' => 9010, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'status' => 'Pending',
        ]]);

        $this->sync()->syncEmployee($employee, '9', '2026');
        $first = HrLeaveApplication::firstOrFail();

        $second = $this->sync()->syncEmployee($employee, '9', '2026');
        $again  = HrLeaveApplication::firstOrFail();

        $this->assertSame(0, $second['failed']);
        $this->assertSame(1, HrLeaveApplication::count());
        $this->assertEquals($first->updated_at->toDateTimeString(), $again->updated_at->toDateTimeString());
    }

    public function test_a_changed_date_range_updates_in_place(): void
    {
        $employee = $this->employee();
        $type     = $this->leaveType();
        $this->balance($employee, $type);

        Http::fake([
            'track.test/api/login'      => Http::response(['token' => 'jwt-abc'], 200),
            'track.test/api/Hrm/leaves' => Http::sequence()
                ->push(['data' => [['id' => 9011, 'leave_type' => 'Casual Leave', 'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'status' => 'Pending']]], 200)
                ->push(['data' => [['id' => 9011, 'leave_type' => 'Casual Leave', 'from_date' => '2026-09-07', 'to_date' => '2026-09-09', 'status' => 'Pending']]], 200),
        ]);

        $this->sync()->syncEmployee($employee, '9', '2026');
        $this->sync()->syncEmployee($employee, '9', '2026');

        $app = HrLeaveApplication::firstOrFail();
        $this->assertSame(1, HrLeaveApplication::count());
        $this->assertSame('2026-09-09', $app->to_date->toDateString());
        $this->assertSame('3.0', (string) $app->days);
    }

    /* ── isolation ───────────────────────────────────────────────────── */

    public function test_unmapped_employees_are_skipped_without_calling_the_api(): void
    {
        $employee = $this->employee(['sangoetrack_user_id' => null]);
        Http::fake();

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_sync_all_only_touches_the_given_tenant(): void
    {
        $mine = $this->employee(['tenant_id' => 1]);
        $type = $this->leaveType();
        $this->balance($mine, $type);
        $theirs = $this->employee(['tenant_id' => 2, 'sangoetrack_user_id' => 56]);

        $this->fakeLeaves([[
            'id' => 9012, 'leave_type' => 'Casual Leave',
            'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'status' => 'Pending',
        ]]);

        $this->sync()->syncAll(1, '9', '2026');

        $this->assertDatabaseHas('hr_leave_applications', ['employee_id' => $mine->id]);
        $this->assertDatabaseMissing('hr_leave_applications', ['employee_id' => $theirs->id]);
    }
}
