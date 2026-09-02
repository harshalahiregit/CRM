<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The HTTP surface for expense claims.
 *
 * The gate tests matter most. The first draft of ReimbursementController checked
 * authorisation inside its lookup helper, which left index() — the method that
 * lists every claim in the tenant — reachable by any authenticated user, because
 * index() does not use that helper. It is a route-group middleware now, and these
 * pin it.
 */
class ReimbursementApiTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'rba-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    /** A person with a login and a linked employee record. */
    private function person(string $code, string $email, string $role = 'staff'): array
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code,
            'name' => 'Person ' . $code, 'email' => $email,
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
        ]);

        $user = app(EmployeeIdentityService::class)->provisionEmployeeFor(
            $this->user($email, $role)
        ) ? User::where('email', $email)->first() : null;

        // provisionEmployeeFor creates a NEW employee for the user; link the one
        // above instead so the fixture has exactly one employee.
        $employee->update(['user_id' => $user->id]);
        HrEmployee::where('user_id', $user->id)->where('id', '!=', $employee->id)->delete();

        return [$user, $employee->fresh()];
    }

    private function claim(HrEmployee $employee, float $amount = 5000): HrReimbursement
    {
        return HrReimbursement::create([
            'tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id,
            'title' => 'Client dinner', 'expense_date' => now()->toDateString(),
            'amount_claimed' => $amount, 'status' => ReimbursementStatus::PENDING,
        ]);
    }

    /* ── the gate ────────────────────────────────────────────────────── */

    public function test_a_plain_employee_cannot_list_every_claim(): void
    {
        [$staff] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($staff);
        $this->getJson('/api/hr/reimbursements')->assertStatus(403);
    }

    public function test_a_plain_employee_cannot_hold_or_approve(): void
    {
        [$staff, $employee] = $this->person('SNE-1', 'priya@example.test');
        $claim = $this->claim($employee);

        Sanctum::actingAs($staff);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", ['reason' => 'nope'])->assertStatus(403);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/approve")->assertStatus(403);
    }

    public function test_an_admin_can_list_and_act(): void
    {
        [, $employee] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->user('admin@example.test', 'admin');
        $claim = $this->claim($employee);

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/reimbursements')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", [
            'reason' => 'The receipt is for 2,500.', 'proposed_amount' => 2500,
        ])->assertOk();

        $this->assertSame(ReimbursementStatus::ON_HOLD, $claim->fresh()->status);
    }

    /* ── ownership ───────────────────────────────────────────────────── */

    public function test_an_employee_cannot_open_somebody_elses_claim(): void
    {
        [$mine] = $this->person('SNE-1', 'priya@example.test');
        [, $other] = $this->person('SNE-2', 'raj@example.test');
        $theirs = $this->claim($other);

        Sanctum::actingAs($mine);
        $this->getJson("/api/hr/me/reimbursements/{$theirs->id}")->assertStatus(404);
        $this->postJson("/api/hr/me/reimbursements/{$theirs->id}/reply", ['body' => 'hello'])->assertStatus(404);
    }

    /* ── the round trip ──────────────────────────────────────────────── */

    public function test_the_whole_exchange_over_http(): void
    {
        Storage::fake('local');

        [$staff, $employee] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->user('admin@example.test', 'admin');

        // Submit, with two files including a PDF.
        Sanctum::actingAs($staff);
        $this->postJson('/api/hr/me/reimbursements', [
            'title' => 'Client dinner', 'expense_date' => now()->toDateString(),
            'amount_claimed' => 5000,
            'files' => [
                UploadedFile::fake()->image('receipt.jpg'),
                UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
            ],
        ])->assertCreated();

        $claim = HrReimbursement::firstOrFail();
        $this->assertSame(2, $claim->attachments()->count(), 'Several files, including a PDF.');

        // Held with a counter-offer.
        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", [
            'reason' => 'The receipt supports 2,500.', 'proposed_amount' => 2500,
        ])->assertOk();

        // The employee sees the offer and the reason.
        Sanctum::actingAs($staff);
        $view = $this->getJson("/api/hr/me/reimbursements/{$claim->id}")->assertOk();
        $view->assertJsonPath('data.can.accept_proposal', true);

        // Accepts it.
        $this->postJson("/api/hr/me/reimbursements/{$claim->id}/accept")->assertOk();

        $claim->refresh();
        $this->assertSame(ReimbursementStatus::APPROVED, $claim->status);
        $this->assertSame('2500.00', $claim->amount_approved);
    }

    public function test_an_internal_note_never_reaches_the_employee(): void
    {
        [$staff, $employee] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->user('admin@example.test', 'admin');
        $claim = $this->claim($employee);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/note", [
            'body' => 'Third claim this month.',
        ])->assertOk();

        Sanctum::actingAs($staff);
        $body = json_encode($this->getJson("/api/hr/me/reimbursements/{$claim->id}")->json('data.thread'));

        $this->assertStringNotContainsString('Third claim this month', $body, 'An internal note leaked.');
    }

    public function test_a_hold_without_a_reason_is_rejected_by_validation(): void
    {
        [, $employee] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->user('admin@example.test', 'admin');
        $claim = $this->claim($employee);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/hold", [])->assertStatus(422);
    }

    public function test_an_executable_is_refused_as_an_attachment(): void
    {
        Storage::fake('local');
        [$staff] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($staff);
        $this->postJson('/api/hr/me/reimbursements', [
            'title' => 'Nope', 'expense_date' => now()->toDateString(), 'amount_claimed' => 100,
            'files' => [UploadedFile::fake()->create('payload.exe', 10)],
        ])->assertStatus(422);
    }
}
