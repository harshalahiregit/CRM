<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Shared\BusinessTime;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the TPV vendor actually sees under Meetings & MOM.
 *
 * The tab was empty for every vendor, always. `kickoffable_type` stores the
 * model class (App\Models\Vendor\Vendor); the portal filtered it against the
 * short key 'vendor', which matches no row that has ever been written. The same
 * comparison guarded the ownership checks, so even a direct link to the minutes
 * was refused. Pinned here because nothing about it looked broken — the
 * endpoint answered 200 with an empty list, which reads as "no meetings yet".
 */
class VendorPortalMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);

        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor User', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $this->vendor->forceFill(['user_id' => $user->id])->save();

        Sanctum::actingAs($user);
    }

    private function clock(int $minutesFromNow): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutesFromNow)->format('Y-m-d H:i:s');
    }

    private function meeting(string $title, int $startsIn, string $status = KickoffStatus::SCHEDULED): KickoffMeeting
    {
        return KickoffMeeting::create([
            'tenant_id'        => self::TENANT,
            'kickoffable_type' => Vendor::class,
            'kickoffable_id'   => $this->vendor->id,
            'title'            => $title,
            'meeting_type'     => 'kickoff',
            'status'           => $status,
            'mode'             => 'online',
            'meeting_link'     => 'https://meet.example.test/room',
            'scheduled_at'     => $this->clock($startsIn),
            'end_at'           => $this->clock($startsIn + 60),
            'duration_minutes' => 60,
        ]);
    }

    public function test_the_vendors_meetings_actually_reach_the_portal(): void
    {
        $this->meeting('Kickoff', 1440);

        $body = $this->getJson('/api/portal/meetings')->assertOk()->json('data');

        $this->assertCount(1, $body, 'the vendor should see their own meeting');
        $this->assertSame('Kickoff', $body[0]['title']);
    }

    public function test_each_meeting_carries_its_end_and_timing(): void
    {
        $this->meeting('Kickoff', 1440);

        $row = $this->getJson('/api/portal/meetings')->assertOk()->json('data.0');

        // Without these the portal cannot say when a meeting finishes, let
        // alone whether it already has.
        foreach (['ends_at', 'duration_minutes', 'timing_state', 'timing_label', 'is_expired', 'is_live'] as $key) {
            $this->assertArrayHasKey($key, $row, "the portal needs {$key}");
        }
        $this->assertSame('upcoming', $row['timing_state']);
        $this->assertFalse($row['is_expired']);
    }

    public function test_a_meeting_whose_time_has_passed_reads_as_expired(): void
    {
        $this->meeting('Missed', -300);

        $row = $this->getJson('/api/portal/meetings')->assertOk()->json('data.0');

        $this->assertSame('expired', $row['timing_state']);
        $this->assertTrue($row['is_expired']);
    }

    public function test_an_expired_meeting_no_longer_hands_out_its_join_link(): void
    {
        $this->meeting('Missed', -300);

        $row = $this->getJson('/api/portal/meetings')->assertOk()->json('data.0');

        $this->assertNull($row['meeting_link'], 'a link to a finished meeting looks like it should still work');
    }

    /**
     * A running meeting is still joinable — the link used to be withheld the
     * moment it started, which is exactly when it is needed, and that must not
     * come back.
     *
     * The link was briefly EARNED rather than given — withheld until the vendor
     * marked attendance. That toll has gone: MeetingLinkAnnouncer e-mails the
     * real room to every participant the moment the organiser pastes it, so
     * withholding the same URL here made the portal the slow route to a link
     * already in their inbox. Marking attendance is still offered, and is now
     * asked for on its own terms. See MeetingAttendanceGate.
     */
    public function test_a_meeting_in_progress_is_joinable_and_carries_its_room(): void
    {
        $meeting = $this->meeting('Running', -10);

        $res = $this->getJson('/api/portal/meetings')->assertOk();
        $row = $res->json('data.0');

        $this->assertSame('live', $row['timing_state']);
        $this->assertTrue($row['is_live']);
        $this->assertTrue($row['has_meeting_link'], 'the portal still knows this is an online meeting');
        $this->assertSame('https://meet.example.test/room', $row['meeting_link'],
            'a real room is handed over — it was e-mailed to them anyway');
        $this->assertFalse($row['attendance_marked'], 'and it cost them nothing to get it');

        // Marking attendance still records what it always recorded.
        $this->postJson("/api/portal/meetings/{$meeting->id}/attendance")->assertOk()
            ->assertJsonPath('attendance_marked', true);

        $this->assertNotNull(
            $this->getJson('/api/portal/meetings')->assertOk()->json('data.0.meeting_link')
        );
    }

    public function test_drafts_stay_invisible_to_the_vendor(): void
    {
        $this->meeting('Not published', 1440, KickoffStatus::DRAFT);

        $this->assertCount(0, $this->getJson('/api/portal/meetings')->assertOk()->json('data'));
    }

    public function test_another_vendors_meeting_is_never_listed(): void
    {
        $other = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival', 'status' => VendorStatus::ACTIVE,
        ]);
        KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $other->id,
            'title' => 'Theirs', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(60), 'end_at' => $this->clock(120), 'duration_minutes' => 60,
        ]);

        // The broken comparison matched nothing, so this passed for the wrong
        // reason before: it now has to be excluded on purpose.
        $this->assertCount(0, $this->getJson('/api/portal/meetings')->assertOk()->json('data'));
    }
}
