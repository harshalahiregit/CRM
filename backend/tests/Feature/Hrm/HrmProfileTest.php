<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrDemoRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Profile, salary, calendar, notifications and the open endpoints. */
class HrmProfileTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrmp-t', 'status' => 'active']);
    }

    private function person(): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'phone' => '9876543210', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Operations', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        return [$user, $employee];
    }

    /* ── profile ─────────────────────────────────────────────────────── */

    public function test_editing_a_profile_returns_the_fields_the_app_reads(): void
    {
        [$user, $e] = $this->person();

        $r = $this->postJson('/api/Hrm/edit-profile', [
            'name' => 'Priya Sharma', 'email' => 'priya.s@example.test', 'mobile_no' => '9000000000',
        ])->assertOk();

        foreach (['name', 'email', 'mobile_no', 'avatar'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data'), "profile.{$k} is missing.");
        }

        // The employee record must not drift from the login.
        $this->assertSame('Priya Sharma', $e->fresh()->name);
    }

    public function test_an_email_already_taken_is_refused(): void
    {
        $this->person();
        User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Other', 'email' => 'taken@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        // 200 with status 0, not 422: the app discards the body of a non-200, so
        // a 422 told somebody "Validation failed" and never named the address.
        $r = $this->postJson('/api/Hrm/edit-profile', ['email' => 'taken@example.test'])->assertOk();
        $this->assertSame(0, $r->json('status'));
        $this->assertArrayHasKey('email', $r->json('errors'));
    }

    /** Deleting deactivates: the history refers to this person. */
    public function test_deleting_an_account_deactivates_rather_than_erases(): void
    {
        [$user, $e] = $this->person();

        $this->postJson('/api/Hrm/delete-account', [])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertFalse((bool) $e->fresh()->app_login_enabled);
        // The person still exists, so nothing that references them is orphaned.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('hr_employees', ['id' => $e->id]);
    }

    /* ── salary ──────────────────────────────────────────────────────── */

    public function test_salary_sends_all_twenty_three_keys_even_where_payroll_is_absent(): void
    {
        [, $e] = $this->person();

        DB::table('hr_employee_salaries')->insert([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id,
            'effective_from' => '2026-01-01', 'monthly_ctc' => 50000, 'net_salary' => 46000,
            'total_deductions' => 4000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $d = $this->postJson('/api/Hrm/salary-details', [])->assertOk()->json('data');

        foreach ([
            'employee_name', 'employee_id', 'designation', 'department', 'salary_type', 'base_salary',
            'allowances', 'commissions', 'loans', 'deductions', 'other_payments', 'overtimes',
            'reimbursements', 'total_allowances', 'total_commissions', 'total_deductions',
            'total_other_payments', 'total_overtime_pay', 'total_reimbursements',
            'advance_deduction', 'advance_addition', 'advance_history', 'net_salary',
        ] as $k) {
            $this->assertArrayHasKey($k, $d, "salary.{$k} is missing.");
        }

        // Payroll is not built: empty ARRAYS, because the app iterates them.
        foreach (['allowances', 'commissions', 'loans', 'deductions', 'other_payments', 'overtimes'] as $k) {
            $this->assertIsArray($d[$k], "salary.{$k} must be an array the app can loop.");
        }

        $this->assertSame('50000', $d['base_salary']);
        $this->assertSame('46000', $d['net_salary']);
        $this->assertSame('Operations', $d['department']);
    }

    public function test_salary_works_when_no_salary_is_recorded(): void
    {
        $this->person();

        $d = $this->postJson('/api/Hrm/salary-details', [])->assertOk()->json('data');

        // Still every key, still arrays — a screen with nothing on it, not a crash.
        $this->assertSame('0', $d['base_salary']);
        $this->assertIsArray($d['advance_history']);
    }

    /* ── calendar ────────────────────────────────────────────────────── */

    public function test_events_use_their_shape_and_are_a_GET(): void
    {
        $this->person();

        // An EVENT, not a holiday. This endpoint used to answer from hr_holidays
        // because there was nowhere else to read from, which put every holiday
        // in both of the app's lists. Holidays are /holidays-list now.
        DB::table('hr_events')->insert([
            'tenant_id' => $this->tenant()->id, 'title' => 'Annual Offsite',
            'description' => 'Two days away', 'start_date' => '2026-03-10',
            'end_date' => '2026-03-11', 'color' => '#10b981',
            'applicable_for' => 'Organization', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->getJson('/api/Hrm/events?month=3&year=2026')->assertOk()->json('data.0');

        foreach (['id', 'title', 'start_date', 'end_date', 'color', 'description'] as $k) {
            $this->assertArrayHasKey($k, $row, "event.{$k} is missing.");
        }
    }

    /* ── notifications ───────────────────────────────────────────────── */

    public function test_notifications_shape_and_paging(): void
    {
        [$user] = $this->person();

        foreach (range(1, 25) as $i) {
            Notification::create([
                'tenant_id' => $this->tenant()->id, 'user_id' => $user->id,
                'type' => 'leave', 'title' => "N{$i}", 'message' => 'Body',
            ]);
        }

        $body = $this->postJson('/api/Hrm/notifications', ['page' => 1, 'per_page' => 20])
            ->assertOk()->json();

        // `data` is the LIST, with the counts BESIDE it — not nested inside.
        // The app does `res['data'] as List` and reads res['unread_count']
        // separately, so nesting them together threw
        // "_Map<String, dynamic> is not a subtype of List<dynamic>" and the
        // notifications screen died the moment it opened.
        $this->assertIsList($body['data'], 'data must be the list itself.');
        foreach (['unread_count', 'has_more'] as $k) {
            $this->assertArrayHasKey($k, $body, "{$k} must sit beside data, not inside it.");
        }
        $this->assertCount(20, $body['data']);
        $this->assertTrue($body['has_more']);
        $this->assertSame(25, $body['unread_count']);

        foreach (['id', 'type', 'title', 'body', 'is_read', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $body['data'][0], "notification.{$k} is missing.");
        }
        // Their model declares a non-nullable int; a null here crashes the app.
        $this->assertIsInt($body['data'][0]['id']);
        $this->assertIsBool($body['data'][0]['is_read']);
    }

    public function test_marking_read_all_and_by_id(): void
    {
        [$user] = $this->person();

        $a = Notification::create(['tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'type' => 't', 'title' => 'A']);
        Notification::create(['tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'type' => 't', 'title' => 'B']);

        $this->postJson('/api/Hrm/notifications/mark-read', ['ids' => [$a->id]])->assertOk();
        $this->assertNotNull($a->fresh()->read_at);
        $this->assertSame(1, $this->postJson('/api/Hrm/notifications', [])->json('unread_count'));

        // No ids means all of them.
        $this->postJson('/api/Hrm/notifications/mark-read', [])->assertOk();
        $this->assertSame(0, $this->postJson('/api/Hrm/notifications', [])->json('unread_count'));

        // mark-read answers with the new count too, so the bell badge updates
        // without a second round trip — the app reads it straight off that reply.
        $this->assertSame(0, $this->postJson('/api/Hrm/notifications/mark-read', [])->json('unread_count'));
    }

    public function test_notifications_never_show_another_users(): void
    {
        [$user] = $this->person();
        $other = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Raj', 'email' => 'raj@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        Notification::create(['tenant_id' => $this->tenant()->id, 'user_id' => $other->id, 'type' => 't', 'title' => 'Theirs']);

        $this->postJson('/api/Hrm/notifications', [])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_preferences_read_defaults_then_save(): void
    {
        $this->person();

        $d = $this->postJson('/api/Hrm/notification-preferences', [])->assertOk()->json('data');

        foreach (['push_enabled', 'email_enabled', 'whatsapp_enabled', 'notify_leave',
                  'notify_reimbursement', 'notify_attendance_raise', 'notify_clock_reminder', 'notify_advance'] as $k) {
            $this->assertArrayHasKey($k, $d, "pref.{$k} is missing.");
            $this->assertIsBool($d[$k], "pref.{$k} must be a bool.");
        }
        $this->assertTrue($d['push_enabled']);

        $saved = $this->postJson('/api/Hrm/notification-preferences', ['push_enabled' => false])
            ->assertOk()->json('data');

        $this->assertFalse($saved['push_enabled']);
        // Reading again must not reset it.
        $this->assertFalse($this->postJson('/api/Hrm/notification-preferences', [])->json('data.push_enabled'));
    }

    public function test_an_fcm_token_is_stored(): void
    {
        [$user] = $this->person();

        $this->postJson('/api/Hrm/fcm-token', ['fcm_token' => 'abc123'])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('abc123', $user->fresh()->meta['fcm_token']);
    }

    /* ── open endpoints ──────────────────────────────────────────────── */

    /** Saying whether an address is registered discloses who works here. */
    public function test_forgot_password_says_the_same_thing_either_way(): void
    {
        $this->person();
        $this->flushHeaders();

        $known   = $this->postJson('/api/Hrm/forgot-password', ['email' => 'priya@example.test'])->assertOk();
        $unknown = $this->postJson('/api/Hrm/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

        $this->assertSame(1, $known->json('status'));
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_demo_request_needs_no_token(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/Hrm/demo-request', [
            'name' => 'Priya', 'company_name' => 'Acme', 'email' => 'p@acme.test',
            'num_employees' => 50, 'message' => 'Interested',
        ])->assertOk()->assertJsonPath('status', 1);

        $row = HrDemoRequest::firstOrFail();
        $this->assertSame('app', $row->source);
        $this->assertSame('new', $row->status);
        $this->assertNull($row->tenant_id, 'An enquiry arrives before anybody owns it.');
    }
}
