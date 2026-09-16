<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Shared\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A meeting starts at the time it was set for — on the screen, in the mail, and
 * in the calendar attachment.
 *
 * The reported fault: a meeting entered for 2:30 PM came back reading 8:00 PM,
 * and the invitation disagreed with the screen. `scheduled_at` holds a wall
 * clock in the tenant's timezone, but the plain `datetime` cast published it as
 * though that wall clock were UTC, so every reader localised it a second time.
 * Re-saving then stored the shifted value, so each edit walked the meeting a
 * further +05:30 down the day.
 *
 * The invariant here: **the hour that was typed is the hour that comes back,
 * however many times it is re-saved, and every channel agrees on one instant.**
 */
class MeetingWallClockTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** The tenant's zone. +05:30 is exactly the offset that was being lost. */
    private const ZONE = 'Asia/Kolkata';

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);
    }

    /** The instant "2:30 PM in the tenant's zone", a few days out. */
    private function twoThirtyPm(): Carbon
    {
        return Carbon::now(self::ZONE)->addDays(4)->setTime(14, 30);
    }

    /* ── The round trip ─────────────────────────────────────────────────── */

    public function test_the_hour_typed_is_the_hour_stored(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $start = $this->twoThirtyPm();

        // The form now sends an instant carrying its offset — what a browser in
        // any timezone produces.
        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff — Bolt Supplies',
            'scheduled_at' => $start->toIso8601String(),
            'end_at' => $start->copy()->addHour()->toIso8601String(),
            'mode' => 'online',
        ])->assertSuccessful();

        $meeting = PurchaseKickoffMeeting::latest('id')->first();

        // Stored as the tenant's wall clock — 14:30, not 09:00 and not 20:00.
        $this->assertSame(
            $start->format('Y-m-d H:i:s'),
            $meeting->getRawOriginal('scheduled_at'),
            'the row holds the wall clock that was entered'
        );
        // And read back as that same real instant.
        $this->assertTrue($start->equalTo($meeting->scheduled_at));
        $this->assertSame('14:30', $meeting->scheduled_at->format('H:i'));
    }

    public function test_a_bare_wall_clock_is_read_in_the_tenants_zone(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $start = $this->twoThirtyPm();

        // An API client — or an older build of the form — sending no offset. It
        // means 2:30 PM where the tenant is, not 2:30 PM UTC.
        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title' => 'No-offset client',
            'scheduled_at' => $start->format('Y-m-d\TH:i:s'),
            'end_at' => $start->copy()->addHour()->format('Y-m-d\TH:i:s'),
            'mode' => 'online',
        ])->assertSuccessful();

        $meeting = PurchaseKickoffMeeting::latest('id')->first();
        $this->assertSame('14:30', $meeting->scheduled_at->format('H:i'));
        $this->assertSame(self::ZONE, $meeting->scheduled_at->timezoneName);
    }

    public function test_the_time_does_not_drift_when_the_meeting_is_re_saved(): void
    {
        // The compounding half of the bug: reading gave 20:00 for a 14:30
        // meeting, and saving that back stored 20:00 — so opening a meeting and
        // saving it without touching the time moved it, every single time.
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $start = $this->twoThirtyPm();
        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Drift check',
            'scheduled_at' => $start->toIso8601String(),
            'end_at' => $start->copy()->addHour()->toIso8601String(),
            'mode' => 'online',
        ])->assertSuccessful();

        $meeting = PurchaseKickoffMeeting::latest('id')->first();

        for ($i = 1; $i <= 3; $i++) {
            // Exactly what the edit form does: read the published value and
            // hand it straight back untouched.
            $body = $this->getJson("/api/purchase/kickoff/{$meeting->id}")->assertOk()->json();
            $published = $body['data']['scheduled_at'] ?? $body['scheduled_at'] ?? null;
            $this->assertNotNull($published, 'the API publishes the start time');

            $this->putJson("/api/purchase/kickoff/{$meeting->id}", [
                'scheduled_at' => $published,
                'end_at' => $start->copy()->addHour()->toIso8601String(),
            ])->assertSuccessful();

            $this->assertSame('14:30', $meeting->fresh()->scheduled_at->format('H:i'), "unchanged after re-save #{$i}");
        }
    }

    public function test_what_the_api_publishes_is_the_instant_the_meeting_starts(): void
    {
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'Serialization', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => $this->twoThirtyPm(),
        ]);

        // Whatever form it takes on the wire, parsing it must land on 14:30 in
        // the tenant's zone — that is all a browser needs to render it right.
        $published = $meeting->fresh()->toArray()['scheduled_at'];
        $this->assertSame('14:30', Carbon::parse($published)->setTimezone(self::ZONE)->format('H:i'));
    }

    public function test_a_time_assigned_in_another_zone_reads_back_on_the_tenants_clock(): void
    {
        // Eloquent caches an OBJECT handed to a class-cast attribute verbatim,
        // so without normalising on assignment the model kept the caller's zone
        // until it was refreshed. That gap made a plain rename look like a
        // reschedule (the two sides of the comparison straddled the refresh),
        // which re-notified the roster and wiped the fired-reminder ledger.
        $meeting = new PurchaseKickoffMeeting;
        $meeting->tenant_id = self::TENANT;
        $meeting->scheduled_at = Carbon::parse('2026-09-04 09:00:00', 'UTC');

        // Same instant, told on the tenant's clock — before any save or reload.
        $this->assertSame('14:30', $meeting->scheduled_at->format('H:i'));
        $this->assertSame(self::ZONE, $meeting->scheduled_at->timezoneName);

        $meeting->purchase_vendor_id = $this->vendor()->id;
        $meeting->title = 'Assigned in UTC';
        $meeting->status = 'Scheduled';
        $meeting->save();

        // And unchanged by the round trip through the database.
        $this->assertSame('14:30', $meeting->fresh()->scheduled_at->format('H:i'));
    }

    /* ── Machine timestamps must NOT move ───────────────────────────────── */

    public function test_created_at_is_still_a_utc_instant(): void
    {
        // created_at and its siblings are written by now() in UTC and were
        // always correct. The fix is scoped to person-entered times; applied
        // globally it would have shifted these by the tenant's offset instead.
        Carbon::setTestNow(Carbon::parse('2026-09-04 09:00:00', 'UTC'));

        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'Machine stamp', 'status' => 'Scheduled',
            'scheduled_at' => $this->twoThirtyPm(),
        ]);

        $this->assertSame('2026-09-04 09:00:00', $meeting->fresh()->getRawOriginal('created_at'));
    }

    /* ── Every channel agrees ───────────────────────────────────────────── */

    public function test_the_invitation_and_the_calendar_file_state_the_same_start(): void
    {
        $start = $this->twoThirtyPm();
        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'title' => 'Channel agreement',
            'status' => 'Scheduled', 'mode' => 'online', 'scheduled_at' => $start,
        ]);

        // What the e-mail body prints.
        $this->assertSame('02:30 PM', $meeting->scheduled_at->format('h:i A'));

        // What a calendar client reads. The ICS marks the same instant in UTC —
        // 09:00Z — which every client renders back as 2:30 PM in the tenant's
        // zone. Before the fix it emitted 14:30Z and calendars showed 8:00 PM.
        $this->assertSame(
            $start->copy()->utc()->format('Ymd\THis\Z'),
            $meeting->scheduled_at->copy()->utc()->format('Ymd\THis\Z')
        );
        $this->assertSame('09:00', $meeting->scheduled_at->copy()->utc()->format('H:i'));
    }

    /* ── "Today" is counted on the tenant's clock ────────────────────────── */

    public function test_a_meeting_late_tonight_counts_as_today(): void
    {
        // 23:30 in Kolkata is 18:00 UTC — the same date here, but the general
        // case (00:30 Kolkata is still yesterday in UTC) is the one that moved
        // late meetings out of "today" on the dashboard.
        Carbon::setTestNow(Carbon::parse('2026-09-04 18:45:00', self::ZONE));

        PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'Evening standup', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => Carbon::parse('2026-09-04 23:30:00', self::ZONE),
        ]);

        Sanctum::actingAs($this->admin());
        $body = $this->getJson('/api/purchase/kickoff/dashboard')->assertOk()->json();
        $stats = $body['data'] ?? $body;

        $this->assertSame(1, $stats['today'] ?? null, 'a meeting later tonight is today');
    }

    /* ── The past-start guard measures on the same clock ─────────────────── */

    public function test_a_start_earlier_today_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 18:00:00', self::ZONE));

        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        // 10 AM this morning. Judged against a UTC now() this read as still
        // hours away and slipped straight through.
        $past = Carbon::parse('2026-09-04 10:00:00', self::ZONE);

        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Already happened',
            'scheduled_at' => $past->format('Y-m-d\TH:i:s'),
            'end_at' => $past->copy()->addHour()->format('Y-m-d\TH:i:s'),
            'mode' => 'online',
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    public function test_a_start_later_today_is_accepted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 18:00:00', self::ZONE));

        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $later = Carbon::parse('2026-09-04 21:00:00', self::ZONE);

        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Tonight',
            'scheduled_at' => $later->format('Y-m-d\TH:i:s'),
            'end_at' => $later->copy()->addHour()->format('Y-m-d\TH:i:s'),
            'mode' => 'online',
        ])->assertSuccessful();
    }

    /* ── Rows written before the fix keep their meaning ──────────────────── */

    public function test_an_existing_row_reads_as_the_clock_it_was_entered_on(): void
    {
        // No migration was run, and none is needed: the column always held the
        // entered wall clock. What changed is that it is now READ as one.
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'Legacy row', 'status' => 'Scheduled',
        ]);
        DB::table('purchase_kickoff_meetings')->where('id', $meeting->id)
            ->update(['scheduled_at' => '2026-09-04 09:00:00']);

        $this->assertSame('09:00', $meeting->fresh()->scheduled_at->format('H:i'));
        $this->assertSame(self::ZONE, $meeting->fresh()->scheduled_at->timezoneName);
    }
}
