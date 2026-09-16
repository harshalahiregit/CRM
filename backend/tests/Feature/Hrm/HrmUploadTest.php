<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Shared\Attachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Everything a person submits from the app can carry files, of any type.
 *
 * Each of the four had its own idea: reimbursement took an array of office
 * documents, advance took ONE file, settlement capped at ten, leave and
 * corrections took none at all. So the same medical certificate could be
 * attached to one request and not the next, and people mailed it separately for
 * HR to marry up by hand.
 */
class HrmUploadTest extends TestCase
{
    use RefreshDatabase;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'hrm-upload', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'ravi@upload.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active', 'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);
    }

    private function files(int $n): array
    {
        // Deliberately mixed and NOT the six types the old rules allowed: a bank
        // statement is a .csv and a clinic's note is often an .odt.
        $kinds = ['a.pdf', 'b.png', 'c.csv', 'd.odt', 'e.heic', 'f.txt'];

        return collect(range(0, $n - 1))
            ->map(fn ($i) => UploadedFile::fake()->create($kinds[$i % count($kinds)], 40))
            ->all();
    }

    private function attachmentCount(string $class): int
    {
        return Attachment::where('attachable_type', $class)->count();
    }

    public function test_an_expense_claim_takes_many_files_of_any_type(): void
    {
        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'Site trip', 'amount' => 500, 'expense_date' => '2026-09-01',
            'receipts' => $this->files(6),
        ])->assertOk();

        $this->assertSame(6, $this->attachmentCount(\App\Models\Hr\HrReimbursement::class));
    }

    public function test_an_advance_takes_many_files_where_it_used_to_take_one(): void
    {
        $this->postJson('/api/Hrm/advance/submit', [
            'purpose' => 'Site visit', 'amount_requested' => 5000,
            'attachments' => $this->files(4),
        ])->assertOk();

        $this->assertSame(4, $this->attachmentCount(HrAdvance::class));
    }

    /** A build already on somebody's phone posts the singular name. */
    public function test_the_old_single_file_field_still_works(): void
    {
        $this->postJson('/api/Hrm/advance/submit', [
            'purpose' => 'Site visit', 'amount_requested' => 5000,
            'attachment' => UploadedFile::fake()->create('quote.pdf', 40),
        ])->assertOk();

        $this->assertSame(1, $this->attachmentCount(HrAdvance::class));
    }

    public function test_a_leave_application_can_carry_a_medical_certificate(): void
    {
        $typeId = $this->seedLeaveType();

        $this->postJson('/api/Hrm/leave-request', [
            'leave_type_id' => $typeId, 'start_date' => '2026-09-10', 'end_date' => '2026-09-11',
            'leave_reason' => 'Fever', 'attachments' => $this->files(3),
        ])->assertOk();

        $this->assertSame(3, $this->attachmentCount(HrLeaveApplication::class));
    }

    public function test_an_attendance_correction_can_carry_its_evidence(): void
    {
        $this->postJson('/api/Hrm/submit-attendance-raise', [
            'attendance_date' => '2026-09-01', 'login_time' => '09:30',
            'logout_time' => '18:30', 'reason' => 'Gate register shows I was in',
            'attachments' => $this->files(2),
        ])->assertOk();

        $this->assertSame(2, $this->attachmentCount(HrAttendanceCorrection::class));
    }

    /** More than the old cap of ten, because a real claim sometimes is. */
    public function test_more_than_ten_files_are_accepted(): void
    {
        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'Month of travel', 'amount' => 9000, 'expense_date' => '2026-09-01',
            'receipts' => $this->files(15),
        ])->assertOk();

        $this->assertSame(15, $this->attachmentCount(\App\Models\Hr\HrReimbursement::class));
    }

    /**
     * The ceiling exists so one request cannot exhaust the server, and it is
     * refused rather than silently truncated — twenty-six receipts arriving as
     * twenty-five is a missing receipt nobody notices.
     */
    public function test_an_absurd_number_is_refused_not_truncated(): void
    {
        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'x', 'amount' => 1, 'expense_date' => '2026-09-01',
            'receipts' => $this->files(30),
        ])->assertOk()->assertJsonPath('status', 0);

        $this->assertSame(0, $this->attachmentCount(\App\Models\Hr\HrReimbursement::class));
    }

    /** Executables are refused wherever they are attached, by the store itself. */
    public function test_an_executable_is_still_refused(): void
    {
        $r = $this->postJson('/api/Hrm/advance/submit', [
            'purpose' => 'x', 'amount_requested' => 100,
            'attachments' => [UploadedFile::fake()->create('payload.exe', 20)],
        ]);

        $this->assertSame(0, $this->attachmentCount(HrAdvance::class), 'An .exe was stored.');
        $this->assertNotSame(200, $r->status() === 200 ? (int) $r->json('status') : 999);
    }

    /** A type, a policy and a balance — applying needs all three. */
    private function seedLeaveType(): int
    {
        $tenantId = (int) $this->employee->tenant_id;

        $type = \App\Models\Hr\HrLeaveType::create([
            'tenant_id' => $tenantId, 'name' => 'Sick', 'code' => 'SL',
            'category' => 'Sick', 'paid' => true, 'yearly_limit' => 12,
            'requires_approval' => true, 'is_active' => true,
        ]);

        $policy = \App\Models\Hr\HrLeavePolicy::create([
            'tenant_id' => $tenantId, 'name' => 'Standard', 'applies_to' => 'All',
            'weekends_count' => true, 'holidays_count' => false, 'half_day_allowed' => true,
            'negative_balance_allowed' => false, 'is_active' => true,
        ]);

        \App\Models\Hr\HrEmployeeLeaveBalance::create([
            'tenant_id' => $tenantId, 'employee_id' => $this->employee->id,
            'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'allocated' => 12, 'opening_balance' => 12, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 12,
            'effective_from' => '2026-01-01',
            'status' => \App\Models\Hr\HrEmployeeLeaveBalance::ACTIVE,
        ]);

        return $type->id;
    }
}
