<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomAgendaItem;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\MeetingAgendaItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notes typed in the live meeting room reach the database.
 *
 * The room shows the call beside the meeting's own agenda and roster, so the
 * minutes are written while the meeting happens instead of reconstructed from
 * memory afterwards. That is only worth anything if what is typed is actually
 * stored — against the right agenda point, on the right meeting, for the right
 * tenant — and if the screen cannot quietly change anything else about the
 * record while it is at it.
 */
class MeetingRoomNotesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function purchaseMeeting(): PurchaseKickoffMeeting
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);

        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    private function purchaseAgenda(PurchaseKickoffMeeting $m, string $item): PurchaseMomAgendaItem
    {
        return PurchaseMomAgendaItem::create([
            'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
            'item' => $item, 'sort_order' => 1,
        ]);
    }

    /* ── The notes land where they belong ───────────────────────────────── */

    public function test_agenda_notes_and_minutes_are_stored(): void
    {
        $meeting = $this->purchaseMeeting();
        $price = $this->purchaseAgenda($meeting, 'Price');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
            'minutes' => 'Ran fifteen minutes over.',
            'agenda' => [
                ['id' => $price->id, 'discussion' => 'Asked for 8% off.', 'decision' => 'Settled at 5%.'],
            ],
        ])->assertOk()->assertJsonPath('agenda_saved', 1);

        $this->assertSame('Ran fifteen minutes over.', $meeting->fresh()->minutes);
        $this->assertSame('Asked for 8% off.', $price->fresh()->discussion);
        $this->assertSame('Settled at 5%.', $price->fresh()->decision);
    }

    public function test_the_shared_engine_stores_them_too(): void
    {
        // One screen drives both engines, so both must accept the same call.
        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'title' => 'Shared kickoff',
            'status' => 'Scheduled', 'mode' => 'online', 'scheduled_at' => now()->addDay(),
        ]);
        $item = MeetingAgendaItem::create([
            'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $meeting->id,
            'item' => 'Safety', 'sort_order' => 1,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/kickoff/meetings/{$meeting->id}/room/notes", [
            'minutes' => 'All present.',
            'agenda' => [['id' => $item->id, 'discussion' => 'Induction dates agreed.']],
        ])->assertOk();

        $this->assertSame('All present.', $meeting->fresh()->minutes);
        $this->assertSame('Induction dates agreed.', $item->fresh()->discussion);
    }

    public function test_autosaving_the_same_note_twice_just_overwrites_it(): void
    {
        // The room saves every time typing pauses, so the same row is written
        // over and over during one meeting. That must not duplicate anything.
        $meeting = $this->purchaseMeeting();
        $item = $this->purchaseAgenda($meeting, 'Delivery');
        Sanctum::actingAs($this->admin());

        foreach (['Del', 'Delivery in', 'Delivery in 3 weeks'] as $typed) {
            $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
                'agenda' => [['id' => $item->id, 'discussion' => $typed]],
            ])->assertOk();
        }

        $this->assertSame('Delivery in 3 weeks', $item->fresh()->discussion);
        $this->assertSame(1, $meeting->agendaItems()->count(), 'no agenda rows were added by saving');
    }

    public function test_a_note_can_be_cleared(): void
    {
        // Emptying a box is an edit the writer meant. Read with `?? null` this
        // would have been mistaken for "no value sent" and silently ignored.
        $meeting = $this->purchaseMeeting();
        $item = $this->purchaseAgenda($meeting, 'Scope');
        $item->forceFill(['discussion' => 'Typed by mistake'])->save();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
            'agenda' => [['id' => $item->id, 'discussion' => '']],
        ])->assertOk();

        // Stored as NULL, not '': the framework's ConvertEmptyStringsToNull
        // middleware rewrites the empty string before validation ever sees it.
        // Either way the note is gone, which is what clearing the box means.
        $this->assertNull($item->fresh()->discussion);
    }

    public function test_omitting_minutes_leaves_them_alone(): void
    {
        // The agenda tab saves without touching the minutes box. Sending no
        // `minutes` key must not wipe what the notes tab already stored.
        $meeting = $this->purchaseMeeting();
        $meeting->forceFill(['minutes' => 'Written earlier'])->save();
        $item = $this->purchaseAgenda($meeting, 'Scope');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
            'agenda' => [['id' => $item->id, 'decision' => 'Agreed.']],
        ])->assertOk();

        $this->assertSame('Written earlier', $meeting->fresh()->minutes);
    }

    /* ── It cannot reach past its own meeting ───────────────────────────── */

    public function test_it_cannot_write_to_another_meetings_agenda(): void
    {
        $mine = $this->purchaseMeeting();
        $theirs = $this->purchaseMeeting();
        $theirItem = $this->purchaseAgenda($theirs, 'Their point');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$mine->id}/room/notes", [
            'agenda' => [['id' => $theirItem->id, 'discussion' => 'Should not land here.']],
        ])->assertOk()->assertJsonPath('agenda_saved', 0);

        $this->assertNull($theirItem->fresh()->discussion);
    }

    public function test_another_tenants_meeting_is_not_reachable(): void
    {
        $meeting = $this->purchaseMeeting();
        $meeting->forceFill(['tenant_id' => 999])->save();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", ['minutes' => 'x'])
            ->assertStatus(404);
    }

    public function test_the_note_taker_cannot_reshape_the_meeting(): void
    {
        // The room is for writing notes. Anything else in the body is ignored:
        // a note-taking screen must not be able to move the meeting, rename it,
        // or complete it by accident.
        $meeting = $this->purchaseMeeting();
        $original = $meeting->title;
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
            'minutes' => 'Notes.',
            'title' => 'Renamed by the room',
            'status' => 'Completed',
            'scheduled_at' => now()->addYear()->toIso8601String(),
        ])->assertOk();

        $fresh = $meeting->fresh();
        $this->assertSame($original, $fresh->title);
        $this->assertSame('Scheduled', $fresh->status);
    }

    public function test_a_note_longer_than_the_column_is_refused(): void
    {
        $meeting = $this->purchaseMeeting();
        $item = $this->purchaseAgenda($meeting, 'Scope');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/notes", [
            'agenda' => [['id' => $item->id, 'discussion' => str_repeat('a', 5001)]],
        ])->assertStatus(422)->assertJsonValidationErrors('agenda.0.discussion');
    }
}
