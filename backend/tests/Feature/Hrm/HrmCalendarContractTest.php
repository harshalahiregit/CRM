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

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'cal-t', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        HrEmployee::create([
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

        $this->assertSame('public-holiday', $rows['Independence Day']['className']);
        $this->assertSame('optional-holiday', $rows['Optional Festival']['className']);
    }
}
