<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\MeetingJoinRecorder;
use App\Support\Shared\AttendanceVerdict;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The organiser has the final word — without erasing what it overrules.
 *
 * Marking attendance in the CRM is a claim, and it is also how a person got the
 * joining link at all. The CRM cannot see a call held on Google's servers, so
 * somebody can mark attendance, take the link and never open it.
 *
 * The sentence the whole feature exists to make writable is "user punched CRM
 * attendance but did not join the call" — and that is only writable if BOTH
 * halves survive. So the tests that matter here are not the happy path; they
 * are the ones asserting the claim is still intact after being overruled.
 */
class MeetingAttendanceReviewTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function staff(string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya '.Str::random(4), 'role' => $role,
            'email' => 'staff-'.Str::random(6).'@sangoe.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(4).'@vendor.local', 'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function meeting(?User $organiser): KickoffMeeting
    {
        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $organiser?->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $this->vendor()->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->setTime(9, 0), 'end_at' => now()->setTime(11, 0), 'duration_minutes' => 120,
            'meeting_platform' => 'google_meet', 'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ]);
    }

    /** Somebody who marked attendance in the CRM — the claim the organiser judges. */
    private function claimant(KickoffMeeting $m, string $name = 'Ravi')
    {
        return $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => $name, 'email' => strtolower($name).'@alphaco.local',
            'side' => 'external', 'attended' => true, 'attendance_status' => 'Online',
            'attendance_source' => MeetingJoinRecorder::SOURCE_LINK,
            'joined_at' => now()->setTime(9, 2),
            'remark' => 'Opened the meeting 08 Sep 2026, 09:02 from Mobile on Safari (203.0.113.9)',
        ]);
    }

    private function submit(KickoffMeeting $m, array $rows)
    {
        return $this->postJson("/api/kickoff/meetings/{$m->id}/attendance/review", ['rows' => $rows]);
    }

    /* ── the point of the whole thing ────────────────────────────────── */

    public function test_overruling_a_claim_does_not_erase_it(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::COMPLETE_ABSENT,
            'verdict_note' => 'User punched CRM attendance but did not join the call.',
        ]])->assertOk()->assertJsonPath('counts.contradicted', 1);

        $row->refresh();

        // The verdict is recorded...
        $this->assertSame(AttendanceVerdict::COMPLETE_ABSENT, $row->verdict);
        $this->assertSame((int) $organiser->id, (int) $row->verdict_by);
        $this->assertNotNull($row->verdict_at);

        // ...and every trace of the claim it overrules is still there. This is
        // the assertion the feature exists for: without it there is no evidence
        // that anybody claimed anything, and the note above is a bare accusation.
        $this->assertTrue((bool) $row->attended, 'the CRM attendance mark stands as a record of the claim');
        $this->assertSame(MeetingJoinRecorder::SOURCE_LINK, $row->attendance_source);
        $this->assertNotNull($row->joined_at, 'when they claimed it');
        $this->assertStringContainsString('Safari', (string) $row->remark, 'and from what');
    }

    public function test_the_register_shows_the_claim_and_the_verdict_side_by_side(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [['id' => $row->id, 'verdict' => AttendanceVerdict::COMPLETE_ABSENT]])->assertOk();

        $entry = $this->getJson("/api/kickoff/meetings/{$m->id}/attendance/register")
            ->assertOk()->json('register.0');

        $this->assertTrue($entry['claimed_attended']);
        $this->assertSame('link', $entry['attendance_source']);
        $this->assertNotNull($entry['claim_evidence']);
        $this->assertSame(AttendanceVerdict::COMPLETE_ABSENT, $entry['verdict']);
        $this->assertSame('Complete Absent', $entry['verdict_label']);
        // The flag the screen needs in order to draw attention to the disagreement.
        $this->assertTrue($entry['contradicts_claim']);
    }

    /* ── the three slabs ─────────────────────────────────────────────── */

    public function test_partial_absent_records_the_window_they_were_actually_there(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);

        // Joined 9:30, left 10:00, on a meeting booked until 11:00.
        $this->submit($m, [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::PARTIAL_ABSENT,
            'verdict_from' => now()->setTime(9, 30)->toDateTimeString(),
            'verdict_to' => now()->setTime(10, 0)->toDateTimeString(),
        ]])->assertOk()->assertJsonPath('counts.partial', 1);

        $entry = $this->getJson("/api/kickoff/meetings/{$m->id}/attendance/register")
            ->assertOk()->json('register.0');

        $this->assertSame(30, $entry['verdict_minutes'], 'half an hour of a two-hour meeting');
        $this->assertNotNull($entry['verdict_from']);
        $this->assertNotNull($entry['verdict_to']);
    }

    public function test_partial_absent_without_times_is_refused(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);

        // "Partially absent" with no window says somebody was unhappy, not what
        // happened — which is the entire value of the slab.
        $this->submit($m, [['id' => $row->id, 'verdict' => AttendanceVerdict::PARTIAL_ABSENT]])
            ->assertStatus(422);

        $this->assertNull($row->fresh()->verdict);
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::PARTIAL_ABSENT,
            'verdict_from' => now()->setTime(10, 0)->toDateTimeString(),
            'verdict_to' => now()->setTime(9, 30)->toDateTimeString(),
        ]])->assertStatus(422);

        $this->assertNull($row->fresh()->verdict);
    }

    public function test_a_window_on_a_slab_that_has_no_window_is_dropped(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::FULLY_PRESENT,
            'verdict_from' => now()->setTime(9, 30)->toDateTimeString(),
            'verdict_to' => now()->setTime(10, 0)->toDateTimeString(),
        ]])->assertOk();

        $row->refresh();
        // "Absent, from 9:30 to 10:00" is a contradiction; so is a window on
        // somebody who was there throughout.
        $this->assertNull($row->verdict_from);
        $this->assertNull($row->verdict_to);
    }

    /* ── who may decide ──────────────────────────────────────────────── */

    public function test_a_member_of_staff_who_did_not_organise_cannot_decide(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        // Final approval authority means one person's, not anyone who can open
        // the page — this decision can cost somebody their attendance record.
        Sanctum::actingAs($this->staff());
        $this->submit($m, [['id' => $row->id, 'verdict' => AttendanceVerdict::COMPLETE_ABSENT]])
            ->assertStatus(403);

        $this->assertNull($row->fresh()->verdict);
    }

    public function test_an_admin_can_decide(): void
    {
        $m = $this->meeting($this->staff());
        $row = $this->claimant($m);

        Sanctum::actingAs($this->staff('admin'));
        $this->submit($m, [['id' => $row->id, 'verdict' => AttendanceVerdict::FULLY_PRESENT]])->assertOk();

        $this->assertSame(AttendanceVerdict::FULLY_PRESENT, $row->fresh()->verdict);
    }

    public function test_a_verdict_cannot_be_aimed_at_another_meetings_attendee(): void
    {
        $organiser = $this->staff();
        $mine = $this->meeting($organiser);
        $theirs = $this->meeting($organiser);
        $theirRow = $this->claimant($theirs, 'Sunita');

        Sanctum::actingAs($organiser);
        $this->submit($mine, [['id' => $theirRow->id, 'verdict' => AttendanceVerdict::COMPLETE_ABSENT]])
            ->assertStatus(422);

        $this->assertNull($theirRow->fresh()->verdict);
    }

    /* ── unreviewed is its own state ─────────────────────────────────── */

    public function test_no_verdict_is_not_the_same_as_absent(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $this->claimant($m);

        Sanctum::actingAs($organiser);
        $entry = $this->getJson("/api/kickoff/meetings/{$m->id}/attendance/register")
            ->assertOk()->json('register.0');

        // An unreviewed meeting must not read as one where nobody turned up.
        $this->assertNull($entry['verdict']);
        $this->assertFalse($entry['reviewed']);
        $this->assertFalse($entry['contradicts_claim']);
        $this->assertTrue($entry['claimed_attended']);
    }

    public function test_a_decision_can_be_taken_back(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [['id' => $row->id, 'verdict' => AttendanceVerdict::COMPLETE_ABSENT]])->assertOk();
        $this->assertNotNull($row->fresh()->verdict);

        // An organiser who decided too early must be able to return the row to
        // unreviewed, which is not the same as marking them present.
        $this->submit($m, [['id' => $row->id, 'verdict' => null]])->assertOk()
            ->assertJsonPath('counts.cleared', 1);

        $row->refresh();
        $this->assertNull($row->verdict);
        $this->assertNull($row->verdict_by);
        $this->assertNull($row->verdict_note);
        $this->assertTrue((bool) $row->attended, 'and the claim is still untouched');
    }

    /* ── the minutes ─────────────────────────────────────────────────── */

    /**
     * The verdict has to reach the document, or the review is a screen nobody
     * outside the CRM ever sees.
     *
     * Rendered rather than asserted about: a Blade change compiles fine and
     * fails at render, and the PDF tests that already exist use meetings with
     * an empty roster — so the attendance table they exercise never enters the
     * loop this touched.
     */
    public function test_the_minutes_print_the_verdict_beside_the_mark(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $row = $this->claimant($m);

        Sanctum::actingAs($organiser);
        $this->submit($m, [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::PARTIAL_ABSENT,
            'verdict_from' => now()->setTime(9, 30)->toDateTimeString(),
            'verdict_to' => now()->setTime(10, 0)->toDateTimeString(),
            'verdict_note' => 'Left before the commercial section.',
        ]])->assertOk();

        $html = view('pdf.kickoff_mom', [
            'meeting' => $m->fresh(['attendees', 'agendaItems']),
            'tenant' => \App\Models\Tenant::find(self::TENANT),
            'projectName' => null,
            'subjectNames' => [],
            'subjectName' => 'AlphaCo',
            'generatedBy' => $organiser->name,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Partial Absent', $html, 'the slab is printed');
        $this->assertStringContainsString('09:30', $html, 'and the window it means');
        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString("Organiser's verdict", $html, 'under its own column');

        // The mark it sits beside is still in the document too — the whole
        // reason for two columns.
        $this->assertStringContainsString('Online', $html);
    }

    /**
     * The same review, and the same minutes, on the Purchase engine.
     *
     * The two rosters were built separately and every difference between them
     * has cost us a defect, so the Purchase half is exercised rather than
     * assumed — a Purchase vendor reading their minutes must not get a
     * different document from a TPV one.
     */
    public function test_purchase_reviews_and_prints_the_same_way(): void
    {
        $organiser = $this->staff();

        $vendor = \App\Models\Purchase\PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
        $m = \App\Models\Purchase\PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $organiser->id, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->setTime(9, 0), 'end_at' => now()->setTime(11, 0), 'duration_minutes' => 120,
            'meeting_platform' => 'zoom', 'meeting_link' => 'https://zoom.us/j/123456',
        ]);
        $row = $m->participants()->create([
            'tenant_id' => self::TENANT, 'name' => 'Sunil', 'email' => 'sunil@southgate.local',
            'side' => 'external', 'attended' => true, 'attendance_status' => 'Online',
            'attendance_source' => MeetingJoinRecorder::SOURCE_LINK, 'joined_at' => now()->setTime(9, 3),
        ]);

        Sanctum::actingAs($organiser);
        $this->postJson("/api/purchase/kickoff/{$m->id}/attendance/review", ['rows' => [[
            'id' => $row->id,
            'verdict' => AttendanceVerdict::COMPLETE_ABSENT,
            'verdict_note' => 'Marked attendance in the CRM but never appeared on the call.',
        ]]])->assertOk()->assertJsonPath('counts.contradicted', 1);

        $row->refresh();
        $this->assertSame(AttendanceVerdict::COMPLETE_ABSENT, $row->verdict);
        // Purchase's roster has no `remark` column, so the claim is carried by
        // these three. They must survive being overruled just the same.
        $this->assertTrue((bool) $row->attended);
        $this->assertSame(MeetingJoinRecorder::SOURCE_LINK, $row->attendance_source);
        $this->assertNotNull($row->joined_at);

        $html = view('pdf.purchase_kickoff_mom', [
            'meeting' => $m->fresh(['participants']),
            'tenant' => \App\Models\Tenant::find(self::TENANT),
            'vendorName' => $vendor->company_name,
            'generatedBy' => $organiser->name,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Complete Absent', $html);
        $this->assertStringContainsString("Organiser's verdict", $html);
        $this->assertStringContainsString('Marked attendance in the CRM', $html,
            'the document says the two records disagree, rather than leaving it to be noticed');
    }

    public function test_the_register_is_readable_by_staff_who_cannot_decide(): void
    {
        $organiser = $this->staff();
        $m = $this->meeting($organiser);
        $this->claimant($m);

        Sanctum::actingAs($this->staff());
        $this->getJson("/api/kickoff/meetings/{$m->id}/attendance/register")->assertOk()
            // Readable, so the minutes can show it — but the screen is told
            // plainly that this person may not change it.
            ->assertJsonPath('may_review', false);
    }
}
