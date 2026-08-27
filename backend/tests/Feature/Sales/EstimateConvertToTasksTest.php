<?php

namespace Tests\Feature\Sales;

use App\Models\Project\Project;
use App\Models\Sales\Estimate;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\EstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PI10 — a Proforma Invoice / Estimate's line items become Tasks (their value as
 * each task's fixed billable amount), linked to the document's project when set.
 */
class EstimateConvertToTasksTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->uid = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ])->id;
    }

    private function estimate(array $lines, ?int $projectId = null): Estimate
    {
        return app(EstimateService::class)->create([
            'subject' => 'Shed job', 'date' => '2026-01-01', 'estimate_type' => 'proforma',
            'currency' => 'INR', 'status' => 'Draft', 'project_id' => $projectId,
        ], $lines, self::TENANT, $this->uid);
    }

    public function test_each_line_item_becomes_a_task(): void
    {
        $estimate = $this->estimate([
            ['item_name' => 'Fabrication', 'qty' => 2, 'rate' => 5000, 'tax' => 0],
            ['item_name' => 'Erection', 'qty' => 1, 'rate' => 3000, 'tax' => 0],
        ]);

        Sanctum::actingAs(User::find($this->uid));
        $res = $this->postJson("/api/sales/estimates/{$estimate->id}/convert-to-tasks")
            ->assertCreated()
            ->assertJsonPath('created', 2);

        $names = Task::where('tenant_id', self::TENANT)->pluck('name')->all();
        $this->assertContains('Fabrication', $names);
        $this->assertContains('Erection', $names);

        $fab = Task::where('name', 'Fabrication')->first();
        $this->assertTrue((bool) $fab->billable);
        $this->assertSame(10000.0, $fab->effectiveBillableAmount());   // 2 × 5000
    }

    public function test_tasks_link_to_the_documents_project(): void
    {
        $project = (new Project());
        $project->forceFill(['tenant_id' => self::TENANT, 'name' => 'Shed', 'status' => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01', 'created_by' => $this->uid])->save();

        $estimate = $this->estimate([['item_name' => 'Work', 'qty' => 1, 'rate' => 1000, 'tax' => 0]], $project->id);

        Sanctum::actingAs(User::find($this->uid));
        $this->postJson("/api/sales/estimates/{$estimate->id}/convert-to-tasks")->assertCreated();

        $task = Task::where('name', 'Work')->first();
        $this->assertSame('project', $task->rel_type);
        $this->assertSame($project->id, (int) $task->rel_id);
    }

    public function test_another_tenants_estimate_is_unreachable(): void
    {
        (new Tenant())->forceFill(['id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active'])->save();
        $foreignAdmin = User::create(['tenant_id' => 2, 'name' => 'A2', 'role' => 'admin', 'email' => 'a2-'.Str::random(4).'@t.local', 'password' => bcrypt('x'), 'status' => 'active']);

        $estimate = $this->estimate([['item_name' => 'X', 'qty' => 1, 'rate' => 1, 'tax' => 0]]);

        Sanctum::actingAs($foreignAdmin);
        $this->postJson("/api/sales/estimates/{$estimate->id}/convert-to-tasks")->assertNotFound();
    }
}
