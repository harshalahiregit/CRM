<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\ReimbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every My Services request tells the employee what happened to it.
 *
 * None of these four flows notified anybody. Somebody submitted an advance and
 * heard nothing; it was approved and they heard nothing; the money was paid and
 * they heard nothing. The only way to find out was to open the app and go
 * looking — which is the exact thing a notification exists to save them from.
 *
 * What is asserted is the IN-APP row, because that is what the push carries the
 * id of and what the phone opens when somebody taps it. Delivery to Google is a
 * separate concern with its own tests; a row that never exists cannot be
 * delivered at all.
 */
class RequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private HrEmployee $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'req-notify', 'status' => 'active']);

        $staff = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'ravi@notify.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->approver = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@notify.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $staff->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Notification> */
    private function notifications()
    {
        return Notification::where('user_id', $this->employee->user_id)->get();
    }

    public function test_requesting_an_advance_tells_the_employee(): void
    {
        app(AdvanceService::class)->request($this->employee, [
            'purpose' => 'Site trip', 'amount_requested' => 5000,
        ], $this->approver);

        $n = $this->notifications();

        $this->assertCount(1, $n, 'Submitting an advance notified nobody.');
        $this->assertStringContainsString('Advance', $n->first()->title);
        $this->assertSame('advance', $n->first()->type, 'The type is what the app opens on tap.');
    }

    public function test_an_expense_claim_tells_the_employee_at_each_step(): void
    {
        $claim = app(ReimbursementService::class)->submit($this->employee, [
            'title' => 'Taxi', 'amount_claimed' => 500, 'expense_date' => '2026-09-01',
        ], $this->approver);

        $this->assertCount(1, $this->notifications(), 'Submitting told nobody.');

        app(ReimbursementService::class)->approve($claim, $this->approver);

        $n = $this->notifications();
        $this->assertCount(2, $n, 'Approving told nobody.');
        $this->assertStringContainsString('approved', strtolower($n->last()->title.' '.$n->last()->message));
    }

    public function test_a_declined_claim_says_why(): void
    {
        $claim = app(ReimbursementService::class)->submit($this->employee, [
            'title' => 'Taxi', 'amount_claimed' => 500, 'expense_date' => '2026-09-01',
        ], $this->approver);

        app(ReimbursementService::class)->decline($claim, $this->approver, 'No receipt attached');

        $last = $this->notifications()->last();
        $this->assertStringContainsString('No receipt attached', $last->message,
            'A rejection without the reason sends somebody to ask why.');
    }

    public function test_an_attendance_correction_tells_the_employee(): void
    {
        $c = app(AttendanceCorrectionService::class)->request($this->employee, [
            'attendance_date' => '2026-09-01', 'requested_check_in' => '09:30',
            'requested_check_out' => '18:30', 'reason' => 'Gate register shows I was in',
        ], $this->approver);

        $this->assertCount(1, $this->notifications());

        app(AttendanceCorrectionService::class)->reject($c, $this->approver, 'Register does not show it');

        $last = $this->notifications()->last();
        $this->assertStringContainsString('Register does not show it', $last->message);
        $this->assertSame('attendance', $last->type);
    }

    /**
     * An employee with no login is not an error.
     *
     * Plenty of people are on the payroll and not on the app; a notifier that
     * threw for them would break the approval it was announcing.
     */
    public function test_an_employee_without_a_login_is_skipped_quietly(): void
    {
        $noLogin = HrEmployee::create([
            'tenant_id' => $this->employee->tenant_id, 'employee_code' => 'E2', 'name' => 'No Login',
            'department' => 'Ops', 'designation' => 'Helper', 'joining_date' => '2020-01-01',
            'status' => 'Active',
        ]);

        $advance = app(AdvanceService::class)->request($noLogin, [
            'purpose' => 'x', 'amount_requested' => 100,
        ], $this->approver);

        $this->assertNotNull($advance->id, 'The request itself must still succeed.');
        $this->assertSame(0, Notification::count());
    }
}
