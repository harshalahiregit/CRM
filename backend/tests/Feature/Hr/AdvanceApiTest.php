<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\AdvanceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The HTTP surface for advances.
 *
 * Two things beyond the usual gate checks. The literal routes — /advances/
 * settlements and /me/advances/outstanding — must not be captured as record ids
 * by the /{id} routes beside them, which is the trap routes/hr.php already notes
 * for sync-sangoetrack. And a bill must be reachable only through its own
 * advance.
 */
class AdvanceApiTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'adva-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    private function employee(string $code, ?User $user = null, ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code,
            'name' => 'E' . $code, 'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
            'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    /** Requester with a real manager, plus accounts and a director. */
    private function cast(): array
    {
        $managerUser = $this->user('manager@example.test');
        $manager     = $this->employee('SNE-M', $managerUser);

        $staffUser = $this->user('priya@example.test');
        $staff     = $this->employee('SNE-1', $staffUser, $manager->id);

        return [$staff, $staffUser, $managerUser,
            $this->user('accounts@example.test', 'staff', 'accounts'),
            $this->user('director@example.test', 'staff', 'director')];
    }

    private function raise(User $actor, array $extra = []): HrAdvance
    {
        Sanctum::actingAs($actor);
        $this->postJson('/api/hr/me/advances', array_merge([
            'purpose' => 'Site visit', 'amount_requested' => 20000, 'advance_type' => 'travel',
        ], $extra))->assertCreated();

        return HrAdvance::orderByDesc('id')->firstOrFail();
    }

    /* ── the gate ────────────────────────────────────────────────────── */

    public function test_a_plain_employee_cannot_list_every_advance(): void
    {
        [, $staffUser] = $this->cast();

        Sanctum::actingAs($staffUser);
        $this->getJson('/api/hr/advances')->assertStatus(403);
        $this->getJson('/api/hr/advances/settlements')->assertStatus(403);
    }

    /**
     * Being in the queue is not being on a rung. An HR user oversees advances
     * and is let through the door, then refused the rung anyway because they are
     * not this employee's manager.
     */
    public function test_hr_manage_alone_does_not_grant_a_rung(): void
    {
        [, $staffUser] = $this->cast();
        $advance = $this->raise($staffUser);

        $hr = $this->user('hr@example.test', 'staff', 'hr_executive');

        Sanctum::actingAs($hr);
        $this->getJson('/api/hr/advances')->assertOk();          // may see the queue
        $this->postJson("/api/hr/advances/{$advance->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This is waiting on the employee\'s own reporting manager.');
    }

    /**
     * An advance says what somebody is doing and how much they needed. A line
     * manager gets their own reports and nothing else.
     */
    public function test_a_manager_sees_only_their_own_reports_advances(): void
    {
        [, $staffUser, $manager] = $this->cast();
        $this->raise($staffUser);

        // Somebody who does not report to that manager.
        $strangerUser = $this->user('raj@example.test');
        $this->employee('SNE-9', $strangerUser);
        $this->raise($strangerUser);

        $this->assertSame(2, HrAdvance::count());

        Sanctum::actingAs($manager);
        $this->getJson('/api/hr/advances')->assertOk()->assertJsonCount(1, 'data');

        // Accounts oversees, so they see both.
        Sanctum::actingAs($this->user('acc2@example.test', 'staff', 'accounts'));
        $this->getJson('/api/hr/advances')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_somebody_with_no_business_here_is_refused_the_door(): void
    {
        [, $staffUser] = $this->cast();

        // A linked employee who manages nobody and holds no tier role.
        Sanctum::actingAs($staffUser);
        $this->getJson('/api/hr/advances')->assertStatus(403);
    }

    /* ── literal routes are not record ids ───────────────────────────── */

    public function test_the_settlement_queue_is_not_matched_as_an_advance_id(): void
    {
        [, , , $accounts] = $this->cast();

        Sanctum::actingAs($accounts);
        $this->getJson('/api/hr/advances/settlements')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(0, 'data');
    }

    public function test_outstanding_is_not_matched_as_an_advance_id(): void
    {
        [, $staffUser] = $this->cast();

        Sanctum::actingAs($staffUser);
        $this->getJson('/api/hr/me/advances/outstanding')
            ->assertOk()
            ->assertJsonPath('data.open_count', 0);
    }

    /* ── ownership ───────────────────────────────────────────────────── */

    public function test_an_employee_cannot_open_somebody_elses_advance(): void
    {
        [, $staffUser] = $this->cast();
        $advance = $this->raise($staffUser);

        $otherUser = $this->user('raj@example.test');
        $this->employee('SNE-2', $otherUser);

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/hr/me/advances/{$advance->id}")->assertStatus(404);
        $this->postJson("/api/hr/me/advances/{$advance->id}/cancel")->assertStatus(404);
    }

    public function test_an_internal_note_never_reaches_the_employee(): void
    {
        [, $staffUser, $manager] = $this->cast();
        $advance = $this->raise($staffUser);

        Sanctum::actingAs($manager);
        $this->postJson("/api/hr/advances/{$advance->id}/note", ['body' => 'Third trip this month.'])->assertOk();

        Sanctum::actingAs($staffUser);
        $body = json_encode($this->getJson("/api/hr/me/advances/{$advance->id}")->json('data.thread'));

        $this->assertStringNotContainsString('Third trip this month', $body, 'An internal note leaked.');
    }

    /* ── the whole thing over HTTP ───────────────────────────────────── */

    public function test_the_full_lifecycle_over_http(): void
    {
        Storage::fake('local');
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();

        // Requested, with a PDF quote attached.
        Sanctum::actingAs($staffUser);
        $this->postJson('/api/hr/me/advances', [
            'purpose' => 'Site visit to Pune', 'amount_requested' => 20000, 'advance_type' => 'travel',
            'files' => [
                UploadedFile::fake()->create('quote.pdf', 30, 'application/pdf'),
                UploadedFile::fake()->image('itinerary.jpg'),
            ],
        ])->assertCreated();

        $advance = HrAdvance::firstOrFail();
        $this->assertSame(2, $advance->attachments()->count());
        $this->assertNotNull($advance->reference, 'Every advance gets a reference.');

        // Rung one.
        Sanctum::actingAs($manager);
        $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();

        // Rung two refuses to be skipped by the director.
        Sanctum::actingAs($director);
        $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertStatus(403);

        // Accounts holds, the employee answers, accounts approves.
        Sanctum::actingAs($accounts);
        $this->postJson("/api/hr/advances/{$advance->id}/hold", ['reason' => 'Which cost centre?'])->assertOk();

        Sanctum::actingAs($staffUser);
        $this->postJson("/api/hr/me/advances/{$advance->id}/reply", ['body' => 'OPS-2.'])->assertOk();
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->fresh()->status);

        Sanctum::actingAs($accounts);
        $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();

        Sanctum::actingAs($director);
        $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();
        $this->assertSame(AdvanceStage::APPROVED, $advance->fresh()->status);

        // A transfer with no reference is refused; cash is not.
        Sanctum::actingAs($accounts);
        $this->postJson("/api/hr/advances/{$advance->id}/disburse", ['mode' => 'bank_transfer'])->assertStatus(422);
        $this->postJson("/api/hr/advances/{$advance->id}/disburse", ['mode' => 'cash'])->assertOk();

        // Settled with bills.
        Sanctum::actingAs($staffUser);
        $this->postJson("/api/hr/me/advances/{$advance->id}/settlement", [
            'actual_expense' => 14000,
            'files' => [UploadedFile::fake()->create('hotel.pdf', 20, 'application/pdf')],
        ])->assertCreated();

        $settlement = HrAdvanceSettlement::firstOrFail();
        $this->assertSame('6000.00', $settlement->balance_return);
        $this->assertSame(1, $settlement->attachments()->count());

        // Reviewed.
        Sanctum::actingAs($accounts);
        $this->postJson("/api/hr/advances/settlements/{$settlement->id}/accept")->assertOk();

        $this->assertSame(AdvanceStage::SETTLED, $advance->fresh()->status);
    }

    /* ── files ───────────────────────────────────────────────────────── */

    public function test_a_bill_is_reachable_through_its_advance_and_not_another(): void
    {
        Storage::fake('local');
        [, $staffUser, $manager, $accounts, $director] = $this->cast();

        $advance = $this->raise($staffUser);

        Sanctum::actingAs($manager);   $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();
        Sanctum::actingAs($accounts);  $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();
        Sanctum::actingAs($director);  $this->postJson("/api/hr/advances/{$advance->id}/approve")->assertOk();
        Sanctum::actingAs($accounts);  $this->postJson("/api/hr/advances/{$advance->id}/disburse", ['mode' => 'cash'])->assertOk();

        Sanctum::actingAs($staffUser);
        $this->postJson("/api/hr/me/advances/{$advance->id}/settlement", [
            'actual_expense' => 14000,
            'files' => [UploadedFile::fake()->create('hotel.pdf', 20, 'application/pdf')],
        ])->assertCreated();

        $bill = HrAdvanceSettlement::firstOrFail()->attachments()->firstOrFail();

        // Through its own advance: fine, on both sides.
        $this->get("/api/hr/me/advances/{$advance->id}/attachments/{$bill->id}")->assertOk();
        Sanctum::actingAs($accounts);
        $this->get("/api/hr/advances/{$advance->id}/attachments/{$bill->id}")->assertOk();

        // Through a different advance: not found, even for the same admin.
        $second = $this->raise($staffUser);
        Sanctum::actingAs($accounts);
        $this->get("/api/hr/advances/{$second->id}/attachments/{$bill->id}")->assertStatus(404);
    }

    public function test_an_executable_is_refused_as_a_supporting_document(): void
    {
        Storage::fake('local');
        [, $staffUser] = $this->cast();

        Sanctum::actingAs($staffUser);
        $this->postJson('/api/hr/me/advances', [
            'purpose' => 'Nope', 'amount_requested' => 100,
            'files' => [UploadedFile::fake()->create('payload.exe', 10)],
        ])->assertStatus(422);
    }
}
