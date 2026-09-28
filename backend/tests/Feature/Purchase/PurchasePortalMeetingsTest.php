<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase vendor portal's meetings endpoint — parity with the TPV one in
 * Tests\Feature\Shared\VendorPortalMeetingsTest.
 *
 * The Purchase dashboard now calls this on load (the meeting schedule card), and
 * every call on that portal goes through an axios client that HARD-REDIRECTS to
 * /purchase-portal/login the moment it sees a session-shaped 401. A dashboard
 * call that answered 401 would therefore eject the vendor to the login screen
 * rather than fail quietly, so what this endpoint answers for a legitimate
 * portal session is worth pinning rather than assuming.
 */
class PurchasePortalMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function clock(int $minutesFromNow): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutesFromNow)->format('Y-m-d H:i:s');
    }

    private function meeting(string $title, int $startsIn, string $status = PurchaseKickoffStatus::SCHEDULED): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id'          => self::TENANT,
            'purchase_vendor_id' => $this->vendor->id,
            'title'              => $title,
            'meeting_type'       => 'kickoff',
            'status'             => $status,
            'mode'               => 'online',
            'meeting_link'       => 'https://meet.example.test/room',
            'scheduled_at'       => $this->clock($startsIn),
            'end_at'             => $this->clock($startsIn + 60),
            'duration_minutes'   => 60,
        ]);
    }

    /** The portal authenticates as the PurchaseVendor itself, not as a User. */
    private function asVendor(): void
    {
        Sanctum::actingAs($this->vendor);
    }

    public function test_a_purchase_vendor_sees_their_own_meetings(): void
    {
        $this->asVendor();
        $this->meeting('Kickoff', 1440);

        $body = $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame('Kickoff', $body[0]['title']);
    }

    public function test_each_meeting_carries_its_end_and_timing(): void
    {
        $this->asVendor();
        $this->meeting('Kickoff', 1440);

        $row = $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data.0');

        foreach (['ends_at', 'duration_minutes', 'timing_state', 'timing_label', 'is_expired', 'is_live'] as $key) {
            $this->assertArrayHasKey($key, $row, "the dashboard card needs {$key}");
        }
        $this->assertSame('upcoming', $row['timing_state']);
    }

    public function test_an_expired_meeting_reads_as_expired_and_loses_its_link(): void
    {
        $this->asVendor();
        $this->meeting('Missed', -300);

        $row = $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data.0');

        $this->assertTrue($row['is_expired']);
        $this->assertNull($row['meeting_link']);
    }

    /**
     * The distinction this draws against the expired case above is whether the
     * meeting is still JOINABLE — and it still is. What changed is that the
     * link was briefly earned rather than given — that toll has gone, because
     * MeetingLinkAnnouncer e-mails the real room to every participant the
     * moment the organiser pastes it. See MeetingAttendanceGate.
     */
    public function test_a_meeting_in_progress_is_joinable_and_carries_its_room(): void
    {
        $this->asVendor();
        $meeting = $this->meeting('Running', -10);

        $res = $this->getJson('/api/portal/purchase/meetings')->assertOk();
        $row = $res->json('data.0');

        $this->assertTrue($row['is_live']);
        $this->assertTrue($row['has_meeting_link'], 'the portal still knows this is an online meeting');
        $this->assertSame('https://meet.example.test/room', $row['meeting_link'],
            'a real room is handed over — it was e-mailed to them anyway');
        $this->assertFalse($row['attendance_marked'], 'and it cost them nothing to get it');

        // Marking attendance still records what it always recorded.
        $this->postJson("/api/portal/purchase/meetings/{$meeting->id}/attendance")->assertOk()
            ->assertJsonPath('attendance_marked', true);

        $this->assertNotNull(
            $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data.0.meeting_link')
        );
    }

    public function test_drafts_stay_invisible_to_the_vendor(): void
    {
        $this->asVendor();
        $this->meeting('Not published', 1440, PurchaseKickoffStatus::DRAFT);

        $this->assertCount(0, $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data'));
    }

    public function test_another_vendors_meeting_is_never_listed(): void
    {
        $other = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'rival-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $other->id,
            'title' => 'Theirs', 'meeting_type' => 'kickoff', 'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(60), 'end_at' => $this->clock(120), 'duration_minutes' => 60,
        ]);

        $this->asVendor();

        $this->assertCount(0, $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data'));
    }

    /**
     * A staff/admin User reaching a Purchase portal endpoint is refused with 403,
     * NOT 401.
     *
     * The distinction is the whole reason the portal client does not sign people
     * out on every 401 it sees: a 403 says "wrong area", a session-shaped 401
     * says "your session is over" and clears the token. If this ever became a
     * 401 the dashboard would throw legitimate users to the login screen.
     */
    public function test_an_admin_user_is_refused_with_403_not_401(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->getJson('/api/portal/purchase/meetings')->assertStatus(403);
    }

    /** No credential at all is a 401 — the guard's job, and the login screen's cue. */
    public function test_an_anonymous_caller_is_unauthenticated(): void
    {
        $this->getJson('/api/portal/purchase/meetings')->assertStatus(401);
    }
}
