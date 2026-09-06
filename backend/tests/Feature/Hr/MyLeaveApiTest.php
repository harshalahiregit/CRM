<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An employee applying for their own leave.
 *
 * The existing leave endpoints are HR's — they take an employee_id from the body
 * and are gated on managing the queue — so there was no way to apply for your
 * own leave in the CRM at all. It only existed in the app, against SangoeTrack.
 *
 * The test that matters most is that a posted employee_id is IGNORED rather than
 * honoured: it is the whole security property of every `me` controller here, and
 * on this endpoint getting it wrong would let anybody book leave against a
 * colleague.
 */
class MyLeaveApiTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'mylv-t', 'status' => 'active']);
    }

    private function person(string $code, string $email): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'U'.$code, 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    /** A leave type, a policy and an active balance to apply against. */
    private function withBalance(HrEmployee $employee): HrLeaveType
    {
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Casual '.$employee->employee_code,
            'code' => 'CL'.$employee->id, 'category' => 'Casual', 'paid' => true,
            'yearly_limit' => 24, 'requires_approval' => true, 'is_active' => true,
        ]);

        $policy = HrLeavePolicy::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Standard '.$employee->employee_code,
            'applies_to' => 'All', 'weekends_count' => true, 'holidays_count' => false,
            'half_day_allowed' => true, 'negative_balance_allowed' => false, 'is_active' => true,
        ]);

        HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id,
            'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'allocated' => 24, 'opening_balance' => 24, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 24,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        return $type;
    }

    /* ── the security property ───────────────────────────────────────── */

    /**
     * A posted employee_id must not be honoured.
     *
     * Without this, anybody could book leave against a colleague — the HR
     * endpoint takes exactly that field, and copying its shape here would have
     * carried the hole across.
     */
    public function test_a_posted_employee_id_is_ignored(): void
    {
        [$mine, $me]     = $this->person('SNE-1', 'priya@example.test');
        [, $victim]      = $this->person('SNE-2', 'raj@example.test');
        $type = $this->withBalance($me);
        $this->withBalance($victim);

        Sanctum::actingAs($mine);
        $this->postJson('/api/hr/me/leave', [
            'employee_id'   => $victim->id,          // ignored, not validated
            'leave_type_id' => $type->id,
            'from_date'     => '2026-03-02',
            'to_date'       => '2026-03-03',
            'reason'        => 'Family function',
        ])->assertCreated();

        $this->assertSame(1, HrLeaveApplication::where('employee_id', $me->id)->count());
        $this->assertSame(0, HrLeaveApplication::where('employee_id', $victim->id)->count(),
            'Leave must never land on somebody else.');
    }

    public function test_an_unlinked_login_is_refused(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'NoEmp', 'email' => 'no@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/hr/me/leave')->assertStatus(403);
        $this->getJson('/api/hr/me/leave/balances')->assertStatus(403);
    }

    public function test_an_employee_cannot_open_or_cancel_somebody_elses_leave(): void
    {
        [$mine, $me]   = $this->person('SNE-1', 'priya@example.test');
        [$other, $them] = $this->person('SNE-2', 'raj@example.test');
        $this->withBalance($me);
        $type = $this->withBalance($them);

        Sanctum::actingAs($other);
        $this->postJson('/api/hr/me/leave', [
            'leave_type_id' => $type->id, 'from_date' => '2026-03-02', 'to_date' => '2026-03-02',
        ])->assertCreated();

        $theirs = HrLeaveApplication::firstOrFail();

        Sanctum::actingAs($mine);
        $this->getJson("/api/hr/me/leave/{$theirs->id}")->assertStatus(404);
        $this->patchJson("/api/hr/me/leave/{$theirs->id}/cancel")->assertStatus(404);
    }

    /* ── literal routes are not record ids ───────────────────────────── */

    public function test_balances_and_preview_are_not_matched_as_ids(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $type = $this->withBalance($me);

        Sanctum::actingAs($user);
        $this->getJson('/api/hr/me/leave/balances')->assertOk();

        $this->postJson('/api/hr/me/leave/preview', [
            'from_date' => '2026-03-02', 'to_date' => '2026-03-04', 'leave_type_id' => $type->id,
        ])->assertOk();
    }

    /* ── the round trip ──────────────────────────────────────────────── */

    public function test_apply_list_and_withdraw(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $type = $this->withBalance($me);

        Sanctum::actingAs($user);

        // The preview must agree with what the server will charge.
        $preview = $this->postJson('/api/hr/me/leave/preview', [
            'from_date' => '2026-03-02', 'to_date' => '2026-03-04', 'leave_type_id' => $type->id,
        ])->assertOk()->json('data');
        $this->assertNotEmpty($preview);

        $this->postJson('/api/hr/me/leave', [
            'leave_type_id' => $type->id, 'from_date' => '2026-03-02', 'to_date' => '2026-03-04',
            'reason' => 'Family function',
        ])->assertCreated();

        $this->getJson('/api/hr/me/leave')->assertOk()->assertJsonCount(1, 'data');

        $app = HrLeaveApplication::firstOrFail();
        // Applying from a phone means applying — not saving a draft nobody submits.
        $this->assertSame('Submitted', $app->status);

        $this->patchJson("/api/hr/me/leave/{$app->id}/cancel")->assertOk();
        $this->assertNotSame('Submitted', $app->fresh()->status);
    }

    public function test_leave_with_no_assigned_policy_is_refused_with_a_reason(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');

        // A type exists, but this employee has no balance against it.
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Casual', 'paid' => true, 'yearly_limit' => 12,
            'requires_approval' => true, 'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $r = $this->postJson('/api/hr/me/leave', [
            'leave_type_id' => $type->id, 'from_date' => '2026-03-02', 'to_date' => '2026-03-02',
        ]);

        $this->assertContains($r->status(), [400, 422]);
        $this->assertStringContainsString('policy', strtolower((string) $r->json('message')));
    }

    public function test_a_backwards_range_is_refused(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $type = $this->withBalance($me);

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/leave', [
            'leave_type_id' => $type->id, 'from_date' => '2026-03-10', 'to_date' => '2026-03-02',
        ])->assertStatus(422);
    }

    public function test_an_executable_is_refused_as_a_document(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $type = $this->withBalance($me);

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/leave', [
            'leave_type_id' => $type->id, 'from_date' => '2026-03-02', 'to_date' => '2026-03-02',
            'attachment' => \Illuminate\Http\UploadedFile::fake()->create('payload.exe', 10),
        ])->assertStatus(422);
    }
}
