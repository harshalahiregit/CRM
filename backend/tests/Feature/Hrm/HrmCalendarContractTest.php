<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dates on the calendar surface are Y-m-d strings, never datetimes.
 *
 * The app does not parse these before comparing them. EventController matches a
 * tapped day with
 *
 *     e.startDate == getDateFormmatted(date)
 *
 * where getDateFormmatted is `DateFormat('yyyy-MM-dd')` — plain string equality.
 * Sending '2026-09-15 00:00:00' therefore matched nothing, and every day tapped
 * in the calendar showed an empty event list while the data sat right there.
 *
 * The holiday card parses `start` for its day/month chips, which tolerates a
 * datetime, but it is the same column and it is sent the same way, so both are
 * pinned here rather than only the one that happened to break.
 */
class HrmCalendarContractTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-15';

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'cal-t', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);

        DB::table('hr_holidays')->insert([
            'tenant_id' => $tenant->id, 'title' => 'Independence Day',
            'description' => 'National holiday', 'holiday_date' => self::DATE,
            'holiday_type' => 'National', 'applicable_for' => 'Organization',
            'is_optional' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);
    }

    public function test_calendar_events_carry_plain_dates_the_app_can_match(): void
    {
        DB::table('hr_events')->insert([
            'tenant_id' => 1, 'title' => 'Quarterly Townhall', 'description' => 'All hands',
            'start_date' => self::DATE, 'end_date' => null, 'color' => '#7C3AED',
            'applicable_for' => 'Organization', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->getJson('/api/Hrm/events?month=9&year=2026')->assertOk()->json('data.0');

        // Exactly this, character for character — the app compares strings.
        $this->assertSame(self::DATE, $row['start_date']);
        $this->assertSame(self::DATE, $row['end_date']);

        foreach (['id', 'title', 'start_date', 'end_date', 'color', 'description'] as $key) {
            $this->assertArrayHasKey($key, $row, "EventData reads {$key}; a missing key renders blank.");
        }
    }

    public function test_the_holiday_list_carries_plain_dates_too(): void
    {
        $row = $this->postJson('/api/Hrm/holidays-list', ['workspace_id' => '1'])
            ->assertOk()->json('data.0');

        $this->assertSame(self::DATE, $row['start']);
        $this->assertSame(self::DATE, $row['end']);

        foreach (['title', 'start', 'end', 'className'] as $key) {
            $this->assertArrayHasKey($key, $row, "HolidayData reads {$key}.");
        }
    }

    /**
     * A holiday scoped to one department belongs to that department only.
     *
     * Neither app endpoint applied `applicable_for`, so a single department's
     * shutdown was published to the whole workspace and the phone showed people a
     * day off they do not get.
     */
    public function test_a_holiday_for_another_department_is_not_shown(): void
    {
        $mine    = $this->employee->department_id;
        $otherDept  = ($mine ?? 0) + 99;

        DB::table('hr_holidays')->insert([
            'tenant_id' => 1, 'title' => 'Plant Shutdown', 'holiday_date' => '2026-09-22',
            'holiday_type' => 'Company', 'applicable_for' => 'Department',
            'department_id' => $otherDept, 'is_optional' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $titles = collect($this->postJson('/api/Hrm/holidays-list', ['workspace_id' => '1'])
            ->assertOk()->json('data'))->pluck('title');

        $this->assertNotContains('Plant Shutdown', $titles);
        $this->assertContains('Independence Day', $titles, 'Organisation-wide holidays must still show.');

        // Events carry the same scope rule, on their own table.
        DB::table('hr_events')->insert([
            'tenant_id' => 1, 'title' => 'Dept Only Offsite', 'start_date' => '2026-09-22',
            'color' => '#7C3AED', 'applicable_for' => 'Department', 'department_id' => $otherDept,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $calendar = collect($this->getJson('/api/Hrm/events?month=9&year=2026')->assertOk()->json('data'))->pluck('title');
        $this->assertNotContains('Dept Only Offsite', $calendar, 'The calendar must apply the same rule.');
    }

    /** Each configured type is told apart, not collapsed into a yes/no. */
    public function test_every_holiday_type_reaches_the_app_distinctly(): void
    {
        $types = ['Festival' => '2026-09-16', 'Company' => '2026-09-17'];
        foreach ($types as $type => $date) {
            DB::table('hr_holidays')->insert([
                'tenant_id' => 1, 'title' => $type.' Day', 'holiday_date' => $date,
                'holiday_type' => $type, 'applicable_for' => 'Organization',
                'is_optional' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = collect($this->postJson('/api/Hrm/holidays-list', ['workspace_id' => '1'])
            ->assertOk()->json('data'))->keyBy('title');

        $this->assertSame('national-holiday', $rows['Independence Day']['className']);
        $this->assertSame('festival-holiday', $rows['Festival Day']['className']);
        $this->assertSame('company-holiday',  $rows['Company Day']['className']);

        // The calendar endpoint is events now, not holidays, so the type lives
        // entirely in className above — there is nothing to cross-check here.
    }

    public function test_an_optional_holiday_is_distinguishable_from_a_public_one(): void
    {
        DB::table('hr_holidays')->insert([
            'tenant_id' => 1, 'title' => 'Optional Festival', 'holiday_date' => '2026-09-28',
            'holiday_type' => 'Optional', 'applicable_for' => 'Organization',
            'is_optional' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect($this->postJson('/api/Hrm/holidays-list', ['workspace_id' => '1'])
            ->assertOk()->json('data'))->keyBy('title');

        // 'national-holiday' now, not the old catch-all: the class carries the
        // configured type so the phone can tell the four kinds apart.
        $this->assertSame('national-holiday', $rows['Independence Day']['className']);
        // Exactly 'optional': the calendar compares this string directly.
        $this->assertSame('optional', $rows['Optional Festival']['className']);
    }
}
