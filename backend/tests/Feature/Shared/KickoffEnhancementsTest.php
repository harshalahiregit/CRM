<?php

namespace Tests\Feature\Shared;

use App\Exceptions\BusinessException;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Shared\KickoffStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The five gaps closed on the shared kickoff engine, plus the backward
 * compatibility each one had to preserve. Every test that adds a column also
 * pins that the old field it shadows still behaves.
 */
class KickoffEnhancementsTest extends TestCase
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

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Coordinator',
            'email' => 'coord'.uniqid().'@test.com', 'password' => bcrypt('secret'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function svc(): KickoffMeetingService
    {
        return app(KickoffMeetingService::class);
    }

    private function meeting(array $data = []): KickoffMeeting
    {
        return $this->svc()->schedule(array_merge([
            'title'        => 'Kickoff',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'attendees'    => [
                ['name' => 'Asha Menon', 'email' => 'asha@v.com', 'organisation' => 'ABC Vendor'],
                ['name' => 'Rahul Patil', 'email' => 'rahul@v.com', 'organisation' => 'XYZ Vendor'],
            ],
        ], $data), $this->actor);
    }

    /* ── 1. structured MOM items ─────────────────────────────────────── */

    public function test_mom_items_persist_with_owner_and_target_date(): void
    {
        $m        = $this->meeting();
        $attendee = $m->attendees->first();

        $saved = $this->svc()->update($m, ['mom_items' => [
            ['description' => 'Submit HSSE plan', 'responsible_attendee_id' => $attendee->id,
             'remark' => 'Draft seen', 'notes' => 'Rev B', 'target_date' => '2026-10-01'],
            ['description' => 'Share site access list'],
        ]], $this->actor);

        $items = $saved->momItems;
        $this->assertCount(2, $items);
        $this->assertSame('Submit HSSE plan', $items[0]->description);
        $this->assertSame($attendee->id, $items[0]->responsible_attendee_id);
        $this->assertSame('2026-10-01', $items[0]->target_date->toDateString());
        $this->assertSame(0, $items[0]->sort_order);
        $this->assertSame(1, $items[1]->sort_order);
        $this->assertNull($items[1]->responsible_attendee_id);
    }

    public function test_resaving_replaces_items_rather_than_appending(): void
    {
        $m = $this->meeting();

        $this->svc()->update($m, ['mom_items' => [['description' => 'One'], ['description' => 'Two']]], $this->actor);
        $saved = $this->svc()->update($m->fresh(), ['mom_items' => [['description' => 'Only']]], $this->actor);

        $this->assertCount(1, $saved->momItems);
        $this->assertSame('Only', $saved->momItems->first()->description);
    }

    public function test_empty_rows_are_dropped_and_foreign_owners_rejected(): void
    {
        $other = $this->meeting();                     // attendees belong to another meeting
        $m     = $this->meeting();

        $saved = $this->svc()->update($m, ['mom_items' => [
            ['description' => '   '],                                                  // blank row
            ['description' => 'Real', 'responsible_attendee_id' => $other->attendees->first()->id],
        ]], $this->actor);

        $this->assertCount(1, $saved->momItems);
        // Assigned to somebody who was never in this meeting -> cleared, not saved.
        $this->assertNull($saved->momItems->first()->responsible_attendee_id);
    }

    /**
     * The Kickoff form was built before this backend existed and posts its own
     * field names — `location_detail`, `responsible` (a comma-separated list of
     * typed names), `remarks`. It shipped with the comment "backend uses what it
     * knows, ignores the rest", and the backend duly ignored all of it.
     *
     * This pins the exact payload that form sends, so the data it has always
     * collected now actually lands.
     */
    public function test_the_existing_frontend_payload_is_accepted_verbatim(): void
    {
        $m = $this->svc()->schedule([
            'title'           => 'Kickoff',
            'scheduled_at'    => now()->addDay()->toDateTimeString(),
            'planned_date'    => '2026-09-01',
            'location'        => 'Nashik',        // the form's "City / Location"
            'location_detail' => 'Conf Room A',   // the form's "Venue / Address"
            'attendees'       => [['name' => 'Asha Menon', 'organisation' => 'ABC Vendor']],
            'mom_items'       => [[
                'description' => 'Submit HSSE plan',
                'responsible' => 'Asha Menon',     // a name, not an id
                'remarks'     => 'Draft seen',
                'target_date' => '2026-10-01',
            ]],
        ], $this->actor);

        $this->assertSame('Conf Room A', $m->venue);
        $this->assertSame('Nashik', $m->location, 'an explicit location still wins');
        $this->assertSame('2026-09-01', $m->planned_date->toDateString());

        $item = $m->momItems->first();
        $this->assertSame('Submit HSSE plan', $item->description);
        $this->assertSame('Draft seen', $item->remark, 'remarks -> remark');
        $this->assertSame('Asha Menon', $item->responsible_names);
        // The typed name matched an attendee, so it also resolved to the link.
        $this->assertSame($m->attendees->first()->id, $item->responsible_attendee_id);
    }

    public function test_a_typed_name_that_is_not_an_attendee_is_still_kept(): void
    {
        $m = $this->meeting();

        $saved = $this->svc()->update($m, ['mom_items' => [
            ['description' => 'External audit', 'responsible' => 'Someone Outside'],
        ]], $this->actor);

        $item = $saved->momItems->first();
        $this->assertSame('Someone Outside', $item->responsible_names);
        $this->assertNull($item->responsible_attendee_id);
    }

    /* ── 2. attendance ───────────────────────────────────────────────── */

    public function test_status_drives_the_legacy_attended_boolean(): void
    {
        $m = $this->meeting();
        [$a, $b] = [$m->attendees[0], $m->attendees[1]];

        $this->svc()->markAttendance($m, [
            ['id' => $a->id, 'attendance_status' => KickoffAttendee::LATE, 'remark' => 'Joined 10 min in'],
            ['id' => $b->id, 'attendance_status' => KickoffAttendee::ABSENT],
        ], $this->actor);

        $a->refresh();
        $b->refresh();
        $this->assertSame(KickoffAttendee::LATE, $a->attendance_status);
        $this->assertTrue($a->attended, 'Late still counts as having attended');
        $this->assertSame('Joined 10 min in', $a->remark);
        $this->assertFalse($b->attended);
    }

    /** The old boolean-only caller must keep working and gain a coherent status. */
    public function test_legacy_boolean_payload_still_works(): void
    {
        $m = $this->meeting();
        $a = $m->attendees->first();

        $this->svc()->markAttendance($m, [['id' => $a->id, 'attended' => true]], $this->actor);

        $a->refresh();
        $this->assertTrue($a->attended);
        $this->assertSame(KickoffAttendee::PRESENT, $a->attendance_status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $m = $this->meeting();

        $this->expectException(BusinessException::class);
        $this->svc()->markAttendance($m, [
            ['id' => $m->attendees->first()->id, 'attendance_status' => 'Maybe'],
        ], $this->actor);
    }

    /* ── 3. location ─────────────────────────────────────────────────── */

    public function test_structured_parts_compose_the_legacy_location_string(): void
    {
        $m = $this->meeting(['venue' => 'Conf Room A', 'address' => '12 MIDC Rd', 'city' => 'Nashik']);

        $this->assertSame('Nashik', $m->city);
        $this->assertSame('Conf Room A, 12 MIDC Rd, Nashik', $m->location, 'old readers still get one string');
    }

    public function test_an_explicit_location_is_not_overwritten(): void
    {
        $m = $this->meeting(['location' => 'Site gate', 'city' => 'Pune']);

        $this->assertSame('Site gate', $m->location);
        $this->assertSame('Pune', $m->city);
    }

    /* ── 4. planned date ─────────────────────────────────────────────── */

    public function test_planned_date_is_independent_of_scheduled_at(): void
    {
        $m = $this->meeting(['planned_date' => '2026-09-01']);

        $this->assertSame('2026-09-01', $m->planned_date->toDateString());
        $this->assertNotSame('2026-09-01', $m->scheduled_at->toDateString());
    }

    /* ── 5. completion gate + 48h acknowledgement ────────────────────── */

    public function test_a_future_meeting_cannot_be_completed(): void
    {
        $m = $this->meeting(['scheduled_at' => now()->addDays(3)->toDateTimeString()]);

        $this->assertFalse($m->can_complete);

        $this->expectException(BusinessException::class);
        $this->svc()->transition($m, KickoffStatus::COMPLETED, [], $this->actor);
    }

    public function test_a_past_meeting_can_be_completed(): void
    {
        $m = $this->meeting(['scheduled_at' => now()->subHour()->toDateTimeString()]);

        $done = $this->svc()->transition($m, KickoffStatus::COMPLETED, [], $this->actor);

        $this->assertSame(KickoffStatus::COMPLETED, $done->status);
        $this->assertNotNull($done->completed_at);
    }

    public function test_publishing_opens_a_48_hour_window(): void
    {
        $m = $this->completedWithMom();

        $published = $this->svc()->publishForAck($m, $this->actor);

        $this->assertSame(KickoffMeeting::ACK_PENDING, $published->acknowledgement_status);
        $this->assertNotNull($published->acknowledgement_sent_at);
        $this->assertEqualsWithDelta(
            48, $published->acknowledgement_sent_at->diffInHours($published->acknowledgement_deadline), 0.1
        );
        $this->assertTrue($published->acknowledgement_open);
    }

    public function test_acknowledgement_after_48_hours_is_refused(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);

        Carbon::setTestNow(now()->addHours(49));

        $stale = $m->fresh();
        $this->assertTrue($stale->acknowledgement_expired);

        try {
            $this->svc()->acknowledge($stale, ['name' => 'Vendor Rep'], ['ip' => '1.1.1.1']);
            $this->fail('an expired window must refuse the acknowledgement');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('expired', strtolower($e->getMessage()));
        }

        $this->assertSame(KickoffMeeting::ACK_EXPIRED, $stale->fresh()->acknowledgement_status);
        $this->assertNull($stale->fresh()->acknowledged_at);

        Carbon::setTestNow();
    }

    public function test_acknowledgement_inside_the_window_succeeds(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);

        Carbon::setTestNow(now()->addHours(47));
        $acked = $this->svc()->acknowledge($m->fresh(), ['name' => 'Vendor Rep'], ['ip' => '1.1.1.1']);

        $this->assertNotNull($acked->acknowledged_at);
        $this->assertSame(KickoffMeeting::ACK_ACKNOWLEDGED, $acked->acknowledgement_status);
        $this->assertNull($acked->getRawOriginal('ack_token'), 'the link stays single-use');

        Carbon::setTestNow();
    }

    /** A meeting published before the window existed has no deadline to breach. */
    public function test_a_meeting_with_no_deadline_never_expires(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);
        $m->fresh()->update(['acknowledgement_deadline' => null]);

        $this->assertFalse($m->fresh()->acknowledgement_expired);
        $this->assertTrue($m->fresh()->acknowledgement_open);
    }

    /* ── 6. vendor response on acknowledgement ───────────────────────── */

    public function test_a_comment_is_stored_with_the_acknowledgement(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);

        $acked = $this->svc()->acknowledge($m->fresh(), [
            'name'    => 'Vendor Rep',
            'comment' => 'Agreed, but item 3 needs a revised target date.',
        ], ['ip' => '1.1.1.1']);

        $this->assertSame('Agreed, but item 3 needs a revised target date.', $acked->acknowledgement_comment);
        $this->assertSame(KickoffMeeting::ACK_ACKNOWLEDGED, $acked->acknowledgement_status);
    }

    /** Every existing caller posts no comment at all — that must keep working. */
    public function test_acknowledging_without_a_comment_still_works(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);

        $acked = $this->svc()->acknowledge($m->fresh(), ['name' => 'Vendor Rep'], ['ip' => '1.1.1.1']);

        $this->assertNotNull($acked->acknowledged_at);
        $this->assertNull($acked->acknowledgement_comment);
    }

    public function test_a_whitespace_only_comment_is_stored_as_null(): void
    {
        $m = $this->completedWithMom();
        $this->svc()->publishForAck($m, $this->actor);

        $acked = $this->svc()->acknowledge($m->fresh(), ['name' => 'Rep', 'comment' => "   \n  "], ['ip' => '1.1.1.1']);

        $this->assertNull($acked->acknowledgement_comment, 'blank input must not masquerade as a response');
    }

    /**
     * A meeting whose minutes are ready to distribute.
     *
     * Meeting.docx §12 puts an approval chain in front of distribution — Draft ->
     * Organizer -> Chairperson -> Approved — and publishForAck enforces it. These
     * tests are about the acknowledgement WINDOW, not the approval workflow, so
     * the helper walks the chain rather than each test restating it.
     */
    private function completedWithMom(): KickoffMeeting
    {
        $m = $this->meeting(['scheduled_at' => now()->subHour()->toDateTimeString()]);
        $this->svc()->transition($m, KickoffStatus::COMPLETED, [], $this->actor);
        $m->fresh()->update(['mom_path' => 'fake/mom.pdf']);

        $this->svc()->submitMomForApproval($m->fresh(), $this->actor);   // Draft -> Organizer
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);   // -> Chairperson
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);   // -> Approved

        return $m->fresh();
    }
}
