<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseMomIssue;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The endpoints the SHARED meeting UI calls that Purchase did not have.
 *
 * The shared meeting screens make 50 distinct API calls between them; Purchase
 * answered 30. Mounting the shared UI without the rest would produce pages that
 * look right and whose buttons 404, which is worse than the thinner pages they
 * replace. These cover the ones added to close that gap.
 */
class PurchaseMeetingParityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name = 'ParityCo'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    private function meeting(PurchaseVendor $v, string $title = 'Weekly'): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'title' => $title, 'meeting_type' => 'kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay(), 'meeting_no' => 'PM-'.strtoupper(Str::random(5)),
        ]);
    }

    private function action(PurchaseKickoffMeeting $m, string $desc = 'Do the thing'): PurchaseMomActionItem
    {
        return PurchaseMomActionItem::create([
            'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
            'action_ref' => 'A-'.strtoupper(Str::random(5)), 'description' => $desc,
            'responsible_names' => 'Sam Owner', 'status' => 'Open',
            'priority' => 'High', 'target_date' => now()->addWeek()->toDateString(),
        ]);
    }

    /** Meeting history, newest first, narrowable to one vendor. */
    public function test_history_returns_meetings_newest_first(): void
    {
        Sanctum::actingAs($this->admin());

        $a = $this->vendor('HistoryCo');
        $b = $this->vendor('OtherCo');
        $this->meeting($a, 'Older')->forceFill(['scheduled_at' => now()->subMonth()])->save();
        $this->meeting($a, 'Newer')->forceFill(['scheduled_at' => now()->subDay()])->save();
        $this->meeting($b, 'Someone else');

        $all = $this->getJson('/api/purchase/kickoff/history')->assertOk()->json();
        $this->assertCount(3, $all);
        $this->assertSame('Newer', $all[0]['title'], 'history is not newest-first');

        $mine = $this->getJson('/api/purchase/kickoff/history?vendor_id='.$a->id)->assertOk()->json();
        $this->assertCount(2, $mine);
        $this->assertSame(['HistoryCo', 'HistoryCo'], array_column($mine, 'vendor'));
    }

    /**
     * Projects and customers are shared-engine subjects Purchase does not have.
     * They must answer 200 with an empty list — the shared form requests both on
     * mount, and a 404 would surface as an error on a working screen.
     */
    public function test_unsupported_subject_pickers_answer_empty_not_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/purchase/kickoff/projects')->assertOk()->assertExactJson([]);
        $this->getJson('/api/purchase/kickoff/customers')->assertOk()->assertExactJson([]);
    }

    /** An action becomes a real Task, linked back to the vendor. */
    public function test_action_can_be_pushed_to_a_task(): void
    {
        Sanctum::actingAs($this->admin());

        $vendor = $this->vendor();
        $m = $this->meeting($vendor);
        $a = $this->action($m, 'Replace the damaged harness');

        $this->postJson("/api/purchase/kickoff/{$m->id}/actions/{$a->id}/push-task")->assertOk();

        $a->refresh();
        $this->assertNotNull($a->task_id, 'the action was not linked to a task');

        $task = \DB::table('tasks')->find($a->task_id);
        $this->assertNotNull($task, 'no task row was created');
        $this->assertSame('Replace the damaged harness', $task->name);
        // Linked to the vendor so it shows on that vendor's Tasks tab.
        $this->assertSame('purchase_vendor', $task->rel_type);
        $this->assertSame($vendor->id, (int) $task->rel_id);
    }

    /** Pushing twice must not mint a second task for the same action. */
    public function test_pushing_an_action_twice_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $m = $this->meeting($this->vendor());
        $a = $this->action($m);

        $this->postJson("/api/purchase/kickoff/{$m->id}/actions/{$a->id}/push-task")->assertOk();
        $firstTaskId = $a->fresh()->task_id;

        $this->postJson("/api/purchase/kickoff/{$m->id}/actions/{$a->id}/push-task")
            ->assertStatus(422);

        $this->assertSame($firstTaskId, $a->fresh()->task_id, 'the second push replaced the task link');
        $this->assertSame(1, \DB::table('tasks')->where('id', $firstTaskId)->count());
    }

    /** An action belonging to another meeting is not reachable through this one. */
    public function test_push_task_rejects_an_action_from_another_meeting(): void
    {
        Sanctum::actingAs($this->admin());

        $mine = $this->meeting($this->vendor('MineCo'));
        $other = $this->meeting($this->vendor('OtherCo'));
        $a = $this->action($other);

        $this->postJson("/api/purchase/kickoff/{$mine->id}/actions/{$a->id}/push-task")
            ->assertNotFound();
    }

    /**
     * `task` joins ncr/capa/approval as a conversion target. Plenty of issues
     * are "someone go and do this" and do not warrant an NCR.
     */
    public function test_issue_can_be_converted_to_a_task(): void
    {
        Sanctum::actingAs($this->admin());

        $vendor = $this->vendor();
        $m = $this->meeting($vendor);
        $issue = PurchaseMomIssue::create([
            'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
            'issue_ref' => 'I-'.strtoupper(Str::random(5)), 'title' => 'Gate lighting is out',
            'description' => 'The east gate lamp has failed.', 'category' => 'HSSE',
            'severity' => 'High', 'owner_names' => 'Sam Owner', 'status' => 'Open',
        ]);

        $this->postJson("/api/purchase/kickoff/{$m->id}/issues/{$issue->id}/convert", ['target' => 'task'])
            ->assertOk();

        $issue->refresh();
        $this->assertSame('Task', $issue->converted_to);
        $this->assertNotNull($issue->converted_id);

        $task = \DB::table('tasks')->find($issue->converted_id);
        $this->assertNotNull($task);
        $this->assertSame('Gate lighting is out', $task->name);
        $this->assertSame('high', $task->priority, 'High severity should map to high priority');
    }

    /** Converting twice is refused, as it already was for the other targets. */
    public function test_converting_an_issue_twice_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $m = $this->meeting($this->vendor());
        $issue = PurchaseMomIssue::create([
            'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
            'issue_ref' => 'I-'.strtoupper(Str::random(5)), 'title' => 'Once only',
            'description' => 'x', 'category' => 'HSSE', 'severity' => 'Low',
            'owner_names' => 'Sam', 'status' => 'Open',
        ]);

        $this->postJson("/api/purchase/kickoff/{$m->id}/issues/{$issue->id}/convert", ['target' => 'task'])->assertOk();
        $this->postJson("/api/purchase/kickoff/{$m->id}/issues/{$issue->id}/convert", ['target' => 'task'])->assertStatus(422);

        $this->assertSame(1, \DB::table('tasks')->where('id', $issue->fresh()->converted_id)->count());
    }

    /**
     * Meeting types are served by the SHARED controller mounted on Purchase —
     * one tenant-scoped table, not a duplicate that would drift.
     */
    public function test_meeting_type_settings_are_reachable_on_purchase(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/purchase/meeting-type-settings')->assertOk();

        $created = $this->postJson('/api/purchase/meeting-type-settings', [
            'key' => 'site_walk', 'label' => 'Site Walk',
        ])->assertCreated()->json();

        $id = $created['id'] ?? $created['data']['id'] ?? null;
        $this->assertNotNull($id, 'the created meeting type has no id');

        $this->putJson("/api/purchase/meeting-type-settings/{$id}", [
            'key' => 'site_walk', 'label' => 'Site Walkdown',
        ])->assertOk();

        $this->deleteJson("/api/purchase/meeting-type-settings/{$id}")->assertOk();
    }

    /**
     * The register reports the task link once an action has been pushed, so an
     * item already being chased as a task is not chased twice.
     */
    public function test_action_register_reports_the_task_link(): void
    {
        Sanctum::actingAs($this->admin());

        $m = $this->meeting($this->vendor());
        $a = $this->action($m);

        $before = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->json();
        $this->assertNull($before[0]['task_id']);

        $this->postJson("/api/purchase/kickoff/{$m->id}/actions/{$a->id}/push-task")->assertOk();

        $after = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->json();
        $this->assertSame($a->fresh()->task_id, $after[0]['task_id']);
    }
}
