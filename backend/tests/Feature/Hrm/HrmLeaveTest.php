<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Leave, holidays and attendance raises, in the app's shapes.
 *
 * Field-by-field, because a key the app does not recognise renders blank rather
 * than failing — which is indistinguishable from the data not existing.
 */
class HrmLeaveTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrml-t', 'status' => 'active']);
    }

    private function person(): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        return [$user, $employee];
    }

    private function leaveType(HrEmployee $e, string $name, string $category, bool $paid = true, float $allocated = 12): HrLeaveType
    {
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => $name, 'code' => strtoupper(substr($name, 0, 3)).$e->id,
            'category' => $category, 'paid' => $paid, 'yearly_limit' => $allocated,
            'requires_approval' => true, 'is_active' => true,
        ]);
        $policy = HrLeavePolicy::create([
            'tenant_id' => $this->tenant()->id, 'name' => "P{$name}{$e->id}", 'applies_to' => 'All',
            'weekends_count' => true, 'holidays_count' => false, 'half_day_allowed' => true,
            'negative_balance_allowed' => false, 'is_active' => true,
        ]);
        HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id,
            'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'allocated' => $allocated, 'opening_balance' => $allocated, 'used' => 2, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => $allocated - 2,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        return $type;
    }

    /* ── leave ───────────────────────────────────────────────────────── */

    public function test_my_leaves_returns_every_field_the_model_reads(): void
    {
        [$user, $e] = $this->person();
        $type = $this->leaveType($e, 'Casual', 'Casual');

        HrLeaveApplication::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-03', 'days' => 2,
            'reason' => 'Family function', 'status' => 'Submitted',
            'applied_at' => '2026-03-01 09:00:00', 'decision_remarks' => 'Looks fine',
        ]);

        $row = $this->postJson('/api/Hrm/get-leaves', [])->assertOk()->json('data.0');

        foreach (['id', 'employee_id', 'user_id', 'leave_type_id', 'applied_on', 'start_date', 'end_date',
                  'total_leave_days', 'leave_reason', 'remark', 'status', 'workspace', 'created_by'] as $k) {
            $this->assertArrayHasKey($k, $row, "leave.{$k} is missing.");
        }

        // Real columns, not guessed ones — `days` and `decision_remarks`.
        $this->assertSame('2', $row['total_leave_days']);
        $this->assertSame('Looks fine', $row['remark']);
        $this->assertSame('2026-03-01', $row['applied_on']);
    }

    public function test_leave_types_carry_their_inverted_disable_flag(): void
    {
        [, $e] = $this->person();
        $this->leaveType($e, 'Casual', 'Casual');

        // A type with no balance for this person: present, but not selectable.
        HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Sabbatical', 'code' => 'SAB',
            'category' => 'Earned', 'paid' => true, 'yearly_limit' => 0,
            'requires_approval' => true, 'is_active' => true,
        ]);

        $rows = collect($this->postJson('/api/Hrm/get-leaves-types', [])->assertOk()->json('data'));

        foreach (['id', 'title', 'days', 'used', 'is_disable'] as $k) {
            $this->assertArrayHasKey($k, $rows->first(), "type.{$k} is missing.");
        }

        // 0 means selectable — the app keeps only is_disable == 0.
        $this->assertSame(0, $rows->firstWhere('title', 'Casual')['is_disable']);
        $this->assertSame(1, $rows->firstWhere('title', 'Sabbatical')['is_disable']);
    }

    public function test_applying_for_leave_works_and_ignores_a_posted_user_id(): void
    {
        [$user, $e] = $this->person();
        $type = $this->leaveType($e, 'Casual', 'Casual');

        $victim = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-9', 'name' => 'Raj',
            'department' => 'Ops', 'designation' => 'A', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->postJson('/api/Hrm/leave-request', [
            'leave_type_id' => $type->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03',
            'leave_reason' => 'Family function', 'remark' => 'Back Wednesday',
            'user_id' => 9999, 'employee_id' => $victim->id,
        ])->assertOk()->assertJsonPath('status', 1);

        // The app sends user_id from its own prefs; it must never decide whose leave this is.
        $this->assertSame(1, HrLeaveApplication::where('employee_id', $e->id)->count());
        $this->assertSame(0, HrLeaveApplication::where('employee_id', $victim->id)->count());
    }

    public function test_a_refusal_is_200_with_status_zero(): void
    {
        [, $e] = $this->person();

        // A type with no balance assigned — the service refuses it.
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Casual', 'paid' => true, 'yearly_limit' => 12,
            'requires_approval' => true, 'is_active' => true,
        ]);

        $r = $this->postJson('/api/Hrm/leave-request', [
            'leave_type_id' => $type->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-02',
        ])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertNotEmpty($r->json('message'));
    }

    /* ── balance: twelve fixed fields ────────────────────────────────── */

    public function test_balance_returns_all_twelve_fields(): void
    {
        [, $e] = $this->person();
        $this->leaveType($e, 'Casual', 'Casual', true, 12);
        $this->leaveType($e, 'Loss of Pay', 'Unpaid', false, 5);

        $d = $this->postJson('/api/Hrm/leave-balance', [])->assertOk()->json('data');

        foreach (['paid', 'unpaid', 'casual', 'comp_off'] as $b) {
            foreach (['_leaves', '_used', '_remaining'] as $suffix) {
                $this->assertArrayHasKey($b.$suffix, $d, "{$b}{$suffix} is missing.");
            }
        }

        $this->assertSame(12.0, (float) $d['casual_leaves']);
        $this->assertSame(2.0, (float) $d['casual_used']);
        $this->assertSame(10.0, (float) $d['casual_remaining']);
        $this->assertSame(5.0, (float) $d['unpaid_leaves']);
    }

    /** A paid type outside their four buckets is summed into paid, not dropped. */
    public function test_a_type_with_no_bucket_still_counts(): void
    {
        [, $e] = $this->person();
        $this->leaveType($e, 'Sick', 'Sick', true, 8);

        $d = $this->postJson('/api/Hrm/leave-balance', [])->assertOk()->json('data');

        $this->assertSame(8.0, (float) $d['paid_leaves'], 'Sick leave is real leave; it must not vanish.');
    }

    /* ── holidays ────────────────────────────────────────────────────── */

    public function test_holidays_use_their_calendar_shape(): void
    {
        $this->person();

        DB::table('hr_holidays')->insert([
            ['tenant_id' => $this->tenant()->id, 'title' => 'Diwali', 'holiday_date' => '2026-11-08',
             'holiday_type' => 'Public', 'applicable_for' => 'All', 'is_optional' => false,
             'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant()->id, 'title' => 'Optional Day', 'holiday_date' => '2026-12-25',
             'holiday_type' => 'Optional', 'applicable_for' => 'All', 'is_optional' => true,
             'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = $this->postJson('/api/Hrm/holidays-list', [])->assertOk()->json('data');

        foreach (['title', 'start', 'end', 'className'] as $k) {
            $this->assertArrayHasKey($k, $rows[0], "holiday.{$k} is missing.");
        }

        $this->assertSame('public-holiday', $rows[0]['className']);
        // Exactly 'optional' — the calendar compares this string directly to pick
        // the badge and the dot colour, so a tidier spelling renders an optional
        // day as a mandatory one.
        $this->assertSame('optional', $rows[1]['className']);
    }

    /* ── attendance raises ───────────────────────────────────────────── */

    public function test_a_raise_round_trips_with_every_field(): void
    {
        [, $e] = $this->person();

        $this->postJson('/api/Hrm/submit-attendance-raise', [
            'attendance_date' => '2026-03-02', 'login_time' => '09:00', 'logout_time' => '18:00',
            'reason' => 'Forgot to clock out.',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(1, HrAttendanceCorrection::where('employee_id', $e->id)->count());

        $row = $this->postJson('/api/Hrm/get-attendance-raises', [])->assertOk()->json('data.0');

        foreach (['id', 'attendance_date', 'login_time', 'logout_time', 'reason', 'status', 'admin_remarks', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "raise.{$k} is missing.");
        }

        $this->assertSame('2026-03-02', $row['attendance_date']);
        $this->assertSame('09:00', $row['login_time']);
        $this->assertSame('18:00', $row['logout_time']);
    }

    /** Times may arrive as HH:mm:ss; the service wants HH:mm. */
    public function test_seconds_in_a_time_do_not_break_it(): void
    {
        $this->person();

        $this->postJson('/api/Hrm/submit-attendance-raise', [
            'attendance_date' => '2026-03-02', 'login_time' => '09:00:00', 'logout_time' => '18:00:00',
            'reason' => 'Forgot.',
        ])->assertOk()->assertJsonPath('status', 1);
    }

    public function test_a_duplicate_raise_is_refused_without_an_http_error(): void
    {
        $this->person();

        $payload = ['attendance_date' => '2026-03-02', 'login_time' => '09:00', 'reason' => 'Forgot.'];

        $this->postJson('/api/Hrm/submit-attendance-raise', $payload)->assertOk()->assertJsonPath('status', 1);
        $r = $this->postJson('/api/Hrm/submit-attendance-raise', $payload)->assertOk();

        $this->assertSame(0, $r->json('status'));
    }

    public function test_a_raise_never_shows_another_employees(): void
    {
        [, $mine] = $this->person();

        $otherUser = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Raj', 'email' => 'raj@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $other = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-2', 'name' => 'Raj',
            'department' => 'Ops', 'designation' => 'A', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $otherUser->id,
        ]);
        HrAttendanceCorrection::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $other->id,
            'attendance_date' => '2026-03-02', 'requested_check_in' => '09:00',
            'reason' => 'Theirs', 'status' => 'pending',
        ]);

        $this->postJson('/api/Hrm/get-attendance-raises', [])->assertOk()->assertJsonCount(0, 'data');
    }
}
