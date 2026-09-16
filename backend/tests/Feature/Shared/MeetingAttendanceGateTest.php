<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The join link is earned, not given.
 *
 * The link used to travel in the invitation e-mail, in the calendar attachment
 * and in every payload the portal read, so the CRM was something people walked
 * past on their way to the call. Nobody opened the agenda, nobody was recorded
 * as attending, and the register was rebuilt afterwards from memory.
 *
 * These tests hold the gate shut from the outside. The important ones are not
 * the happy path — they are the ones that read the raw JSON and assert the link
 * is not in it, because a link that reaches the browser is not gated by the
 * page choosing not to draw it.
 */
class MeetingAttendanceGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const LINK = 'https://meet.google.com/abc-defg-hij';

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

    /** A vendor with a portal login, as the portal middleware expects. */
    private function vendorWithLogin(string $name = 'AlphaCo'): array
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => strtolower($name).'-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    private function meeting(Vendor $vendor, ?User $organiser = null, array $overrides = []): KickoffMeeting
    {
        return KickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT,
            'created_by' => $organiser?->id,
            'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff — AlphaCo',
            'status' => 'Scheduled',
            'mode' => 'online',
            'scheduled_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'meeting_platform' => 'google_meet',
            'meeting_link' => self::LINK,
        ], $overrides));
    }

    /* ── the vendor portal ───────────────────────────────────────────── */

    public function test_the_link_is_not_in_the_vendors_meeting_list_before_they_mark_attendance(): void
    {
        [$user, $vendor] = $this->vendorWithLogin();
        $this->meeting($vendor);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/portal/meetings')->assertOk();

        $res->assertJsonPath('data.0.meeting_link', null)
            ->assertJsonPath('data.0.attendance_marked', false)
            ->assertJsonPath('data.0.can_mark_attendance', true)
            // The difference between "no online meeting" and "one you have not
            // unlocked" — without this the screen cannot tell them apart.
            ->assertJsonPath('data.0.has_meeting_link', true);

        // The whole response, not one field: a link anywhere in this body is a
        // link the browser has, whatever the page draws.
        $this->assertStringNotContainsString(self::LINK, $res->getContent());
    }

    public function test_marking_attendance_hands_over_the_link_and_records_the_vendor(): void
    {
        [$user, $vendor] = $this->vendorWithLogin();
        $meeting = $this->meeting($vendor);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/meetings/{$meeting->id}/attendance")->assertOk()
            ->assertJsonPath('meeting_link', self::LINK)
            ->assertJsonPath('attendance_marked', true);

        // A meeting scheduled FOR a vendor usually has no roster row for them.
        // Marking attendance has to produce one, or there is nowhere for the
        // record to land and the vendor is locked out of their own meeting.
        $row = $meeting->fresh()->attendees()->first();
        $this->assertNotNull($row, 'the vendor was seated on the roster');
        $this->assertTrue((bool) $row->attended);
        $this->assertSame('link', $row->attendance_source);
        $this->assertNotNull($row->joined_at);
    }

    public function test_the_link_stays_visible_after_marking(): void
    {
        [$user, $vendor] = $this->vendorWithLogin();
        $meeting = $this->meeting($vendor);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/meetings/{$meeting->id}/attendance")->assertOk();

        // Reloading the page must not ask them to mark attendance again.
        $this->getJson('/api/portal/meetings')->assertOk()
            ->assertJsonPath('data.0.meeting_link', self::LINK)
            ->assertJsonPath('data.0.attendance_marked', true);
    }

    public function test_one_vendor_cannot_mark_attendance_on_another_vendors_meeting(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $meetingB = $this->meeting($vendorB);

        Sanctum::actingAs($userA);
        $this->postJson("/api/portal/meetings/{$meetingB->id}/attendance")->assertStatus(404);

        $this->assertSame(0, $meetingB->fresh()->attendees()->count());
    }

    public function test_a_cancelled_meeting_hands_out_nothing(): void
    {
        [$user, $vendor] = $this->vendorWithLogin();
        // Not "expired" — a cancelled meeting is in the future and used to keep
        // handing out a working link for a meeting nobody would attend.
        $meeting = $this->meeting($vendor, null, ['status' => 'Cancelled']);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/meetings/{$meeting->id}/attendance")->assertStatus(404);

        $res = $this->getJson('/api/portal/meetings')->assertOk();
        $this->assertStringNotContainsString(self::LINK, $res->getContent());
    }

    /* ── the staff console ───────────────────────────────────────────── */

    public function test_the_organiser_holds_the_link_without_marking(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        Sanctum::actingAs($organiser);

        // They chose the platform and generated the link. Withholding it from
        // them buys nothing and costs them the ability to host.
        $this->getJson("/api/kickoff/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('meeting_link', self::LINK);
    }

    public function test_a_staff_attendee_who_did_not_organise_is_gated(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $attendee = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        // On the roster, which is what "a staff attendee" means. This used to be
        // left out and the test still passed, because any staff member could
        // open any meeting -- so it was exercising the link gate through a
        // person who had no business seeing the meeting at all. Meetings is now
        // scoped per user, and the fixture says what the name always claimed.
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'user_id' => $attendee->id,
            'name' => $attendee->name, 'email' => $attendee->email, 'side' => 'internal',
        ]);

        Sanctum::actingAs($attendee);
        $res = $this->getJson("/api/kickoff/meetings/{$meeting->id}")->assertOk();

        $res->assertJsonPath('meeting_link', null)
            ->assertJsonPath('can_mark_attendance', true);
        $this->assertStringNotContainsString(self::LINK, $res->getContent());

        $this->postJson("/api/kickoff/meetings/{$meeting->id}/attendance")->assertOk()
            ->assertJsonPath('meeting_link', self::LINK)
            ->assertJsonPath('attendance_marked', true);
    }

    public function test_the_link_endpoint_is_gated_too(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $attendee = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        // The other way into the link. Leaving it open would have made gating
        // the meeting payload decorative.
        Sanctum::actingAs($attendee);
        $res = $this->getJson("/api/kickoff/meetings/{$meeting->id}/link")->assertOk();

        $res->assertJsonPath('link', null);
        $this->assertStringNotContainsString(self::LINK, $res->getContent());
    }

    public function test_an_admin_is_not_gated(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $meeting = $this->meeting($vendor, $this->staff());

        // An admin can open the meeting for editing and read the link out of the
        // form, so gating them here would be theatre.
        Sanctum::actingAs($this->staff('admin'));
        $this->getJson("/api/kickoff/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('meeting_link', self::LINK);
    }

    /* ── the invitation, and the calendar file attached to it ────────── */

    /**
     * Capture what would have been sent, without a mail server.
     *
     * @return array<int, array{to:string, html:string, text:?string, attachments:array}>
     */
    private function captureMail(): \ArrayObject
    {
        $sent = new \ArrayObject();

        $this->instance(\App\Services\Mail\TenantMailer::class, new class($sent) extends \App\Services\Mail\TenantMailer
        {
            public function __construct(private \ArrayObject $sent)
            {
            }

            // Mirrors TenantMailer::sendRawHtml exactly — PHP refuses a subclass
            // whose signature has drifted, so an argument added there must be
            // added here or the whole suite dies at parse time.
            public function sendRawHtml(?int $tenantId, string|array $to, string $subject, string $html, ?string $text = null, array $attachments = [], ?string $replyTo = null): void
            {
                $this->sent[] = ['to' => is_array($to) ? implode(',', $to) : $to, 'subject' => $subject,
                    'html' => $html, 'text' => (string) $text, 'attachments' => $attachments,
                    'reply_to' => $replyTo];
            }
        });

        return $sent;
    }

    public function test_the_invitation_carries_no_join_link_at_all(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        app(\App\Services\Shared\MeetingInviteService::class)
            ->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $this->assertNotEmpty($sent, 'the invitation was sent');

        foreach ($sent as $mail) {
            // The e-mail was the widest leak of the three: it survives in every
            // inbox it reached, long after the meeting and past anything we
            // could revoke.
            $this->assertStringNotContainsString(self::LINK, $mail['html'], 'HTML part carries no join link');
            $this->assertStringNotContainsString(self::LINK, $mail['text'], 'text part carries no join link');

            // And the calendar attachment, which is the leak people forget: a
            // LOCATION holding a Meet URL is one click away in every diary that
            // ever synced the event.
            foreach ($mail['attachments'] as $attachment) {
                $this->assertStringNotContainsString(self::LINK, $attachment['data'], 'the .ics carries no join link');
            }
        }
    }

    public function test_the_invitation_sends_people_to_the_crm_instead(): void
    {
        [, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        app(\App\Services\Shared\MeetingInviteService::class)
            ->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $organiser);

        $bodies = implode("
", array_map(fn ($m) => $m['html'].$m['text'], iterator_to_array($sent)));

        // Removing the link is only half of it. An invitation with no way
        // forward is worse than one that leaked — people need somewhere to go.
        $this->assertStringContainsString('mark your attendance', strtolower($bodies));

        // The vendor's own invitation goes to the portal. Every invitation used
        // to point at the staff console, so a vendor following it arrived at a
        // login screen they have no account for.
        $vendorMail = collect(iterator_to_array($sent))->firstWhere('to', $vendor->email);
        $this->assertNotNull($vendorMail, 'the vendor the meeting is about was invited');
        $this->assertStringContainsString('/vendor-portal/governance', $vendorMail['html']);
    }

    /* ── the identity match the gate rests on ────────────────────────── */

    public function test_a_vendor_does_not_inherit_a_staff_roster_row_by_id(): void
    {
        // Push the two id spaces apart first. Created in the natural order the
        // vendor is users.id 1 AND vendors.id 1, so the collision this test is
        // about cannot be built — and the test would pass while proving nothing.
        $decoy = $this->staff();
        $this->staff();
        [$user, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff();
        $meeting = $this->meeting($vendor, $organiser);

        $this->assertNotSame((int) $vendor->id, (int) $vendor->user_id,
            'the ids must genuinely differ, or this test proves nothing');
        $this->assertSame((int) $decoy->id, (int) $vendor->id,
            'and the decoy must be the staff account whose users.id equals the vendor id');

        // A roster row for a STAFF account whose users.id happens to equal the
        // vendor's id in its own table. Matching user_id against any actor's id
        // credited the vendor with this person's attendance — harmless while it
        // only stamped a remark, not harmless now it decides who gets the link.
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT,
            'user_id' => $decoy->id,
            'name' => 'Someone Else',
            'email' => 'someone-else@sangoe.local',
            'attended' => true,
        ]);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/portal/meetings')->assertOk();

        $res->assertJsonPath('data.0.meeting_link', null)
            ->assertJsonPath('data.0.attendance_marked', false);
        $this->assertStringNotContainsString(self::LINK, $res->getContent());
    }
}
