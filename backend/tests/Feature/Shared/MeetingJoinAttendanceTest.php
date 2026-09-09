<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\MeetingJoinRecorder;
use App\Services\Shared\MeetingPresence;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\BusinessTime;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance for a meeting held somewhere we cannot see.
 *
 * MeetingPresence watches the call and is the better record, but it only works
 * for a meeting held INSIDE the CRM. Most are not: an administrator schedules
 * one on Google Meet, opens it, talks for an hour and closes it, and nothing
 * here observed any of it. The register stayed empty and somebody rebuilt it
 * from memory afterwards — the exact thing a register exists to prevent.
 *
 * What can be seen on every platform is somebody marking attendance in the CRM.
 * That is not proof they stayed, so it is recorded as what it is and labelled as
 * such: three sources, ranked, each saying honestly how it knows.
 *
 * Marking attendance is also what RELEASES the link now — see
 * MeetingAttendanceGate — so this is no longer a record taken on the way past.
 * It is the thing the person came here to do.
 */
class MeetingJoinAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;

    private User $vendorUser;

    private PurchaseVendor $pVendor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose', 'role' => 'third_party_vendor',
            'email' => 'rita-'.Str::random(4).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::ACTIVE, 'user_id' => $this->vendorUser->id,
        ]);

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    /**
     * A time that is genuinely in the future on the TENANT's clock.
     *
     * Meeting times are wall clocks in the business timezone, which runs hours
     * ahead of the server's — so `now()->addHour()` is in the PAST there, and a
     * meeting built that way reads as expired before anyone can join it.
     */
    private function clock(int $minutes): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutes)->format('Y-m-d H:i:s');
    }

    /** A meeting on Google Meet — a link to somewhere we cannot observe. */
    private function meeting(string $link = 'https://meet.google.com/abc-defg-hij'): KickoffMeeting
    {
        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $this->vendor->id, 'title' => 'Kickoff',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => $this->clock(60),
            'duration_minutes' => 60, 'meeting_platform' => 'google_meet', 'meeting_link' => $link,
        ]);
    }

    private function purchaseMeeting(): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => $this->clock(60),
            'duration_minutes' => 60, 'meeting_platform' => 'zoom',
            'meeting_link' => 'https://zoom.us/j/123456',
        ]);
    }

    /* ── the gap this closes ─────────────────────────────────────────────── */

    /**
     * The reported problem: an hour-long Google Meet, and no idea who came.
     *
     * Nobody joined through the embedded room — there is no embedded room for a
     * Google Meet — so the only thing that can be recorded is the Join click.
     */
    public function test_joining_a_google_meet_from_the_portal_records_attendance(): void
    {
        $m = $this->meeting();
        $row = $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose',
            'email' => $this->vendorUser->email, 'user_id' => $this->vendorUser->id, 'side' => 'external',
        ]);

        $this->assertFalse((bool) $row->attended, 'nothing is known before they press Join');

        Sanctum::actingAs($this->vendorUser);
        $res = $this->postJson("/api/portal/meetings/{$m->id}/attendance")->assertOk();

        $this->assertSame('https://meet.google.com/abc-defg-hij', $res->json('meeting_link'),
            'the browser gets the real meeting link, in exchange for the record');
        $this->assertTrue($res->json('recorded'));

        $row->refresh();
        $this->assertTrue((bool) $row->attended);
        $this->assertNotNull($row->joined_at);
        $this->assertSame(MeetingJoinRecorder::SOURCE_LINK, $row->attendance_source,
            'and the register says HOW it knows, rather than implying somebody was watched');
    }

    /** The device and time are kept, because "how do we know?" is the question. */
    public function test_the_join_records_when_and_from_what(): void
    {
        $m = $this->meeting();
        $row = $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose',
            'email' => $this->vendorUser->email, 'user_id' => $this->vendorUser->id, 'side' => 'external',
        ]);

        Sanctum::actingAs($this->vendorUser);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) AppleWebKit Safari')
            ->postJson("/api/portal/meetings/{$m->id}/attendance")->assertOk();

        $this->assertStringContainsString('Mobile', (string) $row->fresh()->remark);
        $this->assertStringContainsString('Safari', (string) $row->fresh()->remark);
    }

    public function test_purchase_records_a_join_the_same_way(): void
    {
        $m = $this->purchaseMeeting();
        $row = $m->participants()->create([
            'tenant_id' => self::TENANT, 'name' => 'Southgate',
            'email' => $this->pVendor->email, 'side' => 'external',
        ]);

        Sanctum::actingAs($this->pVendor);
        $this->postJson("/api/portal/purchase/meetings/{$m->id}/attendance")
            ->assertOk()
            ->assertJsonPath('meeting_link', 'https://zoom.us/j/123456')
            ->assertJsonPath('recorded', true);

        $this->assertTrue((bool) $row->fresh()->attended);
        $this->assertSame(MeetingJoinRecorder::SOURCE_LINK, $row->fresh()->attendance_source);
    }

    /* ── the sources rank ────────────────────────────────────────────────── */

    /**
     * A Join click must never downgrade what the call itself saw.
     *
     * Somebody watched arriving and leaving has a far better record than "they
     * pressed a button"; reopening the link from an e-mail afterwards would
     * otherwise erase how long they were really there.
     */
    public function test_a_later_join_click_does_not_overwrite_an_observed_call(): void
    {
        $m = $this->meeting();
        $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose',
            'email' => $this->vendorUser->email, 'user_id' => $this->vendorUser->id, 'side' => 'external',
        ]);

        // Seen in the call first.
        app(MeetingPresence::class)->reconcile($m, [['key' => 'p1', 'name' => 'Rita Bose']], false, null);
        $row = $m->attendees()->sole();
        $this->assertSame(MeetingJoinRecorder::SOURCE_CALL, $row->attendance_source);
        $seenAt = $row->joined_at;

        Sanctum::actingAs($this->vendorUser);
        $this->postJson("/api/portal/meetings/{$m->id}/attendance")->assertOk();

        $row->refresh();
        $this->assertSame(MeetingJoinRecorder::SOURCE_CALL, $row->attendance_source,
            'the stronger source stands');
        $this->assertEquals($seenAt->format('Y-m-d H:i:s'), $row->joined_at->format('Y-m-d H:i:s'),
            'and the time they were actually seen is not moved');
    }

    /** A tick made by hand is a person deciding, and it outranks both. */
    public function test_marking_by_hand_is_recorded_as_such(): void
    {
        $m = $this->meeting();
        $row = $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose', 'side' => 'external',
        ]);

        $admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(4).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/kickoff/meetings/{$m->id}/attendance", [
            'attendance' => [['id' => $row->id, 'attendance_status' => 'Present']],
        ])->assertOk();

        $this->assertSame(MeetingJoinRecorder::SOURCE_MANUAL, $row->fresh()->attendance_source);
    }

    /* ── the edges ───────────────────────────────────────────────────────── */

    /**
     * Somebody not on the roster is seated from who they actually are.
     *
     * This used to assert the opposite: no row was created, and the link was
     * handed over anyway. That was defensible while the link was free — the
     * recorder refuses to invent a row because a name guessed from a login puts
     * the wrong person on the register, and it still refuses.
     *
     * It stopped being defensible once the link had to be earned. A meeting
     * scheduled FOR a vendor commonly has no participant row for that vendor at
     * all — which is why the invitation code adds them separately — so the rule
     * as written locked those vendors out of their own meeting for ever.
     *
     * Nothing is guessed. The portal knows exactly who is asking, because the
     * request authenticated as that vendor, and it passes that identity in. The
     * name on the register is the vendor's own.
     */
    public function test_a_joiner_who_is_not_on_the_roster_is_seated_from_their_own_identity(): void
    {
        $m = $this->meeting();

        Sanctum::actingAs($this->vendorUser);
        $this->postJson("/api/portal/meetings/{$m->id}/attendance")
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('meeting_link', 'https://meet.google.com/abc-defg-hij');

        $row = $m->fresh()->attendees()->sole();
        $this->assertSame($this->vendor->company_name, $row->name,
            'the vendor record, not a name guessed from a login');
        $this->assertSame($this->vendor->email, $row->email);
        $this->assertTrue((bool) $row->attended);
    }

    /** A meeting that is over is not open to join, and records nothing. */
    public function test_a_finished_meeting_cannot_be_joined(): void
    {
        $m = $this->meeting();
        $m->forceFill([
            'scheduled_at' => $this->clock(-180),
            'end_at' => $this->clock(-120),
        ])->save();

        Sanctum::actingAs($this->vendorUser);
        $this->postJson("/api/portal/meetings/{$m->id}/attendance")->assertNotFound();
    }

    /** And another vendor's meeting is not joinable at all. */
    public function test_one_vendor_cannot_join_anothers_meeting(): void
    {
        $m = $this->meeting();

        $other = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rival', 'role' => 'third_party_vendor',
            'email' => 'rival-'.Str::random(4).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'status' => VendorStatus::ACTIVE, 'user_id' => $other->id,
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/portal/meetings/{$m->id}/attendance")->assertNotFound();
    }
}
