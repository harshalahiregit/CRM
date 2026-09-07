<?php

namespace Tests\Feature\Settings;

use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RecycleBin\TrashRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The recycle bin is a registry, so the registry has to be true.
 *
 * Every entry names a model, the columns that label a row on screen, and a
 * group. All three are written by hand, and any one of them being wrong is
 * invisible until the day somebody deletes that kind of record and opens the
 * bin — at which point it is a 500 on an admin screen, or worse, a type that
 * silently lists nothing while looking fine.
 *
 * So the registry is checked against the real schema here rather than trusted:
 * the class exists, it soft-deletes (otherwise nothing can ever appear under
 * that type), it is tenant-scoped (otherwise the bin leaks across tenants), and
 * every label column it names actually exists on its table.
 */
class RecycleBinRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private function admin(): User
    {
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function project(string $name, User $owner, int $tenantId = self::TENANT): Project
    {
        return Project::create([
            'tenant_id' => $tenantId, 'name' => $name, 'status' => 'active',
            'start_date' => now()->toDateString(), 'created_by' => $owner->id,
        ]);
    }

    private function task(string $name, User $owner): Task
    {
        return Task::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'start_date' => now()->toDateString(), 'created_by' => $owner->id,
        ]);
    }

    /** Every registered model is real, soft-deleting and tenant-scoped. */
    public function test_every_registered_model_can_actually_be_binned(): void
    {
        foreach (TrashRegistry::all() as $key => [$model, $labels, $label, $group]) {
            $this->assertTrue(class_exists($model), "[$key] model {$model} does not exist");

            $instance = new $model();
            $this->assertInstanceOf(Model::class, $instance, "[$key] {$model} is not an Eloquent model");

            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($model),
                "[$key] {$model} does not soft-delete — nothing could ever appear under this type"
            );

            $table = $instance->getTable();
            $this->assertTrue(Schema::hasTable($table), "[$key] table {$table} does not exist");

            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "[$key] {$table} has no tenant_id — the bin scopes on it, and without it this type would leak across tenants"
            );

            $this->assertNotEmpty($labels, "[$key] has no label columns, so its rows would be unnamed");
            $this->assertNotSame('', trim($label), "[$key] has no human type label");
            $this->assertNotSame('', trim($group), "[$key] has no group");
        }
    }

    /** Every label column named in the registry exists on its table. */
    public function test_every_label_column_exists(): void
    {
        foreach (TrashRegistry::all() as $key => [$model, $labels]) {
            $table = (new $model())->getTable();
            foreach ($labels as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "[$key] label column {$table}.{$column} does not exist — this row would render blank"
                );
            }
        }
    }

    /** Keys are the API contract; the original five must not drift. */
    public function test_the_original_types_keep_their_keys(): void
    {
        foreach (['invoice', 'estimate', 'project', 'task', 'ticket'] as $key) {
            $this->assertTrue(TrashRegistry::has($key), "type `{$key}` was renamed — existing links would break");
        }
    }

    /* ── Behaviour ───────────────────────────────────────────────────────── */

    public function test_a_deleted_record_appears_in_the_bin_and_comes_back(): void
    {
        $admin = $this->admin();
        $project = $this->project('Jetty refurbishment', $admin);
        $project->delete();

        Sanctum::actingAs($admin);
        $body = $this->getJson('/api/settings/recycle-bin')->assertOk()->json();

        $row = collect($body['data'])->firstWhere('type', 'project');
        $this->assertNotNull($row, 'a deleted project must be listed');
        $this->assertSame('Jetty refurbishment', $row['label']);
        $this->assertSame('Work', $row['group']);

        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'project', 'id' => $project->id])
            ->assertOk()
            ->assertJsonPath('restored', true);

        $this->assertNotSoftDeleted('projects', ['id' => $project->id]);

        // And it is gone from the bin, because it is no longer deleted.
        $after = $this->getJson('/api/settings/recycle-bin')->assertOk()->json('data');
        $this->assertNull(collect($after)->firstWhere('type', 'project'));
    }

    /** Counts drive the filter chips, so they must reflect what is really there. */
    public function test_counts_are_reported_per_type(): void
    {
        $admin = $this->admin();
        foreach (['One', 'Two'] as $name) {
            $this->project($name, $admin)->delete();
        }
        $this->task('A task', $admin)->delete();

        Sanctum::actingAs($admin);
        $body = $this->getJson('/api/settings/recycle-bin')->assertOk()->json();

        $types = collect($body['types'])->keyBy('value');
        $this->assertSame(2, $types['project']['count']);
        $this->assertSame(1, $types['task']['count']);
        $this->assertSame(3, $body['total']);

        // A type holding nothing is not offered as a filter.
        $this->assertFalse($types->has('invoice'));
    }

    public function test_filtering_by_type_and_searching_by_label(): void
    {
        $admin = $this->admin();
        $this->project('Jetty refurbishment', $admin)->delete();
        $this->project('Warehouse roof', $admin)->delete();
        $this->task('A task', $admin)->delete();

        Sanctum::actingAs($admin);

        $onlyTasks = $this->getJson('/api/settings/recycle-bin?type=task')->assertOk()->json('data');
        $this->assertCount(1, $onlyTasks);
        $this->assertSame('task', $onlyTasks[0]['type']);

        $search = $this->getJson('/api/settings/recycle-bin?q=jetty')->assertOk()->json('data');
        $this->assertCount(1, $search);
        $this->assertSame('Jetty refurbishment', $search[0]['label']);
    }

    /** One tenant can never see, or restore, another tenant's deleted rows. */
    public function test_the_bin_is_tenant_scoped(): void
    {
        $admin = $this->admin();

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = $this->project('Not yours', $admin, tenantId: 2);
        $theirs->delete();

        Sanctum::actingAs($admin);

        $this->assertSame([], $this->getJson('/api/settings/recycle-bin')->assertOk()->json('data'));

        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'project', 'id' => $theirs->id])
            ->assertNotFound();

        $this->assertSoftDeleted('projects', ['id' => $theirs->id]);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/settings/recycle-bin/restore', ['type' => 'not_a_type', 'id' => 1])
            ->assertStatus(422);
    }
}
