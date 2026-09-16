<?php

namespace Tests\Feature\Shared;

use App\Models\Notification;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\KickoffMeetingService;
use App\Services\Shared\MeetingInviteService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Does the invitation actually REACH the people it names?
 *
 * Reported from the running system: "in-app notification and mail don't go to
 * the selected vendor or selected persons". Three separate holes, all of which
 * reported success:
 *
 *  1. The vendor the meeting is ABOUT was added to the recipient list with a
 *     hard-coded null login, so the one party the meeting concerns was the only
 *     one who never got a bell notification.
 *
 *  2. The bell, when it did fire, always linked to the staff console — so a
 *     vendor following their own notification landed on a page they have no
 *     access to. The same bug the invitation e-mail had, still here because the
 *     two were fixed separately.
 *
 *  3. Somebody put on the roster by name alone — no address, no login — was
 *     counted as "skipped" and never named, so the organiser was told the send
 *     had worked.
 *
 * And the expiry notice had all three, plus no bell at all.
 */
class MeetingNotificationReachTest extends TestCase
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

    /** A vendor with a portal login, which is how most of them exist. */
    private function vendorWithLogin(): array
    {
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => 'AlphaCo', 'role' => 'third_party_vendor',
            'email' => 'alpha-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $login->id,
        ]);

        return [$login, $vendor];
    }

    private function meeting(Vendor $vendor, User $organiser, array $overrides = []): KickoffMeeting
    {
        return KickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT, 'created_by' => $organiser->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
            'meeting_platform' => 'google_meet', 'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ], $overrides));
    }

    /* ── the invitation ──────────────────────────────────────────────── */

    public function test_the_vendor_the_meeting_is_about_gets_a_bell_notification(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        app(MeetingInviteService::class)->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $bell = Notification::where('user_id', $login->id)->where('type', 'meeting')->first();

        $this->assertNotNull($bell, 'the party the meeting is about must hear about it inside the CRM');
        // And it has to land somewhere they can open. The console is not that.
        $this->assertSame('/vendor-portal/governance', $bell->link);
        $this->assertStringContainsString('Kickoff', $bell->title);
    }

    public function test_a_staff_recipients_bell_still_points_at_the_console(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        app(MeetingInviteService::class)->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $bell = Notification::where('user_id', $organiser->id)->where('type', 'meeting')->first();

        $this->assertNotNull($bell);
        $this->assertSame('/app/tpv/kickoff/'.$meeting->id, $bell->link,
            'fixing the vendor link must not send staff to the portal');
    }

    public function test_somebody_reachable_by_neither_channel_is_named_not_just_counted(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        // Typed onto the roster by name. No address, no login — exactly what the
        // scheduling form allows, and exactly the person who silently hears
        // nothing while the send reports success.
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi from Acme', 'side' => 'external',
        ]);

        $counts = app(MeetingInviteService::class)
            ->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $this->assertContains('Ravi from Acme', $counts['unreachable'],
            'the organiser has to be told WHO was never invited, not just how many');
    }

    public function test_a_reachable_roster_row_is_not_reported_unreachable(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Sunita', 'email' => 'sunita@alphaco.local', 'side' => 'external',
        ]);

        $counts = app(MeetingInviteService::class)
            ->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $this->assertNotContains('Sunita', $counts['unreachable']);
    }

    /* ── the expiry notice ───────────────────────────────────────────── */

    public function test_an_expired_meeting_tells_the_vendor_by_bell_as_well_as_mail(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();

        // Its slot passed while it was still open — nobody completed or
        // cancelled it.
        $meeting = $this->meeting($vendor, $organiser, [
            'scheduled_at' => now()->subHours(4),
            'end_at' => now()->subHours(3),
        ]);
        $this->assertTrue($meeting->fresh()->is_expired, 'the fixture must actually be expired');

        $fired = app(KickoffMeetingService::class)->runDueExpiryNotices();
        $this->assertSame(1, $fired, 'the sweep found it');

        $bell = Notification::where('user_id', $login->id)->where('type', 'meeting')
            ->where('title', 'like', 'Meeting expired%')->first();

        // The expiry only ever went out by e-mail, so it was invisible to anyone
        // reading the CRM — which is where the meeting itself lives.
        $this->assertNotNull($bell, 'the vendor is told inside the CRM that the meeting lapsed');
        $this->assertSame('/vendor-portal/governance', $bell->link);
    }

    public function test_the_expiry_notice_is_sent_once(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $this->meeting($vendor, $organiser, [
            'scheduled_at' => now()->subHours(4),
            'end_at' => now()->subHours(3),
        ]);

        $service = app(KickoffMeetingService::class);
        $this->assertSame(1, $service->runDueExpiryNotices());
        // The sweep runs every fifteen minutes. Telling somebody four times an
        // hour that a meeting lapsed is how a notification channel gets muted.
        $this->assertSame(0, $service->runDueExpiryNotices());

        $this->assertSame(1, Notification::where('user_id', $login->id)
            ->where('title', 'like', 'Meeting expired%')->count());
    }
}
