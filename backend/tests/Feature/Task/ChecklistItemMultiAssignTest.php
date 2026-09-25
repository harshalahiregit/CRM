<?php

namespace Tests\Feature\Task;

use App\Models\Notification;
use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A checklist line can be on more than one person.
 *
 * It held a single user id, so "Priya and Rohit are doing this one" had to be
 * written as two lines — or as one line with one name on it and the other person
 * told verbally, which loses them the moment anybody reads the list.
 *
 * The pivot is the truth now. `assigned_to` is kept pointing at the FIRST person
 * in the set, so the notification leg and anything outside this module that
 * reads the column go on working; the tests below pin that mirror, because a
 * cache nobody checks is a cache that drifts.
 */
class ChecklistItemMultiAssignTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->person('Super Admin', 'admin');

        $this->task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Mobilise the site',
            'status' => 'not_started', 'priority' => 'medium',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
        $this->task->forceFill(['root_id' => $this->task->id])->save();
    }

    private function person(string $name, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role,
            'email' => Str::slug($name).'-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function addItem(string $text, array|int|null $assigned = null): array
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/tasks/{$this->task->id}/checklist", array_filter([
            'description' => $text,
            'assigned_to' => $assigned,
        ], fn ($v) => $v !== null))->assertStatus(201)->json('data');
    }

    private function assign(int $itemId, array|int|null $to): array
    {
        Sanctum::actingAs($this->admin);

        return $this->patchJson("/api/tasks/checklist/{$itemId}", ['assigned_to' => $to])
            ->assertOk()->json('data');
    }

    private function ownerIds(int $itemId): array
    {
        return TaskChecklistItem::find($itemId)->assignees()
            ->orderBy('id')->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    /* ── the point of the change ────────────────────────────────── */

    public function test_one_line_can_be_put_on_several_people(): void
    {
        $priya = $this->person('Priya Sharma');
        $rohit = $this->person('Rohit Nair');

        $item = $this->addItem('Walk the perimeter');
        $this->assign($item['id'], [$priya->id, $rohit->id]);

        $this->assertSame([$priya->id, $rohit->id], $this->ownerIds($item['id']));
    }

    public function test_the_payload_carries_every_owner_with_their_name(): void
    {
        $priya = $this->person('Priya Sharma');
        $rohit = $this->person('Rohit Nair');

        $item = $this->addItem('Walk the perimeter');
        $row = $this->assign($item['id'], [$priya->id, $rohit->id]);

        // The chip has to render without a second request per line.
        $names = array_column(array_column($row['assignees'], 'user'), 'name');
        sort($names);
        $this->assertSame(['Priya Sharma', 'Rohit Nair'], $names);
    }

    public function test_the_set_posted_is_the_set_kept(): void
    {
        $a = $this->person('A');
        $b = $this->person('B');
        $c = $this->person('C');

        $item = $this->addItem('Check the lighting');

        $this->assign($item['id'], [$a->id, $b->id, $c->id]);
        $this->assertCount(3, $this->ownerIds($item['id']));

        // Replace, not merge — the same contract as the task assignee endpoint.
        $this->assign($item['id'], [$b->id]);
        $this->assertSame([$b->id], $this->ownerIds($item['id']));

        // And an empty list takes everybody off.
        $this->assign($item['id'], []);
        $this->assertSame([], $this->ownerIds($item['id']));
    }

    public function test_assigning_the_same_person_twice_does_not_duplicate_them(): void
    {
        $priya = $this->person('Priya Sharma');
        $item = $this->addItem('Check the lighting');

        $this->assign($item['id'], [$priya->id, $priya->id]);

        $this->assertSame([$priya->id], $this->ownerIds($item['id']));
    }

    /* ── the mirror column ──────────────────────────────────────── */

    public function test_assigned_to_still_points_at_the_first_person(): void
    {
        $priya = $this->person('Priya Sharma');
        $rohit = $this->person('Rohit Nair');

        $item = $this->addItem('Walk the perimeter');
        $this->assign($item['id'], [$priya->id, $rohit->id]);

        // Kept so the notification leg and anything outside this module that
        // reads the column go on working.
        $this->assertSame($priya->id, TaskChecklistItem::find($item['id'])->assigned_to);

        // And it is cleared when the last person comes off, rather than being
        // left pointing at somebody who is no longer on the line.
        $this->assign($item['id'], []);
        $this->assertNull(TaskChecklistItem::find($item['id'])->assigned_to);
    }

    public function test_a_single_id_still_works_the_way_it_always_did(): void
    {
        $priya = $this->person('Priya Sharma');

        // The endpoint took one integer before this, and older callers still
        // send one. Both shapes have to mean the same thing.
        $item = $this->addItem('Sign the register', $priya->id);

        $this->assertSame([$priya->id], $this->ownerIds($item['id']));
        $this->assertSame($priya->id, TaskChecklistItem::find($item['id'])->assigned_to);
    }

    /* ── the things that quietly go wrong ───────────────────────── */

    public function test_renaming_a_line_does_not_unassign_it(): void
    {
        $priya = $this->person('Priya Sharma');
        $item = $this->addItem('Walk the permiter', $priya->id);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/tasks/checklist/{$item['id']}", ['description' => 'Walk the perimeter'])
            ->assertOk();

        // Absent means "leave the people alone". If the key being missing were
        // read as an empty set, fixing a typo would silently clear the line.
        $this->assertSame([$priya->id], $this->ownerIds($item['id']));
    }

    public function test_everyone_newly_added_is_told_and_nobody_is_told_twice(): void
    {
        $priya = $this->person('Priya Sharma');
        $rohit = $this->person('Rohit Nair');
        $item = $this->addItem('Walk the perimeter');

        $this->assign($item['id'], [$priya->id]);
        $this->assign($item['id'], [$priya->id, $rohit->id]);

        $count = fn (User $u) => Notification::where('user_id', $u->id)
            ->where('type', 'task.checklist_assigned')->count();

        // Priya was already on it — the second call adds Rohit, and re-telling
        // her is how a shared line becomes a source of noise.
        $this->assertSame(1, $count($priya));
        $this->assertSame(1, $count($rohit));
    }

    public function test_somebody_from_another_tenant_cannot_be_put_on_a_line(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Outsider', 'role' => 'staff',
            'email' => 'out-'.Str::random(5).'@t2.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $item = $this->addItem('Walk the perimeter');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/tasks/checklist/{$item['id']}", ['assigned_to' => [$outsider->id]])
            ->assertStatus(422);

        $this->assertSame([], $this->ownerIds($item['id']));
    }

    public function test_the_list_endpoint_carries_the_owners_too(): void
    {
        $priya = $this->person('Priya Sharma');
        $rohit = $this->person('Rohit Nair');
        $item = $this->addItem('Walk the perimeter');
        $this->assign($item['id'], [$priya->id, $rohit->id]);

        Sanctum::actingAs($this->admin);
        $rows = $this->getJson("/api/tasks/{$this->task->id}/checklist")->assertOk()->json('data');

        $this->assertCount(2, $rows[0]['assignees']);
    }
}
