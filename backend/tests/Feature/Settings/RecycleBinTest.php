<?php

namespace Tests\Feature\Settings;

use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ST2 — the global recycle bin lists soft-deleted records across modules and
 * restores one; admin-only and strictly tenant-scoped.
 */
class RecycleBinTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::TENANT => 't1', self::OTHER => 't2'] as $id => $slug) {
            (new Tenant())->forceFill(['id' => $id, 'name' => strtoupper($slug), 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active'])->save();
        }
        $this->uid = $this->user('admin')->id;
    }

    private function user(string $role, int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function task(string $name, int $tenantId = self::TENANT): Task
    {
        return Task::create([
            'tenant_id' => $tenantId, 'name' => $name, 'status' => 'not_started', 'priority' => 'medium',
            'start_date' => '2026-01-01', 'created_by' => $this->uid,
        ]);
    }

    private function project(string $name, int $tenantId = self::TENANT): Project
    {
        $p = new Project();
        $p->forceFill(['tenant_id' => $tenantId, 'name' => $name, 'status' => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01', 'created_by' => $this->uid])->save();

        return $p;
    }

    public function test_lists_deleted_items_and_restores_one(): void
    {
        $task = $this->task('Deleted task');
        $project = $this->project('Deleted project');
        $task->delete();
        $project->delete();

        Sanctum::actingAs(User::find($this->uid));
        $res = $this->getJson('/api/settings/recycle-bin')->assertOk();
        $rows = collect($res->json('data'));

        $this->assertTrue($rows->contains(fn ($r) => $r['type'] === 'task' && $r['id'] === $task->id));
        $this->assertTrue($rows->contains(fn ($r) => $r['type'] === 'project' && $r['id'] === $project->id));

        // Restore the task.
        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'task', 'id' => $task->id])->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);

        // It's gone from the bin now; the project remains.
        $after = collect($this->getJson('/api/settings/recycle-bin')->json('data'));
        $this->assertFalse($after->contains(fn ($r) => $r['type'] === 'task' && $r['id'] === $task->id));
        $this->assertTrue($after->contains(fn ($r) => $r['type'] === 'project' && $r['id'] === $project->id));
    }

    public function test_cannot_restore_another_tenants_item(): void
    {
        $foreign = $this->task('Foreign', self::OTHER);
        $foreign->delete();

        Sanctum::actingAs(User::find($this->uid));   // tenant 1 admin
        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'task', 'id' => $foreign->id])->assertNotFound();
        $this->assertSoftDeleted('tasks', ['id' => $foreign->id]);
    }

    public function test_staff_cannot_use_the_recycle_bin(): void
    {
        Sanctum::actingAs($this->user('staff'));
        $this->getJson('/api/settings/recycle-bin')->assertForbidden();
        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'task', 'id' => 1])->assertForbidden();
    }
}
