<?php

namespace Tests\Feature\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A checklist line can be corrected, and it can be taken off.
 *
 * The list could only be ADDED to and TICKED. A typo stayed a typo, and a line
 * put there by mistake could only be dealt with by ticking it — which does not
 * say "never mind", it says "we did this", and leaves a false record of the
 * work behind on a task somebody else will read later.
 *
 * What is pinned here:
 *
 *  • renaming a line leaves the people on it alone. The update endpoint takes
 *    `description` and `assigned_to` in one call, and "absent" has to keep
 *    meaning "do not touch" — otherwise fixing a spelling silently unassigns
 *    whoever was on it.
 *  • deleting takes the assignee rows with it, so no pivot row survives
 *    pointing at a line that no longer exists.
 *  • the access check runs BEFORE the delete. Checking afterwards would have
 *    destroyed the row it was supposed to protect, so this is asserted from
 *    another tenant rather than assumed from reading the controller.
 */
class ChecklistItemEditAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const OTHER_TENANT = 2;

    private User $admin;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER_TENANT] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }

        $this->admin = $this->person(self::TENANT, 'Super Admin', 'admin');

        $this->task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Mobilise the site',
            'status' => 'not_started', 'priority' => 'medium',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
        $this->task->forceFill(['root_id' => $this->task->id])->save();
    }

    private function person(int $tenantId, string $name, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => $name, 'role' => $role,
            'email' => Str::slug($name).'-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function item(string $text): TaskChecklistItem
    {
        return TaskChecklistItem::create([
            'tenant_id' => self::TENANT, 'task_id' => $this->task->id,
            'description' => $text, 'finished' => false, 'order' => 1,
        ]);
    }

    public function test_a_line_can_be_renamed(): void
    {
        Sanctum::actingAs($this->admin);
        $item = $this->item('Colect the permits');

        $this->patchJson("/api/tasks/checklist/{$item->id}", ['description' => 'Collect the permits'])
            ->assertOk();

        $this->assertSame('Collect the permits', $item->fresh()->description);
    }

    public function test_renaming_a_line_leaves_the_people_on_it_alone(): void
    {
        Sanctum::actingAs($this->admin);
        $priya = $this->person(self::TENANT, 'Priya');
        $item = $this->item('Colect the permits');

        $this->patchJson("/api/tasks/checklist/{$item->id}", ['assigned_to' => [$priya->id]])->assertOk();
        $this->patchJson("/api/tasks/checklist/{$item->id}", ['description' => 'Collect the permits'])->assertOk();

        // `assigned_to` absent must mean "do not touch the people", not "clear
        // them" — a spelling fix that unassigns somebody is a silent one.
        $this->assertSame(
            [$priya->id],
            $item->fresh()->assignees()->pluck('user_id')->map(fn ($i) => (int) $i)->all(),
        );
    }

    public function test_a_line_can_be_removed_and_takes_its_assignees_with_it(): void
    {
        Sanctum::actingAs($this->admin);
        $priya = $this->person(self::TENANT, 'Priya');
        $item = $this->item('Not actually part of this job');

        $this->patchJson("/api/tasks/checklist/{$item->id}", ['assigned_to' => [$priya->id]])->assertOk();
        $this->assertDatabaseCount('task_checklist_item_assignees', 1);

        $this->deleteJson("/api/tasks/checklist/{$item->id}")->assertOk();

        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item->id]);
        // A pivot row left behind would point at a line that no longer exists,
        // which is what puts a stale user id into a notification query.
        $this->assertDatabaseCount('task_checklist_item_assignees', 0);
    }

    public function test_removing_a_line_that_is_not_there_is_a_404_not_a_500(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/tasks/checklist/999999')->assertNotFound();
    }

    public function test_another_tenant_cannot_delete_the_line(): void
    {
        $outsider = $this->person(self::OTHER_TENANT, 'Outsider', 'admin');
        $item = $this->item('Collect the permits');

        Sanctum::actingAs($outsider);
        $this->deleteJson("/api/tasks/checklist/{$item->id}")->assertNotFound();

        // The point of the assertion: the guard has to run BEFORE the delete,
        // so a refused request leaves the row exactly where it was.
        $this->assertDatabaseHas('task_checklist_items', ['id' => $item->id]);
    }
}
