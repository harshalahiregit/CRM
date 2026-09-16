<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Purchase\PurchaseKickoffService;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MeetingTiming;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The meeting clock, end to end, in BOTH engines.
 *
 * Three things were wrong and are pinned here:
 *
 *  1. Rescheduling moved the start and left the end behind, so a 60-minute
 *     meeting dragged to a new time became seventeen hours long and read as
 *     still in progress the next day.
 *  2. Nothing anywhere distinguished "scheduled for next week" from "scheduled
 *     for last Tuesday and never closed". Both said Scheduled, and the vendor
 *     portal offered a Join button for both.
 *  3. No one was told when a meeting's slot passed with the record still open.
 */
class MeetingTimingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Coordinator', 'role' => 'admin',
            'email' => 'c-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A wall clock N minutes from now, on the tenant's own clock. */
    private function clock(int $minutesFromNow): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutesFromNow)->format('Y-m-d H:i:s');
    }

    /* ── MeetingTiming, the one derivation both engines use ──────────────── */

    public function test_a_meeting_yet_to_start_is_upcoming(): void
    {
        $this->assertSame(
            MeetingTiming::UPCOMING,
            MeetingTiming::state($this->clock(60), $this->clock(120), 60, false, false, self::TENANT),
        );
    }

    public function test_a_meeting_between_its_start_and_end_is_live(): void
    {
        $this->assertSame(
            MeetingTiming::LIVE,
            MeetingTiming::state($this->clock(-10), $this->clock(50), 60, false, false, self::TENANT),
        );
    }

    public function test_a_meeting_past_its_end_and_still_open_is_expired(): void
    {
        $this->assertSame(
            MeetingTiming::EXPIRED,
            MeetingTiming::state($this->clock(-180), $this->clock(-120), 60, false, false, self::TENANT),
        );
    }

    public function test_a_completed_or_cancelled_meeting_is_never_expired(): void
    {
        $this->assertSame(
            MeetingTiming::CLOSED,
            MeetingTiming::state($this->clock(-180), $this->clock(-120), 60, false, true, self::TENANT),
        );
    }

    public function test_a_draft_is_never_expired_however_old(): void
    {
        $this->assertSame(
            MeetingTiming::DRAFT,
            MeetingTiming::state($this->clock(-9999), $this->clock(-9900), 60, true, false, self::TENANT),
        );
    }

    public function test_a_meeting_with_no_stored_end_falls_back_to_its_duration(): void
    {
        // Every row written before end_at existed looks like this. Without the
        // fallback none of them could ever expire.
        $endsAt = MeetingTiming::endsAt($this->clock(-90), null, 30, self::TENANT);

        $this->assertNotNull($endsAt);
        $this->assertSame(
            MeetingTiming::EXPIRED,
            MeetingTiming::state($this->clock(-90), null, 30, false, false, self::TENANT),
        );
    }

    public function test_an_end_before_its_start_is_ignored_in_favour_of_the_duration(): void
    {
        // The shape a half-applied reschedule leaves behind. Believing it would
        // make the meeting negative-length.
        $start  = $this->clock(0);
        $endsAt = MeetingTiming::endsAt($start, $this->clock(-500), 45, self::TENANT);

        $this->assertSame(45, (int) round(BusinessTime::parse($start, self::TENANT)->diffInMinutes($endsAt, false)));
    }

    /* ── Rescheduling keeps the meeting's length ─────────────────────────── */

    public function test_moving_a_shared_meeting_moves_its_end_with_it(): void
    {
        $actor = $this->actor();
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $service = app(KickoffMeetingService::class);

        $meeting = $service->schedule([
            'subject_type' => 'vendor', 'subject_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'scheduled_at' => $this->clock(1440),
            'end_at' => $this->clock(1500),           // 60 minutes long
        ], $actor);

        $this->assertSame(60, (int) $meeting->fresh()->duration_minutes);

        // Reschedule: a new START only, which is what the form sends when the
        // organiser drags the meeting to another slot.
        $moved = $service->update($meeting->fresh(), ['scheduled_at' => $this->clock(2880)], $actor)->fresh();

        $this->assertSame(60, (int) $moved->duration_minutes, 'the meeting should keep its length');
        $this->assertSame(
            60,
            (int) round($moved->scheduled_at->diffInMinutes($moved->end_at, false)),
            'the end must travel with the start, not stay on the old absolute time',
        );
    }

    public function test_moving_a_purchase_meeting_moves_its_end_with_it(): void
    {
        $actor = $this->actor();
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'a-'.Str::random(4).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
        $service = app(PurchaseKickoffService::class);

        $meeting = $service->schedule([
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'scheduled_at' => $this->clock(1440),
            'end_at' => $this->clock(1500),
        ], $actor);

        $this->assertSame(60, (int) $meeting->fresh()->duration_minutes);

        $moved = $service->update($meeting->fresh(), ['scheduled_at' => $this->clock(2880)], $actor)->fresh();

        $this->assertSame(60, (int) $moved->duration_minutes);
        $this->assertSame(60, (int) round($moved->scheduled_at->diffInMinutes($moved->end_at, false)));
    }

    public function test_an_explicit_end_still_changes_the_length(): void
    {
        // Moving a meeting keeps its length; deliberately setting a new end is a
        // different intent and must still win.
        $actor = $this->actor();
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Beta', 'status' => VendorStatus::ACTIVE]);
        $service = app(KickoffMeetingService::class);

        $meeting = $service->schedule([
            'subject_type' => 'vendor', 'subject_id' => $vendor->id,
            'title' => 'Review', 'meeting_type' => 'kickoff',
            'scheduled_at' => $this->clock(1440), 'end_at' => $this->clock(1500),
        ], $actor);

        $stretched = $service->update($meeting->fresh(), [
            'scheduled_at' => $this->clock(1440),
            'end_at'       => $this->clock(1560),   // now 120 minutes
        ], $actor)->fresh();

        $this->assertSame(120, (int) $stretched->duration_minutes);
    }

    /* ── The models expose it, so every payload carries it ───────────────── */

    public function test_both_models_report_an_expired_meeting(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Gamma', 'status' => VendorStatus::ACTIVE]);
        $shared = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Past', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-180), 'end_at' => $this->clock(-120), 'duration_minutes' => 60,
        ]);

        $pv = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Delta',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'd-'.Str::random(4).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
        $purchase = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $pv->id,
            'title' => 'Past', 'meeting_type' => 'kickoff', 'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-180), 'end_at' => $this->clock(-120), 'duration_minutes' => 60,
        ]);

        foreach ([$shared, $purchase] as $m) {
            $this->assertTrue($m->is_expired, class_basename($m).' should be expired');
            $this->assertFalse($m->is_live);
            $this->assertSame('expired', $m->timing_state);
            $this->assertSame('Expired', $m->timing_label);

            // Appended, so it rides along on every list and detail payload.
            $json = $m->toArray();
            foreach (['timing_state', 'timing_label', 'is_expired', 'is_live', 'ends_at'] as $key) {
                $this->assertArrayHasKey($key, $json, "{$key} must be serialised");
            }
        }
    }

    /* ── The notice ──────────────────────────────────────────────────────── */

    public function test_an_expired_meeting_is_notified_exactly_once(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Epsilon', 'status' => VendorStatus::ACTIVE]);
        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Missed', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-180), 'end_at' => $this->clock(-120), 'duration_minutes' => 60,
        ]);

        $service = app(KickoffMeetingService::class);

        $this->assertSame(1, $service->runDueExpiryNotices(), 'the expired meeting should be notified');
        $this->assertContains('expired', $meeting->fresh()->reminders_sent ?? []);

        // A second sweep must not tell everyone again.
        $this->assertSame(0, $service->runDueExpiryNotices(), 'the notice must not repeat');
    }

    public function test_an_upcoming_meeting_is_not_notified(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Zeta', 'status' => VendorStatus::ACTIVE]);
        KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Ahead', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(120), 'end_at' => $this->clock(180), 'duration_minutes' => 60,
        ]);

        $this->assertSame(0, app(KickoffMeetingService::class)->runDueExpiryNotices());
    }

    public function test_a_long_expired_meeting_is_left_alone(): void
    {
        // Switching the sweep on must not mail the roster of every meeting
        // anyone ever left open.
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Eta', 'status' => VendorStatus::ACTIVE]);
        KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Ancient', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-60 * 24 * 30), 'end_at' => $this->clock(-60 * 24 * 30 + 60),
            'duration_minutes' => 60,
        ]);

        $this->assertSame(0, app(KickoffMeetingService::class)->runDueExpiryNotices());
    }

    public function test_the_purchase_engine_notifies_its_own_expired_meetings(): void
    {
        $pv = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Theta',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 't-'.Str::random(4).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $pv->id,
            'title' => 'Missed', 'meeting_type' => 'kickoff', 'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-180), 'end_at' => $this->clock(-120), 'duration_minutes' => 60,
        ]);

        $service = app(PurchaseKickoffService::class);

        $this->assertSame(1, $service->runDueExpiryNotices());
        $this->assertContains('expired', $meeting->fresh()->reminders_sent ?? []);
        $this->assertSame(0, $service->runDueExpiryNotices());
    }
}
