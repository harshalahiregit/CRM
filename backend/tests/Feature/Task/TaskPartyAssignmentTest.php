<?php

namespace Tests\Feature\Task;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Task\Task;
use App\Models\Shared\PartyAssignee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assigning a task to a named person at another company, and what they then see.
 *
 * Before this, "assigned" meant a users row and nothing else. A client's
 * coordinator, a vendor's store manager, a TPV's site supervisor could not be
 * put on a task at all — the screen offered "Related to (company)" instead,
 * which files a task against an organisation and assigns it to nobody. So the
 * commonest instruction in this business — "Rakesh at Southgate is doing this
 * one" — had nowhere to live, and the person doing the work had no way to see it.
 *
 * The half that matters most is the second one. Assignment that the assignee
 * cannot open is worse than no assignment: it looks done from the inside and is
 * invisible from the outside. So every test that assigns somebody also reads
 * back as them.
 */
class TaskPartyAssignmentTest extends TestCase
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

    /* ── fixtures ───────────────────────────────────────────────── */

    private function task(string $name = 'Install the gantry', ?int $parentId = null): Task
    {
        $parent = $parentId ? Task::find($parentId) : null;

        return Task::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'status' => 'not_started', 'priority' => 'medium',
            // tasks.start_date is NOT NULL.
            'start_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'parent_id' => $parentId,
            'root_id'   => $parent ? ($parent->root_id ?: $parent->id) : null,
            'depth'     => $parent ? (int) $parent->depth + 1 : 0,
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
            'title' => 'Project Coordinator',
            'active' => true, 'portal_status' => 'active',
        ]);

        return [$client, $contact];
    }

    /** @return array{0:PurchaseVendor,1:PurchaseContact} */
    private function purchaseVendorWithContact(string $company = 'Southgate'): array
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $contact = PurchaseContact::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'first_name' => 'Rakesh', 'last_name' => 'Iyer',
            'designation' => 'Store Manager',
            'email' => 'rakesh-'.Str::random(4).'@southgate.local',
            'status' => 'Active',
        ]);

        return [$vendor, $contact];
    }

    /** @return array{0:Vendor,1:VendorContact,2:User} */
    private function tpvWithContact(string $company = 'AlphaCo'): array
    {
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => $company, 'role' => 'third_party_vendor',
            'email' => 'alpha-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'email' => 'alpha-'.Str::random(6).'@vendor.local',
            'status' => 'Active', 'user_id' => $login->id,
        ]);
        $contact = VendorContact::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'name' => 'Meera Joshi', 'designation' => 'Site Supervisor',
            'email' => 'meera-'.Str::random(4).'@alpha.local',
        ]);

        return [$vendor, $contact, $login];
    }

    private function assign(Task $task, string $type, int $id): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/tasks/{$task->id}/party-assignees", [
            'parties' => [['party_type' => $type, 'party_id' => $id]],
        ]);
    }

    /* ── The picker ─────────────────────────────────────────────── */

    public function test_the_picker_walks_team_then_person_for_all_three_kinds(): void
    {
        [$client] = $this->clientWithContact();
        [$pv] = $this->purchaseVendorWithContact();
        [$tpv] = $this->tpvWithContact();

        Sanctum::actingAs($this->admin);

        $kinds = $this->getJson('/api/tasks/parties/kinds')->assertOk()->json('data');
        $this->assertSame(
            ['client', 'purchase_vendor', 'tpv_vendor'],
            array_column($kinds, 'org_type'),
            'all three kinds of team are offered'
        );

        foreach ([['client', $client->id], ['purchase_vendor', $pv->id], ['tpv_vendor', $tpv->id]] as [$kind, $orgId]) {
            $orgs = $this->getJson("/api/tasks/parties/{$kind}")->assertOk()->json('data');
            $this->assertContains($orgId, array_column($orgs, 'id'), "$kind team is listed");

            $people = $this->getJson("/api/tasks/parties/{$kind}/{$orgId}")->assertOk()->json('data');
            $this->assertCount(1, $people, "$kind team has its one contact");
            // Three tables spell a name three ways — first/last here, one `name`
            // column there. The picker must not care.
            $this->assertNotEmpty($people[0]['name']);
        }
    }

    public function test_the_picker_shows_only_that_teams_own_people(): void
    {
        [$a] = $this->clientWithContact('Northwind Traders', 'Sunita');
        [, $other] = $this->clientWithContact('Contoso', 'Farah');

        Sanctum::actingAs($this->admin);
        $people = $this->getJson("/api/tasks/parties/client/{$a->id}")->assertOk()->json('data');

        $this->assertCount(1, $people);
        $this->assertNotSame($other->id, $people[0]['party_id'],
            'a contact from another client must never appear under this one');
    }

    public function test_an_inactive_contact_is_not_offered_and_cannot_be_assigned(): void
    {
        [$vendor, $contact] = $this->purchaseVendorWithContact();
        $contact->update(['status' => 'Inactive']);

        Sanctum::actingAs($this->admin);

        $people = $this->getJson("/api/tasks/parties/purchase_vendor/{$vendor->id}")->assertOk()->json('data');
        $this->assertSame([], $people, 'somebody who has left is not a choice');

        // And the API refuses them even when the id is posted directly, because
        // a list that merely omits them is not a rule.
        $this->assign($this->task(), 'purchase_contact', $contact->id)
            ->assertStatus(422);
    }

    /* ── Assigning ──────────────────────────────────────────────── */

    public function test_a_task_can_be_assigned_to_a_person_at_each_kind_of_company(): void
    {
        $task = $this->task();
        [, $clientContact] = $this->clientWithContact();
        [, $purchaseContact] = $this->purchaseVendorWithContact();
        [, $tpvContact] = $this->tpvWithContact();

        Sanctum::actingAs($this->admin);
        $chips = $this->postJson("/api/tasks/{$task->id}/party-assignees", [
            'parties' => [
                ['party_type' => 'client_contact',   'party_id' => $clientContact->id],
                ['party_type' => 'purchase_contact', 'party_id' => $purchaseContact->id],
                ['party_type' => 'vendor_contact',   'party_id' => $tpvContact->id],
            ],
        ])->assertOk()->json('data');

        $this->assertCount(3, $chips);
        $names = array_column($chips, 'name');
        sort($names);
        $this->assertSame(['Meera Joshi', 'Rakesh Iyer', 'Sunita Rao'], $names);

        // The chip carries the company too — "Rakesh" on its own is not an
        // instruction when three vendors each have one.
        foreach ($chips as $chip) {
            $this->assertNotEmpty($chip['org_type']);
            $this->assertNotEmpty($chip['org_label']);
        }
    }

    public function test_the_list_posted_is_the_list_kept_so_removing_is_the_same_call(): void
    {
        $task = $this->task();
        [, $a] = $this->clientWithContact('Northwind Traders', 'Sunita');
        [, $b] = $this->clientWithContact('Contoso', 'Farah');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/tasks/{$task->id}/party-assignees", ['parties' => [
            ['party_type' => 'client_contact', 'party_id' => $a->id],
            ['party_type' => 'client_contact', 'party_id' => $b->id],
        ]])->assertOk();
        $this->assertSame(2, PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_TASK)->where('subject_id', $task->id)->count());

        // Post only one back — the other goes.
        $this->postJson("/api/tasks/{$task->id}/party-assignees", ['parties' => [
            ['party_type' => 'client_contact', 'party_id' => $a->id],
        ]])->assertOk();
        $this->assertSame([$a->id], PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_TASK)->where('subject_id', $task->id)->pluck('party_id')->all());

        // And an empty list clears them, rather than being read as "no change".
        $this->postJson("/api/tasks/{$task->id}/party-assignees", ['parties' => []])->assertOk();
        $this->assertSame(0, PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_TASK)->where('subject_id', $task->id)->count());
    }

    public function test_assigning_twice_does_not_duplicate_the_person(): void
    {
        $task = $this->task();
        [, $contact] = $this->clientWithContact();

        $this->assign($task, 'client_contact', $contact->id)->assertOk();
        $this->assign($task, 'client_contact', $contact->id)->assertOk();

        $this->assertSame(1, PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_TASK)->where('subject_id', $task->id)->count());
    }

    public function test_a_contact_from_another_tenant_cannot_be_assigned(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $foreignClient = Client::create(['tenant_id' => 2, 'company' => 'Elsewhere Ltd', 'email' => 'e@e.local']);
        $foreign = ClientContact::create([
            'tenant_id' => 2, 'client_id' => $foreignClient->id,
            'first_name' => 'Nope', 'last_name' => 'Nope', 'email' => 'nope@e.local', 'active' => true,
        ]);

        // The payload names a real contact id. The only thing standing between
        // it and this tenant's table is the tenant check in the service — which
        // is why the contact is re-read there instead of trusted from the body.
        $this->assign($this->task(), 'client_contact', $foreign->id)->assertStatus(422);

        $this->assertSame(0, PartyAssignee::count());
    }

    /* ── Seeing it, from their side ─────────────────────────────── */

    public function test_a_client_contact_sees_the_task_assigned_to_them_and_nothing_else(): void
    {
        $theirs = $this->task('Approve the layout');
        $this->task('Somebody elses work');
        $this->task('And another');

        [, $contact] = $this->clientWithContact();
        $this->assign($theirs, 'client_contact', $contact->id)->assertOk();

        // The client portal authenticates as the ClientContact itself.
        Sanctum::actingAs($contact);
        $rows = $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'three tasks exist; one is theirs');
        $this->assertSame('Approve the layout', $rows[0]['name']);
        $this->assertTrue($rows[0]['assigned_to_me']);
    }

    public function test_one_contact_cannot_see_another_contacts_task(): void
    {
        [, $sunita] = $this->clientWithContact('Northwind Traders', 'Sunita');
        [, $farah] = $this->clientWithContact('Contoso', 'Farah');

        $hers = $this->task('Farahs task');
        $this->assign($hers, 'client_contact', $farah->id)->assertOk();

        Sanctum::actingAs($sunita);

        $this->assertSame([], $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data'));
        // Not by naming it either — and a 404, not a 403, so the id itself
        // tells them nothing.
        $this->getJson("/api/portal/assigned-tasks/{$hers->id}")->assertStatus(404);
    }

    public function test_the_subtasks_of_an_assigned_task_come_with_it(): void
    {
        $parent = $this->task('Install the gantry');
        $child = $this->task('Pour the footings', $parent->id);
        $grandchild = $this->task('Order the rebar', $child->id);

        [, $contact] = $this->clientWithContact();
        $this->assign($parent, 'client_contact', $contact->id)->assertOk();

        Sanctum::actingAs($contact);
        $rows = $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data');

        // The work they were handed IS its subtasks. A parent whose children are
        // invisible gives them a percentage they cannot account for.
        $this->assertEqualsCanonicalizing(
            [$parent->id, $child->id, $grandchild->id],
            array_column($rows, 'id')
        );

        // But only the top one is what they were actually given.
        $mine = array_values(array_filter($rows, fn ($r) => $r['assigned_to_me']));
        $this->assertCount(1, $mine);
        $this->assertSame($parent->id, $mine[0]['id']);
    }

    public function test_the_tree_above_an_assigned_subtask_stays_shut(): void
    {
        $parent = $this->task('The whole project');
        $child = $this->task('Just this bit', $parent->id);

        [, $contact] = $this->clientWithContact();
        $this->assign($child, 'client_contact', $contact->id)->assertOk();

        Sanctum::actingAs($contact);
        $rows = $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data');

        $this->assertSame([$child->id], array_column($rows, 'id'),
            'being given one piece of a job is not being given the job');
        $this->getJson("/api/portal/assigned-tasks/{$parent->id}")->assertStatus(404);
    }

    public function test_an_unassigned_party_sees_nothing_rather_than_everything(): void
    {
        $this->task('One');
        $this->task('Two');

        [, $contact] = $this->clientWithContact();

        // Nothing is assigned to them at all. The dangerous failure here is an
        // empty filter, which in Eloquent is a no-op and would hand them the
        // whole tenant.
        Sanctum::actingAs($contact);
        $this->assertSame([], $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data'));
    }

    public function test_staff_are_turned_away_from_the_portal_endpoint(): void
    {
        // Staff have the task module. There must not be a second door into task
        // data with a different set of rules behind it.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/portal/assigned-tasks')->assertStatus(403);
    }

    public function test_a_purchase_vendor_login_sees_its_own_contacts_work(): void
    {
        [$vendor, $contact] = $this->purchaseVendorWithContact();
        [$otherVendor] = $this->purchaseVendorWithContact('Northpoint');

        $task = $this->task('Deliver the valves');
        $this->assign($task, 'purchase_contact', $contact->id)->assertOk();

        // The Purchase portal has no per-contact login — the vendor signs in as
        // the COMPANY. So its identity is every contact under it, and the row
        // says whose task it is rather than pretending the system can tell two
        // people at one vendor apart when it cannot.
        Sanctum::actingAs($vendor);
        $rows = $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Rakesh Iyer', $rows[0]['people'][0]['name']);

        // The other vendor is not shown it.
        Sanctum::actingAs($otherVendor);
        $this->assertSame([], $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data'));
    }

    public function test_a_tpv_login_sees_its_own_contacts_work(): void
    {
        [, $contact, $login] = $this->tpvWithContact();
        [, , $otherLogin] = $this->tpvWithContact('BetaCo');

        $task = $this->task('Mobilise the crew');
        $this->assign($task, 'vendor_contact', $contact->id)->assertOk();

        Sanctum::actingAs($login);
        $rows = $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Mobilise the crew', $rows[0]['name']);

        Sanctum::actingAs($otherLogin);
        $this->assertSame([], $this->getJson('/api/portal/assigned-tasks')->assertOk()->json('data'));
    }

    /* ── The chip on the admin side ─────────────────────────────── */

    public function test_the_task_carries_its_party_people_separately_from_its_staff(): void
    {
        $task = $this->task();
        [, $contact] = $this->clientWithContact();
        $this->assign($task, 'client_contact', $contact->id)->assertOk();

        Sanctum::actingAs($this->admin);
        $row = $this->getJson("/api/tasks/{$task->id}")->assertOk()->json('data');

        // Two lists, never merged: `assignees` is users, `party_assignees` is
        // people at other companies who may have no login here at all.
        $this->assertSame([], $row['assignees']);
        $this->assertCount(1, $row['party_assignees']);
        $this->assertSame('Sunita Rao', $row['party_assignees'][0]['name']);
        $this->assertSame('Northwind Traders', $this->clientNameOf($row['party_assignees'][0]));
    }

    private function clientNameOf(array $chip): string
    {
        return (string) Client::find($chip['org_id'])?->company;
    }

    public function test_a_renamed_contact_refreshes_on_the_next_save(): void
    {
        $task = $this->task();
        [, $contact] = $this->clientWithContact();
        $this->assign($task, 'client_contact', $contact->id)->assertOk();

        $contact->update(['last_name' => 'Rao-Mehta']);

        // The name on the row is a cache for rendering, not the truth. The
        // party_type/party_id pair is — so a re-save picks the new name up.
        $this->assign($task, 'client_contact', $contact->id)->assertOk();

        $this->assertSame('Sunita Rao-Mehta',
            PartyAssignee::where('subject_type', PartyAssignee::SUBJECT_TASK)->where('subject_id', $task->id)->value('name'));
    }
}
