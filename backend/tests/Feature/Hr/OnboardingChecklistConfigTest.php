<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Models\Hr\HrOnboardingChecklistItem as Item;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeOnboardingService;
use App\Services\Hr\OnboardingChecklistService;
use App\Support\Hr\OnboardingTaskCategory as TaskCat;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The onboarding checklist, as a tenant master rather than a PHP constant.
 *
 * OnboardingTaskCategory::DEFAULT_TASKS held 27 tasks in code, so a company
 * that wanted one more induction step, or did not issue laptops, needed a
 * developer and a deploy. Those 27 are now the FALLBACK, and a workspace's own
 * rows are the list.
 *
 * Two properties carry most of the weight here.
 *
 * The first is that nothing changed for anybody. A tenant that has configured
 * nothing still seeds exactly the same 27 tasks, in the same order, with the
 * same categories, owners and mandatory flags — asserted item by item rather
 * than by count, because a count would pass if the titles were wrong.
 *
 * The second is that editing the template cannot reach an onboarding already
 * under way. That was already true of the data model — seedTasks() COPIES into
 * hr_employee_onboarding_tasks and the copy holds no reference back — and the
 * tests below pin it, because it is the thing an administrator would most
 * reasonably fear when handed an editor.
 */
class OnboardingChecklistConfigTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'chk-a', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'chk-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function admin(?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'Admin',
            'email' => uniqid().'@chk.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    /**
     * A staff user carrying the permissions named, plus hr_attendance.
     *
     * hr_attendance is the route GROUP's gate and is held by both users below,
     * so the only thing that differs between them is hr_settings — otherwise
     * the refusal would come from the middleware and these tests would pass
     * without the controller's check existing at all.
     */
    private function staff(array $modules, string $slug): User
    {
        $permissions = ['hr_attendance' => [StaffPermission::VIEW_GLOBAL]];
        foreach ($modules as $module) {
            $permissions[$module] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($slug), 'slug' => $slug,
            'permissions' => $permissions, 'scope' => 'global', 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => uniqid().'@chk.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    /** Holds hr_settings — the configured, non-admin route in. */
    private function settingsUser(): User
    {
        return $this->staff(['hr_settings'], 'hr_config');
    }

    /** Through the group's door, but without hr_settings. */
    private function hrUser(): User
    {
        return $this->staff(['hr_employees'], 'hr_exec');
    }

    private function item(array $attrs = [], ?Tenant $tenant = null): Item
    {
        return Item::create(array_merge([
            'tenant_id' => ($tenant ?: $this->tenant)->id,
            'category' => TaskCat::ORIENTATION, 'title' => 'A task',
            'owner_role' => 'HR', 'is_mandatory' => false,
            'sort_order' => 0, 'is_active' => true,
        ], $attrs));
    }

    private function service(): OnboardingChecklistService
    {
        return app(OnboardingChecklistService::class);
    }

    /** An onboarding through the real path, so tasks are seeded as in production. */
    private function startOnboarding(?Tenant $tenant = null): HrEmployeeOnboarding
    {
        $tenant = $tenant ?: $this->tenant;
        $actor = $this->admin($tenant);

        $employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'name' => 'New Joiner',
            'employee_code' => 'E'.substr(uniqid(), -6), 'department' => 'Ops',
            'designation' => 'Analyst', 'joining_date' => '2026-10-01', 'status' => 'Active',
        ]);

        return app(EmployeeOnboardingService::class)->createFromEmployee($employee->id, $actor);
    }

    /* ── 1. nothing changed for an unconfigured workspace ─────────────── */

    public function test_an_unconfigured_workspace_still_gets_the_original_27(): void
    {
        $this->assertFalse($this->service()->isConfigured($this->tenant->id));

        $applicable = $this->service()->applicableFor($this->tenant->id);

        $this->assertCount(27, $applicable);
    }

    public function test_the_fallback_is_the_constant_exactly(): void
    {
        // Item by item, not by count: a count would pass while the titles,
        // owners or mandatory flags had all silently changed.
        $expected = [];
        foreach (TaskCat::DEFAULT_TASKS as $category => $tasks) {
            foreach ($tasks as $task) {
                $expected[] = [
                    'category' => $category, 'title' => $task['title'],
                    'owner_role' => $task['owner_role'], 'is_mandatory' => (bool) $task['is_mandatory'],
                ];
            }
        }

        $this->assertSame($expected, $this->service()->applicableFor($this->tenant->id));
    }

    public function test_a_new_onboarding_seeds_the_original_27_when_unconfigured(): void
    {
        $onboarding = $this->startOnboarding();

        $tasks = $onboarding->tasks()->orderBy('sort_order')->get();

        $this->assertCount(27, $tasks);
        $this->assertSame('Company overview & vision', $tasks->first()->title);
        $this->assertSame(TaskCat::ORIENTATION, $tasks->first()->category);
        $this->assertTrue((bool) $tasks->first()->is_mandatory);
        $this->assertSame('Introduced to key stakeholders', $tasks->last()->title);
        $this->assertSame(TaskCat::STATUS_PENDING, $tasks->first()->status);
        $this->assertSame('System', $tasks->first()->source);
    }

    /* ── 2. a configured workspace gets its own list ──────────────────── */

    public function test_a_configured_workspace_replaces_the_defaults_entirely(): void
    {
        $this->item(['title' => 'Sign the safety brief', 'sort_order' => 0]);
        $this->item(['title' => 'Collect PPE', 'sort_order' => 1, 'category' => TaskCat::GENERAL]);

        $onboarding = $this->startOnboarding();
        $titles = $onboarding->tasks()->orderBy('sort_order')->pluck('title')->all();

        $this->assertSame(['Sign the safety brief', 'Collect PPE'], $titles);
    }

    public function test_an_inactive_item_is_not_seeded(): void
    {
        $this->item(['title' => 'Current', 'sort_order' => 0]);
        $this->item(['title' => 'Retired', 'sort_order' => 1, 'is_active' => false]);

        $onboarding = $this->startOnboarding();

        $this->assertSame(['Current'], $onboarding->tasks()->pluck('title')->all());
    }

    public function test_deactivating_everything_leaves_an_empty_checklist(): void
    {
        // The distinction that matters: a workspace with rows has configured
        // one, even when every row is off. Restoring the defaults over that
        // would be the system overruling an administrator.
        $this->item(['title' => 'Only one', 'is_active' => false]);

        $this->assertSame([], $this->service()->applicableFor($this->tenant->id));
        $this->assertCount(0, $this->startOnboarding()->tasks);
    }

    public function test_items_are_seeded_in_their_configured_order(): void
    {
        $this->item(['title' => 'Third', 'sort_order' => 30]);
        $this->item(['title' => 'First', 'sort_order' => 10]);
        $this->item(['title' => 'Second', 'sort_order' => 20]);

        $onboarding = $this->startOnboarding();

        $this->assertSame(['First', 'Second', 'Third'],
            $onboarding->tasks()->orderBy('sort_order')->pluck('title')->all());
    }

    /* ── 3. tenant isolation ──────────────────────────────────────────── */

    public function test_one_workspaces_checklist_does_not_reach_another(): void
    {
        $this->item(['title' => 'Ours'], $this->tenant);

        // The other tenant has configured nothing, so it falls back — it must
        // not inherit the neighbour's single item.
        $theirs = $this->service()->applicableFor($this->other->id);

        $this->assertCount(27, $theirs);
        $this->assertNotContains('Ours', array_column($theirs, 'title'));
    }

    public function test_a_configured_workspace_sees_only_its_own_items(): void
    {
        // BOTH workspaces configured, so neither falls back. Without this the
        // isolation test above passes on a resolver that ignores tenant_id
        // entirely: the unconfigured side returns the defaults either way, and
        // the leak never shows.
        $this->item(['title' => 'Ours'], $this->tenant);
        $this->item(['title' => 'Theirs'], $this->other);

        $mine   = array_column($this->service()->applicableFor($this->tenant->id), 'title');
        $theirs = array_column($this->service()->applicableFor($this->other->id), 'title');

        $this->assertSame(['Ours'], $mine);
        $this->assertSame(['Theirs'], $theirs);
    }

    public function test_an_onboarding_never_seeds_another_workspaces_tasks(): void
    {
        $this->item(['title' => 'Ours'], $this->tenant);
        $this->item(['title' => 'Theirs'], $this->other);

        $onboarding = $this->startOnboarding($this->tenant);

        $this->assertSame(['Ours'], $onboarding->tasks()->pluck('title')->all());
    }

    public function test_an_admin_cannot_edit_another_workspaces_item(): void
    {
        $theirs = $this->item(['title' => 'Theirs'], $this->other);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/onboarding-checklist/{$theirs->id}", ['title' => 'Mine now'])
            ->assertStatus(404);
        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    public function test_a_reorder_cannot_include_another_workspaces_item(): void
    {
        $mine = $this->item(['title' => 'Mine'], $this->tenant);
        $theirs = $this->item(['title' => 'Theirs'], $this->other);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/onboarding-checklist/reorder', ['ids' => [$mine->id, $theirs->id]])
            ->assertStatus(422);
        $this->assertSame(0, (int) $theirs->fresh()->sort_order);
    }

    /* ── 4. authorization ─────────────────────────────────────────────── */

    public function test_a_user_with_hr_settings_may_configure(): void
    {
        Sanctum::actingAs($this->settingsUser());

        $this->getJson('/api/hr/onboarding-checklist')->assertOk();
        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'New step'])->assertStatus(201);
    }

    public function test_hr_access_alone_does_not_permit_configuring(): void
    {
        $item = $this->item();

        Sanctum::actingAs($this->hrUser());

        // Running onboardings must not confer the right to remove the steps
        // you are measured against.
        $this->getJson('/api/hr/onboarding-checklist')->assertStatus(403);
        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson("/api/hr/onboarding-checklist/{$item->id}")->assertStatus(403);
        $this->assertDatabaseHas('hr_onboarding_checklist_items', ['id' => $item->id]);
    }

    /* ── 5. the editing itself ────────────────────────────────────────── */

    public function test_a_task_can_be_added_edited_disabled_and_removed(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/hr/onboarding-checklist', [
            'title' => 'Issue safety boots', 'category' => TaskCat::IT_SETUP,
            'owner_role' => 'System', 'is_mandatory' => true,
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/hr/onboarding-checklist/{$id}", ['title' => 'Issue safety boots and helmet'])
            ->assertOk()->assertJsonPath('data.title', 'Issue safety boots and helmet');

        $this->patchJson("/api/hr/onboarding-checklist/{$id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/hr/onboarding-checklist/{$id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);

        $this->deleteJson("/api/hr/onboarding-checklist/{$id}")->assertOk();
        $this->assertDatabaseMissing('hr_onboarding_checklist_items', ['id' => $id]);
    }

    public function test_a_new_task_is_appended_not_inserted(): void
    {
        $this->item(['title' => 'Existing', 'sort_order' => 5]);

        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/hr/onboarding-checklist', ['title' => 'Added'])
            ->assertStatus(201)->json('data.id');

        $this->assertSame(6, (int) Item::find($id)->sort_order);
    }

    public function test_reordering_stores_the_order_it_was_given(): void
    {
        $a = $this->item(['title' => 'A', 'sort_order' => 0]);
        $b = $this->item(['title' => 'B', 'sort_order' => 1]);
        $c = $this->item(['title' => 'C', 'sort_order' => 2]);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/hr/onboarding-checklist/reorder', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertSame(['C', 'A', 'B'],
            Item::where('tenant_id', $this->tenant->id)->orderBy('sort_order')->pluck('title')->all());
    }

    public function test_a_blank_title_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/onboarding-checklist', ['title' => '   '])->assertStatus(422);
    }

    public function test_an_unknown_category_or_owner_role_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'X', 'category' => 'Invented'])
            ->assertStatus(422);
        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'X', 'owner_role' => 'Nobody'])
            ->assertStatus(422);
    }

    public function test_the_defaults_can_be_adopted_as_editable_rows(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/onboarding-checklist/adopt-defaults')->assertOk();

        $this->assertSame(27, Item::where('tenant_id', $this->tenant->id)->count());

        // And never twice — adopting again must not append a second copy.
        $this->postJson('/api/hr/onboarding-checklist/adopt-defaults')->assertStatus(422);
        $this->assertSame(27, Item::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_listing_shows_inactive_items_too(): void
    {
        $this->item(['title' => 'On', 'sort_order' => 0]);
        $this->item(['title' => 'Off', 'sort_order' => 1, 'is_active' => false]);

        Sanctum::actingAs($this->admin());

        // A retired step has to stay visible in Settings or it cannot be
        // brought back.
        $titles = array_column($this->getJson('/api/hr/onboarding-checklist')->assertOk()->json('data.items'), 'title');
        $this->assertSame(['On', 'Off'], $titles);
    }

    /* ── 6. an onboarding under way is untouchable ────────────────────── */

    public function test_editing_the_template_does_not_alter_a_started_onboarding(): void
    {
        $keep = $this->item(['title' => 'Original step', 'sort_order' => 0]);
        $drop = $this->item(['title' => 'Second step', 'sort_order' => 1]);

        $onboarding = $this->startOnboarding();
        $this->assertCount(2, $onboarding->tasks);

        // Now an administrator rewrites the template underneath it.
        Sanctum::actingAs($this->admin());
        $this->putJson("/api/hr/onboarding-checklist/{$keep->id}", ['title' => 'Renamed step'])->assertOk();
        $this->deleteJson("/api/hr/onboarding-checklist/{$drop->id}")->assertOk();
        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'Brand new step'])->assertStatus(201);

        // The in-flight checklist is exactly as it was: nothing renamed,
        // nothing removed, nothing appended.
        $titles = $onboarding->fresh()->tasks()->orderBy('sort_order')->pluck('title')->all();
        $this->assertSame(['Original step', 'Second step'], $titles);
    }

    public function test_reordering_the_template_does_not_reorder_a_started_onboarding(): void
    {
        $a = $this->item(['title' => 'A', 'sort_order' => 0]);
        $b = $this->item(['title' => 'B', 'sort_order' => 1]);

        $onboarding = $this->startOnboarding();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/hr/onboarding-checklist/reorder', ['ids' => [$b->id, $a->id]])->assertOk();

        $this->assertSame(['A', 'B'],
            $onboarding->fresh()->tasks()->orderBy('sort_order')->pluck('title')->all());
    }

    public function test_completed_work_survives_the_template_being_emptied(): void
    {
        $this->item(['title' => 'Do the thing', 'sort_order' => 0]);

        $onboarding = $this->startOnboarding();
        $task = $onboarding->tasks()->firstOrFail();

        $actor = $this->admin();
        app(EmployeeOnboardingService::class)->updateTask(
            $onboarding, $task, ['status' => TaskCat::STATUS_COMPLETED], $actor
        );

        // Wipe the template entirely.
        Item::where('tenant_id', $this->tenant->id)->delete();

        $fresh = $task->fresh();
        $this->assertSame(TaskCat::STATUS_COMPLETED, $fresh->status);
        $this->assertSame($actor->id, (int) $fresh->completed_by);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_a_later_onboarding_picks_up_the_edited_template(): void
    {
        $this->item(['title' => 'Old step', 'sort_order' => 0]);

        $first = $this->startOnboarding();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/hr/onboarding-checklist', ['title' => 'New step'])->assertStatus(201);

        $second = $this->startOnboarding();

        // The whole point of the snapshot: the old one keeps its list, the new
        // one gets the current one.
        $this->assertSame(['Old step'], $first->fresh()->tasks()->pluck('title')->all());
        $this->assertSame(['Old step', 'New step'],
            $second->tasks()->orderBy('sort_order')->pluck('title')->all());
    }

    /* ── 7. existing task behaviour is unchanged ──────────────────────── */

    public function test_completing_a_task_still_stamps_who_and_when(): void
    {
        $onboarding = $this->startOnboarding();
        $task = $onboarding->tasks()->firstOrFail();
        $actor = $this->admin();

        app(EmployeeOnboardingService::class)->updateTask(
            $onboarding, $task, ['status' => TaskCat::STATUS_COMPLETED], $actor
        );

        $fresh = $task->fresh();
        $this->assertSame(TaskCat::STATUS_COMPLETED, $fresh->status);
        $this->assertSame($actor->id, (int) $fresh->completed_by);
        $this->assertSame($actor->name, $fresh->completed_by_name);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_the_hardcoded_constant_is_still_the_documented_fallback(): void
    {
        // Kept deliberately rather than deleted: it is what a workspace that
        // has configured nothing still receives, and the migration backfills
        // from it. Removing it would strand every new tenant with no list.
        $this->assertCount(5, TaskCat::DEFAULT_TASKS);
        $this->assertSame(27, array_sum(array_map('count', TaskCat::DEFAULT_TASKS)));
    }
}
