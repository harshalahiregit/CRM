<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Report;
use Sire\Models\ReportSeverity;
use Tests\TestCase;

/**
 * Assigning an issue to more than one person, and to the right KIND of person.
 *
 * ONE OWNER, SEVERAL WORKING IT. sire_reports.assignee_id stays the owner —
 * every workflow guard is written against it, and a defect with four equal
 * owners has none. The rest go in sire_report_assignees.
 *
 * STAFF AND CUSTOMERS ARE DIFFERENT. Both are people an issue can be put
 * against; a flat dropdown of both is how a production defect gets assigned to a
 * client by mistake.
 */
class SireMultiAssignTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $dev;
    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'name' => 'Asha Lead',
        ]);
        $this->dev = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'staff', 'name' => 'Dev Kumar',
            'department' => 'Engineering', 'designation' => 'Senior Developer',
        ]);
        $this->second = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'staff', 'name' => 'Nina Pair',
            'department' => 'Engineering', 'designation' => 'Developer',
        ]);
    }

    private function triagedIssue(): int
    {
        Sanctum::actingAs($this->admin);

        $id = (int) $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated()->json('data.report.id');

        $severity = ReportSeverity::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 's2', 'name' => 'High', 'level' => 3,
        ]);

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'triage', 'severity_id' => $severity->id, 'priority' => 'p2',
        ])->assertOk();

        return $id;
    }

    private function coAssignees(int $reportId): array
    {
        return DB::table('sire_report_assignees')
            ->where('report_id', $reportId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // -------------------------------------------- the picker tells them apart

    public function test_staff_and_customers_arrive_as_separate_kinds(): void
    {
        $client = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'client', 'name' => 'A Customer',
        ]);

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/sire/dashboard/options')->assertOk()->json('data.assignees'))
            ->keyBy('id');

        $this->assertSame('staff', $rows[$this->dev->id]['kind']);
        $this->assertSame('customer', $rows[$client->id]['kind']);

        // Staff lead the list, so the person you meant is near the top.
        $first = collect($this->getJson('/api/sire/dashboard/options')->json('data.assignees'))->first();
        $this->assertSame('staff', $first['kind']);
    }

    public function test_staff_management_fields_reach_the_picker(): void
    {
        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/sire/dashboard/options')->assertOk()->json('data.assignees'))
            ->keyBy('id');

        // "Dev Kumar" is only useful to somebody who already knows who that is.
        $this->assertSame('Engineering', $rows[$this->dev->id]['department']);
        $this->assertSame('Senior Developer', $rows[$this->dev->id]['designation']);
    }

    // ------------------------------------------------------- several assignees

    public function test_an_issue_can_go_to_several_people_with_one_owner(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action'          => 'assign',
            'assignee_id'     => $this->dev->id,
            'co_assignee_ids' => [$this->second->id],
        ])->assertOk();

        $this->assertSame($this->dev->id, (int) Report::findOrFail($id)->assignee_id);
        $this->assertSame([$this->second->id], $this->coAssignees($id));
    }

    public function test_the_owner_is_never_duplicated_into_the_team(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action'          => 'assign',
            'assignee_id'     => $this->dev->id,
            // The client sends the whole ticked list; the owner must not land in
            // both places, or every read has to remember to de-duplicate.
            'co_assignee_ids' => [$this->dev->id, $this->second->id],
        ])->assertOk();

        $this->assertSame([$this->second->id], $this->coAssignees($id));
    }

    public function test_the_team_survives_an_unrelated_transition(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id,
            'co_assignee_ids' => [$this->second->id],
        ])->assertOk();

        // No co_assignee_ids key at all: "I am not changing the team", never
        // "clear it". Otherwise every hold and every triage would empty it.
        Sanctum::actingAs($this->dev);
        $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => 'start_development'])
            ->assertOk();

        $this->assertSame([$this->second->id], $this->coAssignees($id));
    }

    public function test_an_empty_list_clears_the_team_deliberately(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id,
            'co_assignee_ids' => [$this->second->id],
        ])->assertOk();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'unassign',
        ])->assertOk();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id, 'co_assignee_ids' => [],
        ])->assertOk();

        $this->assertSame([], $this->coAssignees($id));
    }

    public function test_the_team_comes_back_on_the_issue(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id,
            'co_assignee_ids' => [$this->second->id],
        ])->assertOk();

        $names = collect($this->getJson("/api/sire/reports/{$id}")->assertOk()->json('data.co_assignees'))
            ->pluck('display_name');

        $this->assertSame(['Nina Pair'], $names->all());
    }

    public function test_everyone_working_it_is_notified_not_just_the_owner(): void
    {
        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id,
            'co_assignee_ids' => [$this->second->id],
        ])->assertOk();

        // Being the second name on an issue should not mean hearing about it
        // second-hand.
        Sanctum::actingAs($this->dev);
        $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => 'start_development'])->assertOk();
        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'mark_ready_for_qa', 'fix_summary' => 'Added the missing null check.',
        ])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => 'start_qa'])->assertOk();
        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'qa_fail', 'qa_notes' => 'Still fails on the bulk path.',
        ])->assertOk();

        $told = DB::table('notifications')->where('type', 'sire.qa.failed')->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        $this->assertTrue($told->contains($this->dev->id), 'the owner must be told');
        $this->assertTrue($told->contains($this->second->id), 'so must everyone working it');
    }

    public function test_another_tenants_person_cannot_be_added(): void
    {
        $outsider = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id, 'role' => 'staff',
        ]);

        $id = $this->triagedIssue();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id,
            'co_assignee_ids' => [$outsider->id],
        ])->assertStatus(422);

        $this->assertSame([], $this->coAssignees($id));
    }

    public function test_the_team_is_capped(): void
    {
        $many = collect(range(1, 11))->map(fn () => User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'staff',
        ])->id)->all();

        $id = $this->triagedIssue();

        // An issue assigned to twelve people is assigned to nobody.
        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->dev->id, 'co_assignee_ids' => $many,
        ])->assertStatus(422);
    }
}
