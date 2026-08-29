<?php

namespace Tests\Feature\Project;

use App\Models\Customer\Client;
use App\Models\Project\Project;
use App\Models\Sales\Estimate;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PR2 — a project converts into a Sales Proforma Invoice built from its billable
 * tasks (each task's effective amount → a line). Milestone / selected-task scopes
 * narrow which tasks are billed.
 */
class ProjectConvertToProformaTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->uid = $this->user('admin')->id;
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function project(?int $clientId = null): Project
    {
        $p = new Project();
        $p->forceFill([
            'tenant_id' => self::TENANT, 'name' => 'Shed Build', 'status' => 'in_progress',
            'progress' => 0, 'start_date' => '2026-01-01', 'created_by' => $this->uid, 'customer_id' => $clientId,
        ])->save();

        return $p;
    }

    private function task(int $projectId, array $over = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Task', 'status' => 'not_started', 'priority' => 'medium',
            'start_date' => '2026-01-01', 'created_by' => $this->uid,
            'rel_type' => 'project', 'rel_id' => $projectId, 'billable' => true,
        ], $over));
    }

    public function test_project_converts_to_a_proforma_with_task_lines(): void
    {
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Acme', 'active' => 1]);
        $project = $this->project($client->id);
        $this->task($project->id, ['name' => 'Fabrication', 'billable_amount' => 50000]);
        $this->task($project->id, ['name' => 'Erection', 'billable_amount' => 30000]);
        $this->task($project->id, ['name' => 'Non-billable', 'billable' => false, 'billable_amount' => 9999]);

        Sanctum::actingAs(User::find($this->uid));
        $res = $this->postJson("/api/projects/{$project->id}/convert-to-proforma")->assertCreated();

        $estimateId = $res->json('data.id');
        $estimate = Estimate::find($estimateId);
        $this->assertSame('proforma', $estimate->estimate_type);
        $this->assertSame($client->id, (int) $estimate->client_id);
        // Two billable tasks became lines; the non-billable one did not.
        $this->assertCount(2, $estimate->lineItems);
        $this->assertEqualsWithDelta(80000, (float) $estimate->total, 0.01);
    }

    public function test_milestone_scope_bills_only_that_milestones_tasks(): void
    {
        $project = $this->project();
        $ms = \App\Models\Project\ProjectMilestone::create([
            'tenant_id' => self::TENANT, 'project_id' => $project->id, 'name' => 'Phase 1', 'order' => 1,
            'due_date' => '2026-02-01',
        ]);
        $this->task($project->id, ['name' => 'In phase', 'milestone_id' => $ms->id, 'billable_amount' => 10000]);
        $this->task($project->id, ['name' => 'Out of phase', 'billable_amount' => 20000]);

        Sanctum::actingAs(User::find($this->uid));
        $res = $this->postJson("/api/projects/{$project->id}/convert-to-proforma", [
            'scope' => 'milestone', 'milestone_id' => $ms->id,
        ])->assertCreated();

        $estimate = Estimate::find($res->json('data.id'));
        $this->assertCount(1, $estimate->lineItems);
        $this->assertEqualsWithDelta(10000, (float) $estimate->total, 0.01);
    }

    public function test_nothing_billable_is_rejected(): void
    {
        $project = $this->project();
        $this->task($project->id, ['name' => 'Unpriced', 'billable_amount' => null, 'hourly_rate' => 0]);

        Sanctum::actingAs(User::find($this->uid));
        $this->postJson("/api/projects/{$project->id}/convert-to-proforma")->assertStatus(422);
    }
}
