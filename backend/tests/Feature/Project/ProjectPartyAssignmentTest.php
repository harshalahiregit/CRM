<?php

namespace Tests\Feature\Project;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Project\Project;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\PartyAssignee;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vendors can be put on a PROJECT, not only on its tasks.
 *
 * The project's Vendors tab could only DERIVE who was involved, by reading the
 * assignees off the project's tasks. A vendor engaged for the project as a whole
 * — before a single task exists, which is the normal order — appeared nowhere,
 * so the tab read "no vendors are assigned to this project's tasks yet" on a
 * project with three vendors on site.
 *
 * The same engine serves both, so the tests that matter most here are the ones
 * about the two NOT bleeding into each other: one table now holds both, and a
 * task and a project can share an id.
 */
class ProjectPartyAssignmentTest extends TestCase
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

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Super Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function project(string $name = 'Warehouse fit-out'): Project
    {
        return Project::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
    }

    /** @return array{0:Client,1:ClientContact} */
    private function clientWithContact(string $company = 'Northwind Traders', string $first = 'Sunita'): array
    {
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => $company,
            'email' => 'ap-'.Str::random(4).'@'.Str::slug($company).'.local',
        ]);
        $contact = ClientContact::create([
            'tenant_id' => self::TENANT, 'client_id' => $client->id,
            'first_name' => $first, 'last_name' => 'Rao',
            'email' => strtolower($first).'-'.Str::random(4).'@'.Str::slug($company).'.local',
            'title' => 'Project Coordinator', 'active' => true, 'portal_status' => 'active',
        ]);

        return [$client, $contact];
    }

    /** @return array{0:PurchaseVendor,1:PurchaseContact} */
    private function vendorWithContact(string $company = 'Southgate'): array
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $contact = PurchaseContact::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'first_name' => 'Rakesh', 'last_name' => 'Iyer', 'designation' => 'Site Manager',
            'email' => 'rakesh-'.Str::random(4).'@southgate.local', 'status' => 'Active',
        ]);

        return [$vendor, $contact];
    }

    private function assign(int $projectId, array $parties)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/projects/{$projectId}/party-assignees", ['parties' => $parties]);
    }

    /* ── the point ──────────────────────────────────────────────── */

    public function test_a_vendor_can_be_put_on_a_project_with_no_tasks_at_all(): void
    {
        $project = $this->project();
        [, $rakesh] = $this->vendorWithContact();

        $chips = $this->assign($project->id, [
            ['party_type' => 'purchase_contact', 'party_id' => $rakesh->id],
        ])->assertOk()->json('data');

        $this->assertCount(1, $chips);
        $this->assertSame('Rakesh Iyer', $chips[0]['name']);
        // The chip names the company too — "Rakesh" alone is not an instruction
        // when three vendors each have one.
        $this->assertSame('Vendor', $chips[0]['org_label']);
        $this->assertSame(0, Task::where('rel_type', 'project')->count());
    }

    public function test_people_from_all_three_kinds_of_team_can_be_on_one_project(): void
    {
        $project = $this->project();
        [, $sunita] = $this->clientWithContact();
        [, $rakesh] = $this->vendorWithContact();

        $chips = $this->assign($project->id, [
            ['party_type' => 'client_contact', 'party_id' => $sunita->id],
            ['party_type' => 'purchase_contact', 'party_id' => $rakesh->id],
        ])->assertOk()->json('data');

        $names = array_column($chips, 'name');
        sort($names);
        $this->assertSame(['Rakesh Iyer', 'Sunita Rao'], $names);
    }

    public function test_the_list_posted_is_the_list_kept(): void
    {
        $project = $this->project();
        [, $sunita] = $this->clientWithContact('Northwind Traders', 'Sunita');
        [, $farah] = $this->clientWithContact('Contoso', 'Farah');

        $this->assign($project->id, [
            ['party_type' => 'client_contact', 'party_id' => $sunita->id],
            ['party_type' => 'client_contact', 'party_id' => $farah->id],
        ])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->assertCount(2, $this->getJson("/api/projects/{$project->id}/party-assignees")->assertOk()->json('data'));

        // Replace, not merge — the same contract as the task endpoint.
        $this->assign($project->id, [['party_type' => 'client_contact', 'party_id' => $sunita->id]])->assertOk();
        $rows = $this->getJson("/api/projects/{$project->id}/party-assignees")->assertOk()->json('data');
        $this->assertSame([$sunita->id], array_column($rows, 'party_id'));

        // And an empty list clears them.
        $this->assign($project->id, [])->assertOk();
        $this->assertSame([], $this->getJson("/api/projects/{$project->id}/party-assignees")->assertOk()->json('data'));
    }

    /* ── the two subjects must not bleed ────────────────────────── */

    public function test_a_project_and_a_task_with_the_same_id_do_not_share_people(): void
    {
        $project = $this->project();

        // Force a task onto the SAME id as the project. One table holds both
        // now, so an unfiltered subject_id lookup would mix them — and ids
        // colliding across two tables is normal, not exotic.
        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'A task', 'status' => 'not_started',
            'priority' => 'medium', 'start_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        $task->forceFill(['id' => $project->id, 'root_id' => $project->id])->save();

        [, $sunita] = $this->clientWithContact('Northwind Traders', 'Sunita');
        [, $farah] = $this->clientWithContact('Contoso', 'Farah');

        $this->assign($project->id, [['party_type' => 'client_contact', 'party_id' => $sunita->id]])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tasks/{$task->id}/party-assignees", [
            'parties' => [['party_type' => 'client_contact', 'party_id' => $farah->id]],
        ])->assertOk();

        $projectPeople = $this->getJson("/api/projects/{$project->id}/party-assignees")->assertOk()->json('data');
        $taskPeople = $this->getJson("/api/tasks/{$task->id}")->assertOk()->json('data.party_assignees');

        $this->assertSame(['Sunita Rao'], array_column($projectPeople, 'name'));
        $this->assertSame(['Farah Rao'], array_column($taskPeople, 'name'));
    }

    public function test_a_project_assignment_does_not_show_up_in_the_portal_task_list(): void
    {
        $project = $this->project();
        [, $sunita] = $this->clientWithContact();

        $this->assign($project->id, [['party_type' => 'client_contact', 'party_id' => $sunita->id]])->assertOk();

        // The portal turns assignment rows into TASK ids. Without a subject_type
        // filter a project id would be read as a task id — and a task with that
        // id may well exist.
        Sanctum::actingAs($sunita);
        $this->assertSame([], $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data'));
    }

    /* ── the usual refusals ─────────────────────────────────────── */

    public function test_a_project_in_another_tenant_is_not_found(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = Project::create([
            'tenant_id' => 2, 'name' => 'Someone elses', 'start_date' => now()->toDateString(),
            'created_by' => $this->admin->id,   // projects.created_by is NOT NULL
        ]);
        [, $sunita] = $this->clientWithContact();

        // 404, not 403 — confirming the id is real is how an id range gets walked.
        $this->assign($foreign->id, [['party_type' => 'client_contact', 'party_id' => $sunita->id]])
            ->assertStatus(404);

        $this->assertSame(0, PartyAssignee::count());
    }

    public function test_an_inactive_contact_cannot_be_put_on_a_project(): void
    {
        $project = $this->project();
        [, $rakesh] = $this->vendorWithContact();
        $rakesh->update(['status' => 'Inactive']);

        $this->assign($project->id, [['party_type' => 'purchase_contact', 'party_id' => $rakesh->id]])
            ->assertStatus(422);

        $this->assertSame(0, PartyAssignee::count());
    }

    public function test_assigning_the_same_person_twice_does_not_duplicate_them(): void
    {
        $project = $this->project();
        [, $sunita] = $this->clientWithContact();
        $one = [['party_type' => 'client_contact', 'party_id' => $sunita->id]];

        $this->assign($project->id, $one)->assertOk();
        $this->assign($project->id, $one)->assertOk();

        $this->assertSame(1, PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_PROJECT)->count());
    }
}
