<?php

namespace Tests\Feature\Task;

use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bulk assign takes several people.
 *
 * It took one user id, so putting three people on a set of tasks was three
 * passes over the same selection — and building the selection is the expensive
 * part. The `value` field on /tasks/bulk was already untyped (its meaning
 * depends on the action), so widening it for 'assign' touches nothing else.
 *
 * The single-id shape still works: the bar sends one when only one is picked,
 * and older callers exist.
 */
class BulkAssignManyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->person('Super Admin', 'admin');
    }

    private function person(string $name, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role,
            'email' => Str::slug($name).'-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function task(string $name): Task
    {
        $t = Task::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'status' => 'not_started', 'priority' => 'medium',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
        $t->forceFill(['root_id' => $t->id])->save();

        return $t;
    }

    private function bulk(array $ids, $value)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/tasks/bulk', [
            'action' => 'assign', 'task_ids' => $ids, 'value' => $value,
        ]);
    }

    private function ownerIds(Task $task): array
    {
        return $task->assignees()->orderBy('id')->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_three_people_go_onto_every_selected_task_in_one_call(): void
    {
        $a = $this->person('A');
        $b = $this->person('B');
        $c = $this->person('C');
        $one = $this->task('One');
        $two = $this->task('Two');

        $this->bulk([$one->id, $two->id], [$a->id, $b->id, $c->id])->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $this->ownerIds($one));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $this->ownerIds($two));
    }

    public function test_it_adds_to_whoever_is_already_on_the_task(): void
    {
        $existing = $this->person('Already On It');
        $a = $this->person('A');
        $task = $this->task('One');
        $task->assignees()->create(['tenant_id' => self::TENANT, 'user_id' => $existing->id]);

        $this->bulk([$task->id], [$a->id])->assertOk();

        // "Also put these people on it", not "these people and nobody else" —
        // replacing would silently take people off work they are doing.
        $this->assertEqualsCanonicalizing([$existing->id, $a->id], $this->ownerIds($task));
    }

    public function test_a_single_id_still_works(): void
    {
        $a = $this->person('A');
        $task = $this->task('One');

        // The bar sends one when only one is picked.
        $this->bulk([$task->id], $a->id)->assertOk();

        $this->assertSame([$a->id], $this->ownerIds($task));
    }

    public function test_one_bad_id_fails_the_whole_call_rather_than_half_applying(): void
    {
        $a = $this->person('A');
        $client = $this->person('A Client', 'client');
        $task = $this->task('One');

        $this->bulk([$task->id], [$a->id, $client->id])->assertStatus(422);

        // Validated once up front, before a single row is touched — a partly
        // applied bulk action is worse than a refused one, because nothing on
        // screen says which half went through.
        $this->assertSame([], $this->ownerIds($task));
    }

    public function test_an_empty_list_is_refused_rather_than_silently_doing_nothing(): void
    {
        $task = $this->task('One');

        $this->bulk([$task->id], [])->assertStatus(422);
        $this->assertSame([], $this->ownerIds($task));
    }
}
