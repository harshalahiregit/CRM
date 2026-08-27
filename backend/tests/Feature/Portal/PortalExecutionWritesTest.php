<?php

namespace Tests\Feature\Portal;

use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\StatusService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Execution vendor-writes on the TPV portal: raise/reply a ticket, advance an own
 * task's status, and log an expense on an own project.
 */
class PortalExecutionWritesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private Vendor $vendor;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE, 'email' => 'acme@test.local']);
        $this->user = User::create(['tenant_id' => self::TENANT, 'name' => 'Acme Portal', 'role' => 'third_party_vendor', 'email' => 'acme@test.local', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->vendor->update(['user_id' => $this->user->id]);
    }

    private function project(): Project
    {
        $p = new Project();
        $p->forceFill(['tenant_id' => self::TENANT, 'name' => 'Shed', 'status' => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01', 'created_by' => $this->user->id, 'vendor_user_id' => $this->user->id])->save();

        return $p;
    }

    public function test_vendor_raises_lists_and_replies_to_a_ticket(): void
    {
        Sanctum::actingAs($this->user);

        $id = $this->postJson('/api/portal/my-work/tickets', ['subject' => 'Access issue', 'body' => 'Cannot enter gate'])
            ->assertCreated()->json('id');

        // Appears in the vendor's own ticket list (raised-by path).
        $list = $this->getJson('/api/portal/my-work/tickets')->assertOk()->json('data');
        $this->assertContains($id, collect($list)->pluck('id')->all());

        $this->getJson("/api/portal/my-work/tickets/{$id}")->assertOk()->assertJsonPath('subject', 'Access issue');
        $this->postJson("/api/portal/my-work/tickets/{$id}/reply", ['message' => 'Still blocked'])->assertCreated();
        $this->getJson("/api/portal/my-work/tickets/{$id}")->assertOk()
            ->assertJsonPath('replies.0.message', 'Still blocked')
            ->assertJsonPath('replies.0.mine', true);
    }

    public function test_vendor_advances_its_task_status(): void
    {
        $task = new Task();
        $task->forceFill(['tenant_id' => self::TENANT, 'name' => 'Weld', 'status' => 'not_started', 'start_date' => '2026-01-01', 'created_by' => $this->user->id, 'rel_type' => 'tpv_vendor', 'rel_id' => $this->vendor->id])->save();

        $status = app(StatusService::class)->keys('task', self::TENANT)[0] ?? 'in_progress';

        Sanctum::actingAs($this->user);
        $this->patchJson("/api/portal/my-work/tasks/{$task->id}/status", ['status' => $status])
            ->assertOk()->assertJsonPath('data.status', $status);
    }

    public function test_vendor_logs_an_expense_on_its_project(): void
    {
        $project = $this->project();

        Sanctum::actingAs($this->user);
        $this->postJson('/api/portal/my-work/expenses', ['project_id' => $project->id, 'title' => 'Consumables', 'amount' => 500])
            ->assertCreated()->assertJsonPath('project_id', $project->id);

        $this->getJson('/api/portal/my-work/expenses')->assertOk()->assertJsonPath('data.0.title', 'Consumables');
    }

    public function test_vendor_cannot_touch_a_foreign_task(): void
    {
        $other = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Beta', 'status' => VendorStatus::ACTIVE, 'email' => 'beta@test.local']);
        $task = new Task();
        $task->forceFill(['tenant_id' => self::TENANT, 'name' => 'Theirs', 'status' => 'not_started', 'start_date' => '2026-01-01', 'created_by' => $this->user->id, 'rel_type' => 'tpv_vendor', 'rel_id' => $other->id])->save();

        Sanctum::actingAs($this->user);
        $this->patchJson("/api/portal/my-work/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertNotFound();
    }
}
