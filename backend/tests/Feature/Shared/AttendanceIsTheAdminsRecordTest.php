<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance is the admin's record, and it says so.
 *
 * Three things this holds, all of which were missing:
 *
 *  1. **Authority.** The tick used to be gated on "can you see this meeting?",
 *     which meant any member of staff could rewrite who attended — on a
 *     document that goes to the vendor over the organiser's name. It is now the
 *     same authority as the verdict: the organiser, or an admin.
 *  2. **Authorship.** marked_by / marked_at, so the record has an author.
 *  3. **The times.** The admin types when each person came and went. The CRM
 *     cannot see a call on Google's servers, and joined_at only ever existed
 *     for the few who pressed Join here — so those stay as evidence and the
 *     typed window is the official record.
 *
 * And the fourth, which is the other half of the room-link work: a real room is
 * no longer withheld from someone who has not marked attendance, because it is
 * already in their inbox.
 */
class AttendanceIsTheAdminsRecordTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const ROOM = 'https://meet.google.com/abc-defg-hij';

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
            'email' => 'alpha-'.Str::random(5).'@vendor.local', 'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function meeting(User $organiser, array $overrides = []): KickoffMeeting
    {
        return KickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT, 'created_by' => $organiser->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $this->vendor()->id,
            'title' => 'Kickoff — AlphaCo', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            'meeting_platform' => 'google_meet', 'meeting_link' => self::ROOM,
        ], $overrides));
    }

    /* ── 1. authority ────────────────────────────────────────────────── */

    public function test_a_staff_member_who_can_merely_see_the_meeting_cannot_record_attendance(): void
    {
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser);
        $row = $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        $bystander = $this->staff();
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'user_id' => $bystander->id, 'name' => $bystander->name,
            'email' => $bystander->email, 'side' => 'internal',
        ]);

        Sanctum::actingAs($bystander);
        $this->patchJson("/api/kickoff/meetings/{$meeting->id}/attendance", [
            'attendance' => [['id' => $row->id, 'attendance_status' => 'Absent']],
        ])->assertStatus(403);

        $this->assertNull($row->fresh()->attendance_status, 'nothing was written');
    }

    public function test_the_organiser_and_an_admin_may_record_it(): void
    {
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser);
        $row = $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        Sanctum::actingAs($organiser);
        $this->patchJson("/api/kickoff/meetings/{$meeting->id}/attendance", [
            'attendance' => [['id' => $row->id, 'attendance_status' => 'Present']],
        ])->assertOk();

        Sanctum::actingAs($this->staff('admin'));
        $this->patchJson("/api/kickoff/meetings/{$meeting->id}/attendance", [
            'attendance' => [['id' => $row->id, 'attendance_status' => 'Late']],
        ])->assertOk();

        $this->assertSame('Late', $row->fresh()->attendance_status);
    }

    /* ── 2 + 3. authorship and the typed times ───────────────────────── */

    public function test_the_tick_stores_who_marked_it_when_and_the_in_out_window(): void
    {
        $admin = $this->staff('admin');
        $meeting = $this->meeting($admin);
        $row = $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
            // Observed evidence, written by the room. The typed entry must not
            // overwrite it — there would then be nothing left to check against.
            'joined_at' => now()->setTime(10, 7), 'seconds_in_call' => 1200,
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/kickoff/meetings/{$meeting->id}/attendance", [
            'attendance' => [[
                'id' => $row->id, 'attendance_status' => 'Present',
                'in_at' => now()->setTime(10, 0)->toDateTimeString(),
                'out_at' => now()->setTime(11, 30)->toDateTimeString(),
            ]],
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame((int) $admin->id, (int) $fresh->marked_by);
        $this->assertNotNull($fresh->marked_at);
        $this->assertSame('10:00', $fresh->in_at->format('H:i'));
        $this->assertSame('11:30', $fresh->out_at->format('H:i'));
        $this->assertSame(90, $fresh->attendance_minutes, 'the duration is computed from the typed window');
        $this->assertSame($admin->name, $fresh->marked_by_name);

        // The observed evidence survives underneath it.
        $this->assertSame('10:07', $fresh->joined_at->format('H:i'));
        $this->assertSame(1200, (int) $fresh->seconds_in_call);
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $admin = $this->staff('admin');
        $meeting = $this->meeting($admin);
        $row = $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/kickoff/meetings/{$meeting->id}/attendance", [
            'attendance' => [[
                'id' => $row->id, 'attendance_status' => 'Present',
                'in_at' => now()->setTime(11, 0)->toDateTimeString(),
                'out_at' => now()->setTime(10, 0)->toDateTimeString(),
            ]],
        ])->assertStatus(422);
    }

    /* ── 4. the link is no longer the price of marking ───────────────── */

    public function test_an_invited_viewer_sees_a_real_room_without_marking_attendance(): void
    {
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser);
        $attendee = $this->staff();
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'user_id' => $attendee->id, 'name' => $attendee->name,
            'email' => $attendee->email, 'side' => 'internal',
        ]);

        Sanctum::actingAs($attendee);

        // The room is already in their inbox — MeetingLinkAnnouncer mailed it
        // the moment the organiser pasted it. Withholding it here would only
        // make the CRM the slow route to a link they already have.
        $this->getJson("/api/kickoff/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('meeting_link', self::ROOM)
            ->assertJsonPath('attendance_marked', false)
            ->assertJsonPath('can_mark_attendance', true);

        $this->getJson("/api/kickoff/meetings/{$meeting->id}/link")->assertOk()
            ->assertJsonPath('link', self::ROOM);
    }

    public function test_an_instant_start_link_is_still_held_by_the_host_alone(): void
    {
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser, ['meeting_link' => 'https://meet.google.com/new']);
        $attendee = $this->staff();
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'user_id' => $attendee->id, 'name' => $attendee->name,
            'email' => $attendee->email, 'side' => 'internal',
        ]);

        Sanctum::actingAs($attendee);
        $res = $this->getJson("/api/kickoff/meetings/{$meeting->id}")->assertOk();

        $res->assertJsonPath('meeting_link', null)->assertJsonPath('link_pending', true);
        $this->assertStringNotContainsString('meet.google.com/new', $res->getContent());
    }

    public function test_marking_attendance_still_works_and_still_records_the_evidence(): void
    {
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser);
        $attendee = $this->staff();

        Sanctum::actingAs($attendee);
        $this->postJson("/api/kickoff/meetings/{$meeting->id}/attendance")->assertOk()
            ->assertJsonPath('attendance_marked', true);

        $row = $meeting->fresh()->attendees()->where('user_id', $attendee->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('link', $row->attendance_source);
    }

    /* ── parity: Purchase does all of the above ──────────────────────── */

    public function test_the_purchase_register_has_the_same_authority_author_and_times(): void
    {
        $organiser = $this->staff();
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => 'bolt-'.Str::random(5).'@vendor.local',
        ]);
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff — Bolt', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(), 'meeting_platform' => 'google_meet',
            'meeting_link' => self::ROOM, 'created_by' => $organiser->id,
        ]);
        $row = $meeting->participants()->create([
            'tenant_id' => self::TENANT, 'name' => 'Asha', 'email' => 'asha@bolt.local', 'side' => 'external',
        ]);

        Sanctum::actingAs($this->staff());
        $this->patchJson("/api/purchase/kickoff/{$meeting->id}/attendance", [
            'rows' => [['id' => $row->id, 'attendance_status' => 'Absent']],
        ])->assertStatus(403);

        Sanctum::actingAs($organiser);
        $this->patchJson("/api/purchase/kickoff/{$meeting->id}/attendance", [
            'rows' => [[
                'id' => $row->id, 'attendance_status' => 'Present',
                'in_at' => now()->setTime(9, 15)->toDateTimeString(),
                'out_at' => now()->setTime(10, 0)->toDateTimeString(),
            ]],
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame((int) $organiser->id, (int) $fresh->marked_by);
        $this->assertNotNull($fresh->marked_at);
        $this->assertSame(45, $fresh->attendance_minutes);
    }
}
