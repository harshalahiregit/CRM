<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\MeetingPresence;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MeetingTiming;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who was in the call, and when it actually ran.
 *
 * Two failures sat behind this, both reported from a real meeting:
 *
 *  1. A vendor joined, was visibly in the call, and the attendance list was
 *     empty afterwards. The chair — the person who opened the room — was not
 *     marked either. Attendance was ticked by matching a person's display name
 *     in the call against the roster character for character, and nobody types their
 *     roster name into a video call.
 *
 *  2. The meeting had been over for twenty minutes and still read "In progress",
 *     because the state was derived from the booked hour and nothing recorded
 *     that the call had finished.
 *
 * Everything here is asserted against both engines where the behaviour is
 * shared, because a fix that lands on TPV and not Purchase is half a fix.
 */
class MeetingPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        // The meeting surface is behind role:admin,staff — the room is an admin
        // screen, and only somebody who can open it can report presence.
        $this->actor = User::factory()->create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair',
            'email' => 'priya@sangoe.test', 'role' => 'admin',
        ]);
    }

    /* ── fixtures ────────────────────────────────────────────────────── */

    private function meeting(array $attrs = []): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);

        return KickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT, 'created_by' => $this->actor->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => KickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            'end_at' => now()->addMinutes(55)->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ], $attrs));
    }

    private function purchaseMeeting(): PurchaseKickoffMeeting
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'bolt-'.Str::random(4).'@example.test',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $this->actor->id,
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            'end_at' => now()->addMinutes(55)->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);
    }

    private function presence(): MeetingPresence
    {
        return app(MeetingPresence::class);
    }

    /* ── the empty attendance list ───────────────────────────────────── */

    /**
     * The reported bug, in one test.
     *
     * A vendor joins under the name they typed into the call — not the name on the
     * roster — and the meeting ends with them recorded, not dropped.
     */
    public function test_somebody_who_joins_under_an_unlisted_name_is_recorded(): void
    {
        $meeting = $this->meeting();

        $this->presence()->reconcile($meeting, [
            ['key' => 'p-1', 'name' => 'sonu (mobile)', 'self' => false],
        ], false, $this->actor);

        $rows = $meeting->fresh()->attendees;

        $this->assertCount(1, $rows, 'the person in the call must end up on the roster');
        $this->assertSame('sonu (mobile)', $rows->first()->name);
        $this->assertTrue((bool) $rows->first()->attended);
        $this->assertSame('Online', $rows->first()->attendance_status);
        $this->assertTrue((bool) $rows->first()->is_guest,
            'flagged as a guest so an unexpected name reads as somebody who turned up, not a data error');
        $this->assertNotNull($rows->first()->joined_at);
    }

    /**
     * The chair, who was the one person guaranteed to be missed.
     *
     * They open the room from their own account, and the call shows them under
     * whatever name their browser remembered — so a name match never found
     * them. Tying the local participant to the signed-in user does.
     */
    public function test_the_organiser_is_marked_by_their_account_not_their_display_name(): void
    {
        $meeting = $this->meeting();
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair',
            'email' => 'priya@sangoe.test', 'side' => 'internal',
        ]);

        // The display name is nothing like the roster name.
        $this->presence()->reconcile($meeting, [
            ['key' => 'p-me', 'name' => "Priya's MacBook", 'self' => true],
        ], false, $this->actor);

        $rows = $meeting->fresh()->attendees;

        $this->assertCount(1, $rows, 'the chair must not be added a second time as a guest');
        $this->assertTrue((bool) $rows->first()->attended);
        $this->assertSame((int) $this->actor->id, (int) $rows->first()->user_id,
            'and the row is tied to their account, so the record names a person');
    }

    /* ── matching ────────────────────────────────────────────────────── */

    public function test_a_partial_name_claims_the_right_roster_row(): void
    {
        $meeting = $this->meeting();
        $listed = $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Bale', 'side' => 'external',
        ]);

        // "bale" is what they typed. It means exactly one person here.
        $this->presence()->reconcile($meeting, [
            ['key' => 'p-9', 'name' => 'bale', 'self' => false],
        ], false, $this->actor);

        $this->assertCount(1, $meeting->fresh()->attendees, 'no duplicate row');
        $this->assertTrue((bool) $listed->fresh()->attended);
    }

    /**
     * And gives up when it cannot be sure.
     *
     * A wrong tick on an attendance record is worse than an untied guest row
     * somebody can see and correct, so an ambiguous partial name is never
     * guessed at.
     */
    public function test_an_ambiguous_partial_name_is_not_guessed(): void
    {
        $meeting = $this->meeting();
        foreach (['Sam Patel', 'Sam Iyer'] as $name) {
            $meeting->attendees()->create(['tenant_id' => self::TENANT, 'name' => $name, 'side' => 'internal']);
        }

        $this->presence()->reconcile($meeting, [
            ['key' => 'p-3', 'name' => 'Sam', 'self' => false],
        ], false, $this->actor);

        $rows = $meeting->fresh()->attendees;

        $this->assertCount(3, $rows, 'the two Sams are left alone and the arrival is recorded separately');
        $this->assertSame(0, $rows->where('name', 'Sam Patel')->where('attended', true)->count());
        $this->assertSame(0, $rows->where('name', 'Sam Iyer')->where('attended', true)->count());
        $this->assertSame(1, $rows->where('is_guest', true)->count());
    }

    /** Word-wise, so a name is never matched on a fragment of another word. */
    public function test_a_fragment_of_a_word_does_not_match(): void
    {
        $meeting = $this->meeting();
        $meeting->attendees()->create(['tenant_id' => self::TENANT, 'name' => 'Khalid Ahmed', 'side' => 'internal']);

        $this->presence()->reconcile($meeting, [
            ['key' => 'p-4', 'name' => 'ali', 'self' => false],
        ], false, $this->actor);

        $this->assertFalse((bool) $meeting->fresh()->attendees->firstWhere('name', 'Khalid Ahmed')->attended,
            '"ali" inside "Khalid" is not a name match');
    }

    /* ── the snapshot is safe to repeat ──────────────────────────────── */

    /**
     * Everyone in a call sees everyone else, so the same arrival can arrive
     * several times over — from a retry, a reload, or a second browser in the
     * room. Reconciling a snapshot has to be idempotent or the roster fills up
     * with copies of the same person.
     */
    public function test_the_same_snapshot_applied_repeatedly_changes_nothing(): void
    {
        $meeting = $this->meeting();
        $snapshot = [
            ['key' => 'a', 'name' => 'Ravi', 'self' => false],
            ['key' => 'b', 'name' => 'Meera', 'self' => false],
        ];

        foreach (range(1, 4) as $ignored) {
            $this->presence()->reconcile($meeting, $snapshot, false, $this->actor);
        }

        $this->assertCount(2, $meeting->fresh()->attendees);
    }

    /** One person reported twice inside a single snapshot is still one person. */
    public function test_a_duplicated_entry_within_one_snapshot_is_one_person(): void
    {
        $meeting = $this->meeting();

        $this->presence()->reconcile($meeting, [
            ['key' => 'x', 'name' => 'Ravi', 'self' => false],
            ['key' => 'x', 'name' => 'Ravi', 'self' => false],
        ], false, $this->actor);

        $this->assertCount(1, $meeting->fresh()->attendees);
    }

    /* ── when the meeting actually ran ───────────────────────────────── */

    /**
     * The second reported bug: a meeting that finished still read "In progress"
     * for the rest of its booked hour.
     */
    public function test_a_call_that_ends_early_stops_reading_as_in_progress(): void
    {
        $meeting = $this->meeting();

        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);
        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state);
        $this->assertSame('In progress', $meeting->fresh()->timing_label);

        $this->presence()->reconcile($meeting, [], true, $this->actor);
        $fresh = $meeting->fresh();

        $this->assertSame(MeetingTiming::ENDED, $fresh->timing_state,
            'the call is over; fifty minutes of unused booking do not keep it running');
        $this->assertSame('Ended', $fresh->timing_label);
        $this->assertTrue($fresh->has_ended);
        $this->assertNotNull($fresh->actual_end_at);
    }

    /** Ended is not expired: one meeting happened, the other was missed. */
    public function test_ended_and_expired_are_told_apart(): void
    {
        $held = $this->meeting();
        $this->presence()->reconcile($held, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);
        $this->presence()->reconcile($held, [], true, $this->actor);

        $missed = $this->meeting([
            'scheduled_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
            'end_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(MeetingTiming::ENDED, $held->fresh()->timing_state);
        $this->assertSame(MeetingTiming::EXPIRED, $missed->fresh()->timing_state);

        // Both are over, so neither should still be offering a join link.
        $this->assertTrue($held->fresh()->is_expired, 'a finished call cannot be joined either');
        $this->assertTrue($missed->fresh()->is_expired);
    }

    /**
     * A meeting that overruns is still happening.
     *
     * Withdrawing the link from people who are mid-sentence is worse than
     * leaving it up a few minutes past the hour.
     */
    public function test_a_call_running_past_its_slot_stays_live(): void
    {
        $meeting = $this->meeting([
            'scheduled_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(MeetingTiming::EXPIRED, $meeting->fresh()->timing_state,
            'before anybody joins, the slot is all there is');

        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);

        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state,
            'people are in the call right now');
        $this->assertFalse($meeting->fresh()->is_expired);
    }

    /** A call picked back up is one meeting that resumed, not a finished one. */
    public function test_rejoining_after_the_end_reopens_the_meeting(): void
    {
        $meeting = $this->meeting();

        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);
        $this->presence()->reconcile($meeting, [], true, $this->actor);
        $this->assertSame(MeetingTiming::ENDED, $meeting->fresh()->timing_state);

        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);

        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state);
        $this->assertNull($meeting->fresh()->actual_end_at);
    }

    /**
     * A call that was never hung up properly.
     *
     * The laptop is shut, the browser crashes, somebody clicks away to another
     * page — nothing reports the end. Without a staleness rule the meeting would
     * read "In progress" for ever, which is a worse version of the bug this
     * replaced: that one at least stopped at the end of the booked hour.
     */
    public function test_a_call_that_goes_quiet_stops_reading_as_in_progress(): void
    {
        $meeting = $this->meeting();
        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);

        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state);

        // A call that started twenty minutes ago and has not been heard from for
        // the last five — the shape a shut laptop leaves behind.
        $meeting->forceFill([
            'actual_start_at' => now()->subMinutes(20),
            'presence_seen_at' => now()->subMinutes(MeetingTiming::STALE_AFTER_MINUTES + 2),
        ])->saveQuietly();

        $this->assertSame(MeetingTiming::ENDED, $meeting->fresh()->timing_state,
            'nothing has been heard from the call for minutes — it is over');
        $this->assertNotNull($meeting->fresh()->held_minutes,
            'and it still reports how long it ran, measured to the last time anyone was seen');
    }

    /** A brief gap is not an ending — one dropped request must not close a meeting. */
    public function test_a_short_gap_between_reports_keeps_the_meeting_live(): void
    {
        $meeting = $this->meeting();
        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);

        $meeting->forceFill(['presence_seen_at' => now()->subSeconds(45)])->saveQuietly();

        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state);
    }

    /** Every meeting held before any of this existed keeps behaving as it did. */
    public function test_a_meeting_with_no_heartbeat_at_all_is_not_treated_as_stale(): void
    {
        $meeting = $this->meeting();
        $meeting->forceFill([
            'actual_start_at' => now()->subMinutes(10),
            'presence_seen_at' => null,
        ])->saveQuietly();

        $this->assertSame(MeetingTiming::LIVE, $meeting->fresh()->timing_state,
            'a row with no heartbeat is not evidence that the call went quiet');
    }

    /** The start is the first arrival, and it is not moved by later ones. */
    public function test_the_start_is_the_first_arrival(): void
    {
        $meeting = $this->meeting();

        $this->presence()->reconcile($meeting, [['key' => 'a', 'name' => 'Ravi']], false, $this->actor);
        $first = $meeting->fresh()->actual_start_at;

        $this->presence()->reconcile($meeting, [
            ['key' => 'a', 'name' => 'Ravi'],
            ['key' => 'b', 'name' => 'Meera'],
        ], false, $this->actor);

        $this->assertEquals(
            $first->format('Y-m-d H:i:s'),
            $meeting->fresh()->actual_start_at->format('Y-m-d H:i:s'),
            'a second person arriving does not restart the meeting',
        );
    }

    /**
     * How long it ran is measured start-to-finish, not by adding up how long
     * each person stayed — four people in a half-hour meeting is half an hour,
     * not two hours.
     */
    public function test_the_held_duration_is_the_length_of_the_meeting(): void
    {
        $meeting = $this->meeting();
        $this->presence()->reconcile($meeting, [
            ['key' => 'a', 'name' => 'Ravi'],
            ['key' => 'b', 'name' => 'Meera'],
            ['key' => 'c', 'name' => 'Sunil'],
        ], false, $this->actor);

        // Wind the clock back on the record so there is a real span to measure.
        $meeting->forceFill(['actual_start_at' => now()->subMinutes(34)->format('Y-m-d H:i:s')])->saveQuietly();
        $this->presence()->reconcile($meeting->fresh(), [], true, $this->actor);

        $held = $meeting->fresh()->held_minutes;

        $this->assertNotNull($held);
        $this->assertEqualsWithDelta(34, $held, 1, 'the meeting ran about 34 minutes, not 3 × 34');
    }

    /** Nothing is claimed about a meeting nobody joined through the room. */
    public function test_a_meeting_with_no_record_of_a_call_behaves_exactly_as_before(): void
    {
        $meeting = $this->meeting([
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
        ]);

        $this->assertNull($meeting->actual_start_at);
        $this->assertNull($meeting->held_minutes);
        $this->assertSame(MeetingTiming::UPCOMING, $meeting->timing_state);
    }

    /* ── the endpoints, on both engines ──────────────────────────────── */

    public function test_the_shared_engine_records_presence_over_http(): void
    {
        $meeting = $this->meeting();
        Sanctum::actingAs($this->actor);

        $res = $this->postJson("/api/kickoff/meetings/{$meeting->id}/room/presence", [
            'in_call' => [['key' => 'a', 'name' => 'Ravi', 'self' => false]],
            'ended' => false,
        ]);

        $res->assertOk()->assertJsonPath('present', 1);
        $this->assertSame('Ravi', $res->json('attendees.0.name'));
        $this->assertSame(MeetingTiming::LIVE, $res->json('timing_state'));
    }

    public function test_the_purchase_engine_records_presence_over_http(): void
    {
        $meeting = $this->purchaseMeeting();
        Sanctum::actingAs($this->actor);

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/presence", [
            'in_call' => [['key' => 'a', 'name' => 'Ravi', 'self' => false]],
        ])->assertOk()->assertJsonPath('present', 1);

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/room/presence", [
            'in_call' => [],
            'ended' => true,
        ])->assertOk();

        $this->assertSame(MeetingTiming::ENDED, $meeting->fresh()->timing_state,
            'Purchase gets the same fix, not half of it');
    }

    /** Another tenant's meeting is not reachable, presence or otherwise. */
    public function test_presence_cannot_be_reported_for_another_tenants_meeting(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $meeting = $this->meeting();
        $outsider = User::factory()->create(['tenant_id' => 2]);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/kickoff/meetings/{$meeting->id}/room/presence", [
            'in_call' => [['key' => 'a', 'name' => 'Intruder']],
        ])->assertForbidden();

        $this->assertCount(0, $meeting->fresh()->attendees);
    }
}
