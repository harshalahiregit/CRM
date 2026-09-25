<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrManpowerRequest;
use App\Models\Hr\HrShift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\OrganizationService;
use App\Support\Hr\ManpowerRequestStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recruitment reads the shift master instead of its own copy of one.
 *
 * `hr_shifts` has had a full editor behind HR Settings since the shift module
 * landed, and recruitment ignored it. The dropdown was fed a four-value
 * constant, and — the part that made it a bug rather than an inconsistency —
 * the manpower validation rule hardcoded the same four strings inline. So a
 * shift somebody configured in Settings was not offered, and could not have
 * been saved if it had been: the field would have been refused with a 422.
 *
 * There were four copies of that vocabulary: the service constant, the
 * validation rule, a second constant in the React page, and the real master.
 * One resolver now answers for all of them, so the dropdown cannot offer a
 * value the validator refuses.
 *
 * Deliberately not done: no backfill and no invalidation. Requisitions already
 * holding 'Day' keep it, stay displayable and stay editable — the tenant-scoped
 * rule governs a shift being CHANGED, not one being carried past.
 *
 * Attendance is untouched. It already honours tenant shifts through
 * AttendanceService::applyAssignedShift(); HrAttendance::SHIFTS is a separate
 * preset map (start/end/grace) for a different purpose and is not merged here.
 */
class RecruitmentShiftVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT => 't1', self::OTHER => 't2'] as $id => $slug) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => strtoupper($slug), 'slug' => $slug,
                'subdomain' => $slug, 'status' => 'active',
            ])->save();
        }

        $this->actor = $this->user(self::TENANT);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(int $tenantId): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'HR', 'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function shift(int $tenantId, string $name, bool $active = true): HrShift
    {
        return HrShift::create([
            'tenant_id' => $tenantId, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).rand(10, 99),
            'shift_type' => HrShift::FIXED, 'is_active' => $active,
            'full_day_hours' => 9, 'half_day_hours' => 4.5,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'department' => 'Engineering', 'position_title' => 'Engineer',
            'number_of_posts' => 1, 'priority' => 'Medium', 'job_type' => 'Full-time',
        ], $overrides);
    }

    private function existingRequest(array $attrs = []): HrManpowerRequest
    {
        return HrManpowerRequest::create(array_merge([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'Engineer', 'position' => 'Engineer',
            'number_of_positions' => 1, 'status' => Status::DRAFT,
            'l1_status' => 'pending', 'l2_status' => 'pending',
            'requested_by' => $this->actor->id,
        ], $attrs));
    }

    /* ── 1. the resolver ──────────────────────────────────────────────── */

    public function test_a_workspace_with_no_shifts_falls_back_to_the_legacy_names(): void
    {
        // A fresh tenant must still get a usable field rather than an empty
        // dropdown and a value it cannot supply.
        $this->assertSame(
            OrganizationService::LEGACY_SHIFTS,
            OrganizationService::shiftOptions(self::TENANT)
        );
    }

    public function test_configured_shifts_replace_the_fallback_entirely(): void
    {
        $this->shift(self::TENANT, 'Night Patrol');
        $this->shift(self::TENANT, 'Early');

        $options = OrganizationService::shiftOptions(self::TENANT);

        $this->assertSame(['Early', 'Night Patrol'], $options);
        $this->assertNotContains('Rotational', $options, 'the fallback is a fallback, not a supplement');
    }

    public function test_a_deactivated_shift_is_not_offered(): void
    {
        $this->shift(self::TENANT, 'Early');
        $this->shift(self::TENANT, 'Retired Shift', false);

        $this->assertSame(['Early'], OrganizationService::shiftOptions(self::TENANT));
    }

    public function test_another_workspaces_shifts_are_not_offered(): void
    {
        $this->shift(self::OTHER, 'Their Shift');

        // Nothing configured HERE, so this tenant falls back — it must not
        // inherit the neighbour's master.
        $this->assertSame(OrganizationService::LEGACY_SHIFTS, OrganizationService::shiftOptions(self::TENANT));
        $this->assertNotContains('Their Shift', OrganizationService::shiftOptions(self::TENANT));
    }

    /* ── 2. the dropdown the UI renders ───────────────────────────────── */

    public function test_master_data_serves_the_configured_shifts(): void
    {
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        $this->getJson('/api/hr/master-data')
            ->assertOk()
            ->assertJsonPath('shifts', ['Night Patrol']);
    }

    public function test_master_data_falls_back_when_nothing_is_configured(): void
    {
        Sanctum::actingAs($this->actor);

        $this->getJson('/api/hr/master-data')
            ->assertOk()
            ->assertJsonPath('shifts', OrganizationService::LEGACY_SHIFTS);
    }

    /* ── 3. what the validator accepts, over HTTP ─────────────────────── */

    public function test_a_configured_shift_saves_on_a_new_requisition(): void
    {
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/manpower-requests', $this->payload(['shift' => 'Night Patrol']))
            ->assertStatus(201);

        $this->assertDatabaseHas('hr_manpower_requests', ['shift' => 'Night Patrol']);
    }

    public function test_a_legacy_name_is_refused_once_the_workspace_configures_its_own(): void
    {
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        // The whole point of reading the master: once it exists, it governs.
        $this->postJson('/api/hr/manpower-requests', $this->payload(['shift' => 'Rotational']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    public function test_a_legacy_name_still_saves_while_nothing_is_configured(): void
    {
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/manpower-requests', $this->payload(['shift' => 'Rotational']))
            ->assertStatus(201);
    }

    public function test_another_workspaces_shift_cannot_be_saved(): void
    {
        $this->shift(self::TENANT, 'Ours');
        $this->shift(self::OTHER, 'Theirs');

        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/manpower-requests', $this->payload(['shift' => 'Theirs']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    public function test_a_deactivated_shift_cannot_be_saved(): void
    {
        $this->shift(self::TENANT, 'Ours');
        $this->shift(self::TENANT, 'Retired Shift', false);

        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/manpower-requests', $this->payload(['shift' => 'Retired Shift']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    public function test_an_absent_shift_is_still_optional(): void
    {
        $this->shift(self::TENANT, 'Ours');

        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/manpower-requests', $this->payload())->assertStatus(201);
    }

    /* ── 4. nothing already stored is invalidated ─────────────────────── */

    public function test_an_existing_legacy_value_survives_an_unrelated_edit(): void
    {
        $mr = $this->existingRequest(['shift' => 'Rotational']);
        $this->shift(self::TENANT, 'Night Patrol');   // configured AFTER the row was written

        Sanctum::actingAs($this->actor);

        // Editing the title must not fail because an untouched shift field no
        // longer matches the master. No backfill, no invalidation.
        $this->putJson("/api/hr/manpower-requests/{$mr->id}", ['position_title' => 'Senior Engineer'])
            ->assertOk();

        $this->assertSame('Rotational', $mr->fresh()->shift);
    }

    public function test_an_existing_legacy_value_can_be_resubmitted_unchanged(): void
    {
        $mr = $this->existingRequest(['shift' => 'Rotational']);
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        // The form posts every field back, including the shift it loaded.
        $this->putJson("/api/hr/manpower-requests/{$mr->id}", [
            'position_title' => 'Senior Engineer', 'shift' => 'Rotational',
        ])->assertOk();

        $this->assertSame('Rotational', $mr->fresh()->shift);
    }

    public function test_an_existing_row_can_be_moved_onto_a_configured_shift(): void
    {
        $mr = $this->existingRequest(['shift' => 'Rotational']);
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        $this->putJson("/api/hr/manpower-requests/{$mr->id}", ['shift' => 'Night Patrol'])
            ->assertOk();

        $this->assertSame('Night Patrol', $mr->fresh()->shift);
    }

    public function test_an_edit_cannot_introduce_a_different_unknown_shift(): void
    {
        $mr = $this->existingRequest(['shift' => 'Rotational']);
        $this->shift(self::TENANT, 'Night Patrol');

        Sanctum::actingAs($this->actor);

        // Its OWN stored value is tolerated. Another stale one is not — the
        // tolerance is per row, not a reopened back door to the old list.
        $this->putJson("/api/hr/manpower-requests/{$mr->id}", ['shift' => 'Flexible'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    public function test_an_edit_cannot_introduce_another_workspaces_shift(): void
    {
        $mr = $this->existingRequest(['shift' => 'Rotational']);
        $this->shift(self::TENANT, 'Ours');
        $this->shift(self::OTHER, 'Theirs');

        Sanctum::actingAs($this->actor);

        $this->putJson("/api/hr/manpower-requests/{$mr->id}", ['shift' => 'Theirs'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    /* ── 5. attendance keeps its own vocabulary ───────────────────────── */

    public function test_the_attendance_presets_are_left_alone(): void
    {
        // A separate map, carrying start/end/grace for a different job. This
        // change must not have merged the two vocabularies.
        $this->assertSame(
            ['General', 'Morning', 'Evening', 'Night', 'Custom'],
            array_keys(\App\Models\Hr\HrAttendance::SHIFTS)
        );
    }
}
