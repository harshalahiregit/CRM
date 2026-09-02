<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Purchase meeting created the way the SHARED form posts it.
 *
 * The shared meeting form builds the whole meeting — agenda rows, action items,
 * decisions, issues — and posts it in ONE request. Purchase only had per-item
 * CRUD endpoints, so that same request would have created an empty meeting and
 * dropped everything typed into it without an error. That is the failure this
 * exists to prevent: not a crash, a silent loss.
 *
 * The payload below is the shape KickoffMeetingCreate actually sends, after the
 * adapter has renamed subject_id → purchase_vendor_id and attendees →
 * participants.
 */
class PurchaseKickoffNestedPayloadTest extends TestCase
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

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'NestedCo',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'nested@test.local', 'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    /** The full body the shared form posts, as the adapter rewrites it. */
    private function payload(int $vendorId): array
    {
        return [
            'purchase_vendor_id' => $vendorId,
            'title' => 'Quarterly HSSE review',
            'meeting_type' => 'kickoff',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'onsite',
            'location' => 'Site office',
            'priority' => 'High',
            'chairperson' => 'Dana Chair',
            'participants' => [
                ['name' => 'Dana Chair', 'email' => 'dana@test.local', 'side' => 'internal', 'role' => 'Chair'],
                ['name' => 'Vic Vendor', 'email' => 'vic@nested.local', 'side' => 'external', 'role' => 'Attendee'],
            ],
            'agenda_items' => [
                ['id' => 'tmp-1', 'item' => 'Review previous actions', 'owner' => 'Dana Chair', 'duration_minutes' => 15],
                ['id' => 'tmp-2', 'item' => 'Permit compliance', 'owner' => 'Vic Vendor', 'duration_minutes' => 20],
            ],
            'mom_items' => [
                ['id' => 'tmp-3', 'description' => '<p>Re-issue the expired permits</p>',
                 'responsible' => 'Vic Vendor', 'priority' => 'High',
                 'target_date' => now()->addWeek()->toDateString(), 'remarks' => 'Before next shift'],
            ],
            'decisions' => [
                ['id' => 'tmp-4', 'decision' => 'Scaffold work paused until permits are current',
                 'decided_by' => 'Dana Chair', 'impact' => 'Schedule', 'status' => 'Active'],
            ],
            'issues' => [
                ['id' => 'tmp-5', 'title' => 'Two permits lapsed', 'description' => 'Found during the walk.',
                 'category' => 'HSSE', 'severity' => 'High', 'owner' => 'Vic Vendor'],
            ],
        ];
    }

    /**
     * Everything in one request lands. Before the content service, this test
     * would have found a meeting with zero agenda rows, zero actions, zero
     * decisions and zero issues — and a 201.
     */
    public function test_one_request_creates_the_meeting_and_all_its_content(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor();

        $id = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))
            ->assertCreated()->json('id');

        $m = PurchaseKickoffMeeting::with(['agendaItems', 'actionItems', 'momDecisions', 'momIssues', 'participants'])
            ->findOrFail($id);

        $this->assertCount(2, $m->agendaItems, 'agenda rows were dropped');
        $this->assertCount(1, $m->actionItems, 'the action item was dropped');
        $this->assertCount(1, $m->momDecisions, 'the decision was dropped');
        $this->assertCount(1, $m->momIssues, 'the issue was dropped');
        $this->assertCount(2, $m->participants, 'participants were dropped');

        // Field renames survive the trip.
        $this->assertSame('Review previous actions', $m->agendaItems[0]->item);
        $this->assertSame('Dana Chair', $m->agendaItems[0]->owner_names, 'owner → owner_names lost');

        $action = $m->actionItems[0];
        $this->assertSame('Vic Vendor', $action->responsible_names, 'responsible → responsible_names lost');
        $this->assertSame('Before next shift', $action->remark, 'remarks → remark lost');
        $this->assertNotNull($action->action_ref, 'the action got no reference');

        $this->assertSame('Dana Chair', $m->momDecisions[0]->decided_by_names, 'decided_by → decided_by_names lost');
        $this->assertSame('Vic Vendor', $m->momIssues[0]->owner_names, 'owner → owner_names lost on the issue');
        $this->assertNotNull($m->momIssues[0]->issue_ref);
    }

    /**
     * A temporary client id must never be treated as a database id. The shared
     * form gives unsaved rows keys like "tmp-1"; looking one up as a real id
     * could edit an unrelated row.
     */
    public function test_client_temp_ids_create_new_rows_rather_than_matching_existing(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor();

        $first = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');
        $second = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');

        $this->assertNotSame($first, $second);
        foreach ([$first, $second] as $mid) {
            $this->assertCount(2, PurchaseKickoffMeeting::findOrFail($mid)->agendaItems);
        }
    }

    /**
     * Editing reuses the same form and the same body. A row sent with its real
     * id is updated in place — it must not be deleted and recreated, which
     * would throw away the action's reference and its Action-Engine state.
     */
    public function test_edit_updates_rows_in_place_and_removes_omitted_ones(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor();

        $id = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');
        $m = PurchaseKickoffMeeting::with('agendaItems', 'actionItems')->findOrFail($id);

        $keepId = $m->agendaItems[0]->id;
        $actionId = $m->actionItems[0]->id;
        $actionRef = $m->actionItems[0]->action_ref;

        // Re-post with the first agenda row renamed, the second dropped.
        $edit = $this->payload($vendor->id);
        $edit['agenda_items'] = [
            ['id' => $keepId, 'item' => 'Review previous actions (revised)', 'owner' => 'Dana Chair'],
        ];
        $edit['mom_items'] = [
            ['id' => $actionId, 'description' => '<p>Re-issue the expired permits</p>', 'responsible' => 'Vic Vendor'],
        ];

        $this->putJson("/api/purchase/kickoff/{$id}", $edit)->assertOk();

        $m->refresh()->load(['agendaItems', 'actionItems']);
        $this->assertCount(1, $m->agendaItems, 'the omitted agenda row was not removed');
        $this->assertSame($keepId, $m->agendaItems[0]->id, 'the kept row was recreated instead of updated');
        $this->assertSame('Review previous actions (revised)', $m->agendaItems[0]->item);

        $this->assertCount(1, $m->actionItems);
        $this->assertSame($actionId, $m->actionItems[0]->id);
        $this->assertSame($actionRef, $m->actionItems[0]->action_ref, 'the action reference changed on edit');
    }

    /**
     * An absent collection is left alone; a present-but-empty one clears.
     * The detail page saves one section at a time, so an absent key must not
     * be read as "delete everything".
     */
    public function test_absent_collections_are_untouched_but_empty_ones_clear(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor();

        $id = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');

        // Update sending ONLY decisions — agenda and actions must survive.
        $this->putJson("/api/purchase/kickoff/{$id}", [
            'decisions' => [['decision' => 'A different call', 'decided_by' => 'Dana Chair']],
        ])->assertOk();

        $m = PurchaseKickoffMeeting::with(['agendaItems', 'actionItems', 'momDecisions'])->findOrFail($id);
        $this->assertCount(2, $m->agendaItems, 'agenda was wiped by an update that never mentioned it');
        $this->assertCount(1, $m->actionItems, 'actions were wiped by an update that never mentioned them');
        $this->assertCount(1, $m->momDecisions);
        $this->assertSame('A different call', $m->momDecisions[0]->decision);
    }

    /** A row id from another meeting cannot be hijacked through this one. */
    public function test_a_row_id_from_another_meeting_is_not_adopted(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor();

        $a = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');
        $b = $this->postJson('/api/purchase/kickoff', $this->payload($vendor->id))->assertCreated()->json('id');

        $foreignId = PurchaseKickoffMeeting::with('agendaItems')->findOrFail($a)->agendaItems[0]->id;

        $edit = ['agenda_items' => [['id' => $foreignId, 'item' => 'Hijacked']]];
        $this->putJson("/api/purchase/kickoff/{$b}", $edit)->assertOk();

        // A's row is untouched; B created its own.
        $this->assertSame(
            'Review previous actions',
            PurchaseKickoffMeeting::with('agendaItems')->findOrFail($a)->agendaItems->firstWhere('id', $foreignId)->item,
            "another meeting's agenda row was edited"
        );
        $bItems = PurchaseKickoffMeeting::with('agendaItems')->findOrFail($b)->agendaItems;
        $this->assertCount(1, $bItems);
        $this->assertNotSame($foreignId, $bItems[0]->id);
    }
}
