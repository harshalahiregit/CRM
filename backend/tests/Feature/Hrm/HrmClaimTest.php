<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\ReimbursementService;
use App\Support\Hr\AdvanceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense claims and advances, in the app's shapes.
 *
 * Their AdvanceRequestData reads twenty-five fields, several computed rather
 * than stored. Every one is asserted, because an absent key renders blank and a
 * blank status label reads as "no status" rather than "unknown".
 */
class HrmClaimTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrmc-t', 'status' => 'active']);
    }

    private function person(string $code = 'SNE-1', string $email = 'priya@example.test', ?int $mgr = null): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'P'.$code, 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code, 'name' => 'P'.$code,
            'department' => 'Operations', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
            'reporting_manager_id' => $mgr,
        ]);

        return [$user, $employee];
    }

    /* ── reimbursements ──────────────────────────────────────────────── */

    public function test_a_claim_round_trips_with_every_field(): void
    {
        Storage::fake('local');
        [$user, $e] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'Client dinner', 'amount' => '5000', 'expense_date' => '2026-03-02',
            'description' => 'With the Pune team',
            'receipt' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertOk()->assertJsonPath('status', 1);

        $row = $this->postJson('/api/Hrm/get-reimbursements', [])->assertOk()->json('data.0');

        foreach (['id', 'title', 'description', 'amount', 'expense_date', 'receipt', 'status', 'admin_remarks', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "claim.{$k} is missing.");
        }

        $this->assertSame('Client dinner', $row['title']);
        $this->assertSame('5000', $row['amount']);
        // A signed URL, because the app hands this to an image widget.
        $this->assertStringContainsString('signature=', $row['receipt']);
    }

    public function test_the_receipt_url_opens_without_a_token(): void
    {
        Storage::fake('local');
        [$user] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'Dinner', 'amount' => '100', 'expense_date' => '2026-03-02',
            'receipt' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertOk();

        $url = $this->postJson('/api/Hrm/get-reimbursements', [])->json('data.0.receipt');

        // No Authorization header at all — an image widget sends none.
        $this->flushHeaders();
        $this->get($url)->assertOk();
    }

    public function test_a_tampered_file_url_is_refused(): void
    {
        Storage::fake('local');
        [$user] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/Hrm/submit-reimbursement', [
            'title' => 'Dinner', 'amount' => '100', 'expense_date' => '2026-03-02',
            'receipt' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertOk();

        $url = $this->postJson('/api/Hrm/get-reimbursements', [])->json('data.0.receipt');

        $this->flushHeaders();
        $this->get(str_replace('signature=', 'signature=00', $url))->assertStatus(403);
    }

    public function test_a_claim_never_shows_another_employees(): void
    {
        [$mine] = $this->person();
        [$otherUser, $other] = $this->person('SNE-2', 'raj@example.test');

        app(ReimbursementService::class)->submit($other, [
            'title' => 'Theirs', 'expense_date' => '2026-03-02', 'amount_claimed' => 900,
        ], $otherUser);

        Sanctum::actingAs($mine);
        $this->postJson('/api/Hrm/get-reimbursements', [])->assertOk()->assertJsonCount(0, 'data');
    }

    /** on_hold is a CRM state the app has no word for; it still reads as waiting. */
    public function test_a_held_claim_reads_as_pending_and_carries_the_reason(): void
    {
        [$user, $e] = $this->person();
        $admin = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'A', 'email' => 'a@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        $claim = app(ReimbursementService::class)->submit($e, [
            'title' => 'Dinner', 'expense_date' => '2026-03-02', 'amount_claimed' => 5000,
        ], $user);
        app(ReimbursementService::class)->hold($claim, $admin, 'The receipt supports 2,500.');

        Sanctum::actingAs($user);
        $row = $this->postJson('/api/Hrm/get-reimbursements', [])->assertOk()->json('data.0');

        $this->assertSame('pending', $row['status']);
        $this->assertStringContainsString('2,500', $row['admin_remarks'], 'The employee must see what was asked.');
    }

    /* ── advances ────────────────────────────────────────────────────── */

    public function test_an_advance_carries_all_twenty_five_fields(): void
    {
        Storage::fake('local');
        [$user] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/Hrm/advance/submit', [
            'advance_type' => 'travel', 'category' => 'Site expense', 'purpose' => 'Pune site visit',
            'amount_requested' => '20000', 'required_date' => '2026-03-05',
            'expected_settlement_date' => '2026-03-20', 'project_site' => 'Pune',
            'attachment' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ])->assertOk()->assertJsonPath('status', 1);

        $row = $this->postJson('/api/Hrm/advance/my-requests', [])->assertOk()->json('data.0');

        foreach ([
            'id', 'advance_id', 'advance_type', 'advance_type_label', 'category', 'department',
            'project_site', 'purpose', 'amount_requested', 'amount_approved', 'amount_modified',
            'amount_modified_reason', 'required_date', 'expected_settlement_date', 'attachment',
            'status', 'rejection_reason', 'has_disbursement', 'disbursed_on', 'payment_mode',
            'utr_reference', 'settlement_status', 'can_upload_settlement', 'settlement', 'ledger', 'created_at',
        ] as $k) {
            $this->assertArrayHasKey($k, $row, "advance.{$k} is missing.");
        }

        $this->assertSame('Travel', $row['advance_type_label']);
        $this->assertSame('Operations', $row['department'], 'Department comes from the employee record.');
        $this->assertFalse($row['has_disbursement']);
        $this->assertFalse($row['can_upload_settlement']);
        $this->assertNull($row['settlement']);
    }

    /** The whole life of an advance, as the app would see it. */
    public function test_the_full_advance_lifecycle_through_the_app(): void
    {
        [$mgrUser, $mgr] = $this->person('SNE-M', 'mgr@example.test');
        [$user, $e]      = $this->person('SNE-1', 'priya@example.test', $mgr->id);
        $accounts = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Acc', 'email' => 'acc@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'internal_role' => 'accounts',
        ]);
        $director = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Dir', 'email' => 'dir@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'internal_role' => 'director',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/Hrm/advance/submit', [
            'purpose' => 'Site visit', 'amount_requested' => '20000',
        ])->assertOk();

        $advance = HrAdvance::firstOrFail();
        $svc = app(AdvanceService::class);
        $svc->approve($advance, $mgrUser);
        $svc->approve($advance->fresh(), $accounts);
        $svc->approve($advance->fresh(), $director);
        $svc->disburse($advance->fresh(), $accounts, 'upi', 'UPI-99');

        Sanctum::actingAs($user);
        $row = $this->postJson('/api/Hrm/advance/detail', ['advance_id' => $advance->id])
            ->assertOk()->json('data');

        $this->assertTrue($row['has_disbursement']);
        $this->assertSame('upi', $row['payment_mode']);
        $this->assertSame('UPI-99', $row['utr_reference']);
        $this->assertTrue($row['can_upload_settlement'], 'Disbursed means a settlement may be uploaded.');

        // The ledger is built from the facts, so it cannot disagree with them.
        $this->assertNotEmpty($row['ledger']);
        foreach (['entry_date', 'entry_type', 'entry_type_label', 'debit', 'credit', 'running_balance', 'description'] as $k) {
            $this->assertArrayHasKey($k, $row['ledger'][0], "ledger.{$k} is missing.");
        }
        $this->assertSame('20000', $row['ledger'][0]['running_balance']);

        // Settle it.
        Storage::fake('local');
        $this->postJson('/api/Hrm/advance/submit-settlement', [
            'advance_id' => $advance->id, 'actual_expense' => '14000',
            'settlement_notes' => 'Hotel and travel',
            'bills' => [UploadedFile::fake()->create('hotel.pdf', 20, 'application/pdf')],
        ])->assertOk()->assertJsonPath('status', 1);

        $s = HrAdvanceSettlement::firstOrFail();
        $this->assertSame(1, $s->attachments()->count());

        $row = $this->postJson('/api/Hrm/advance/detail', ['advance_id' => $advance->id])->json('data');

        foreach (['actual_expense', 'balance_return', 'settlement_case', 'case_label', 'status',
                  'reviewer_remarks', 'extra_reimbursement_amount'] as $k) {
            $this->assertArrayHasKey($k, $row['settlement'], "settlement.{$k} is missing.");
        }
        $this->assertSame('less_spent', $row['settlement']['settlement_case']);
        $this->assertSame('6000', $row['settlement']['balance_return']);
        $this->assertFalse($row['can_upload_settlement'], 'One under review means no second upload.');
    }

    public function test_the_payroll_summary_shape(): void
    {
        [$mgrUser, $mgr] = $this->person('SNE-M', 'mgr@example.test');
        [$user, $e]      = $this->person('SNE-1', 'priya@example.test', $mgr->id);
        $acc = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Acc', 'email' => 'acc@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'internal_role' => 'accounts',
        ]);
        $dir = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'D', 'email' => 'd@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'internal_role' => 'director',
        ]);

        $svc = app(AdvanceService::class);
        $a = $svc->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $user);
        $svc->approve($a, $mgrUser);
        $svc->approve($a->fresh(), $acc);
        $svc->approve($a->fresh(), $dir);
        $svc->disburse($a->fresh(), $acc, 'cash', null);

        Sanctum::actingAs($user);
        $d = $this->postJson('/api/Hrm/advance/payroll-summary', [])->assertOk()->json('data');

        foreach (['total_outstanding', 'has_active', 'items'] as $k) {
            $this->assertArrayHasKey($k, $d, "summary.{$k} is missing.");
        }
        $this->assertTrue($d['has_active']);
        $this->assertSame('20000', $d['total_outstanding']);

        foreach (['advance_id', 'advance_type', 'status', 'amount_requested', 'amount_approved',
                  'amount_modified', 'modified_reason', 'settlement_case', 'balance_return',
                  'extra_reimbursement', 'payroll_label'] as $k) {
            $this->assertArrayHasKey($k, $d['items'][0], "item.{$k} is missing.");
        }
        $this->assertSame('Outstanding 20000', $d['items'][0]['payroll_label']);
    }

    /** A changed amount shows only when it actually changed. */
    public function test_amount_modified_is_empty_when_nothing_changed(): void
    {
        [$mgrUser, $mgr] = $this->person('SNE-M', 'mgr@example.test');
        [$user, $e]      = $this->person('SNE-1', 'priya@example.test', $mgr->id);

        $svc = app(AdvanceService::class);
        $a = $svc->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $user);
        $svc->approve($a, $mgrUser);

        Sanctum::actingAs($user);
        $row = $this->postJson('/api/Hrm/advance/my-requests', [])->json('data.0');

        $this->assertSame('', $row['amount_modified']);
        // NULL, not '': the app declares amountModifiedReason as String? and hides
        // the row with `!= null`. An empty string passes that guard and renders a
        // labelled "Modification Reason" row with nothing after it.
        $this->assertNull($row['amount_modified_reason']);
    }

    public function test_a_changed_amount_and_its_reason_reach_the_app(): void
    {
        [$mgrUser, $mgr] = $this->person('SNE-M', 'mgr@example.test');
        [$user, $e]      = $this->person('SNE-1', 'priya@example.test', $mgr->id);

        $svc = app(AdvanceService::class);
        $a = $svc->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $user);
        $svc->approve($a, $mgrUser, 15000, 'Budget only covers 15,000.');

        Sanctum::actingAs($user);
        $row = $this->postJson('/api/Hrm/advance/my-requests', [])->json('data.0');

        $this->assertSame('15000', $row['amount_modified']);
        $this->assertSame('Budget only covers 15,000.', $row['amount_modified_reason']);
    }

    public function test_another_employees_advance_is_not_reachable_by_id(): void
    {
        [$mine] = $this->person();
        [$otherUser, $other] = $this->person('SNE-2', 'raj@example.test');

        $a = app(AdvanceService::class)->request($other, ['purpose' => 'Theirs', 'amount_requested' => 500], $otherUser);

        Sanctum::actingAs($mine);
        $r = $this->postJson('/api/Hrm/advance/detail', ['advance_id' => $a->id])->assertOk();

        $this->assertSame(0, $r->json('status'));
    }

    public function test_a_refusal_is_200_with_status_zero(): void
    {
        [$user, $e] = $this->person();

        // Settling something never disbursed.
        $a = app(AdvanceService::class)->request($e, ['purpose' => 'X', 'amount_requested' => 500], $user);

        Sanctum::actingAs($user);
        $r = $this->postJson('/api/Hrm/advance/submit-settlement', [
            'advance_id' => $a->id, 'actual_expense' => '100',
        ])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertNotEmpty($r->json('message'));
    }
}
