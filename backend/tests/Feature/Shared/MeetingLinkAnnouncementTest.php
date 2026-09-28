<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\MeetingDistribution;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorContact;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Send everyone the actual link, the moment I have it."
 *
 * The invitation deliberately carries no join link — it goes out days early,
 * when the only link that exists is meet.google.com/new, which opens a
 * different empty room for every person who clicks it. This is the send that
 * happens afterwards, and these are the rules it has to keep:
 *
 *  - every participant gets it, including the ones whose roster row has no
 *    e-mail on it but whose address the database holds somewhere else;
 *  - a person with no address ANYWHERE is reported BY NAME, never skipped;
 *  - an instant-start URL is never mailed to anybody;
 *  - it goes through the tenant's own SMTP settings, and a tenant with none is
 *    told so rather than having its mail written to a log and called sent.
 */
class MeetingLinkAnnouncementTest extends TestCase
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

    /** The tenant's own SMTP, which is the only transport this app sends through. */
    private function smtp(bool $enabled = true): TenantMailSetting
    {
        return TenantMailSetting::create([
            'tenant_id' => self::TENANT, 'host' => 'smtp.sangoe.local', 'port' => 587,
            'username' => 'mailer', 'password' => 'secret', 'encryption' => 'tls',
            'from_name' => 'Sangoe', 'from_email' => 'no-reply@sangoe.local',
            'enabled' => $enabled, 'verify_peer' => true,
        ]);
    }

    private function staff(string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya '.Str::random(4), 'role' => $role,
            'email' => 'staff-'.Str::random(6).'@sangoe.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name = 'AlphaCo'): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function meeting(User $organiser, Vendor $vendor, array $overrides = []): KickoffMeeting
    {
        return KickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT,
            'created_by' => $organiser->id,
            'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff — AlphaCo',
            'status' => 'Scheduled',
            'mode' => 'online',
            'scheduled_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'meeting_platform' => 'google_meet',
            'meeting_link' => 'https://meet.google.com/new',
        ], $overrides));
    }

    /**
     * Capture what would have been sent, without a mail server — and prove it
     * went through TenantMailer, because a Mailable posted straight at the
     * Mail facade would never reach this double.
     *
     * @return \ArrayObject<int, array{to:string, subject:string, html:string, text:string, attachments:array}>
     */
    private function captureMail(): \ArrayObject
    {
        $sent = new \ArrayObject();

        $this->instance(\App\Services\Mail\TenantMailer::class, new class($sent) extends \App\Services\Mail\TenantMailer
        {
            public function __construct(private \ArrayObject $sent) {}

            public function sendRawHtml(?int $tenantId, string|array $to, string $subject, string $html, ?string $text = null, array $attachments = [], ?string $replyTo = null): void
            {
                $this->sent[] = ['to' => is_array($to) ? implode(',', $to) : $to, 'subject' => $subject,
                    'html' => $html, 'text' => (string) $text, 'attachments' => $attachments];
            }
        });

        return $sent;
    }

    private function addresses(\ArrayObject $sent): array
    {
        return array_map(fn ($m) => strtolower($m['to']), iterator_to_array($sent));
    }

    /* ── the send itself ─────────────────────────────────────────────── */

    public function test_pasting_the_room_mails_the_real_link_to_every_participant(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');
        $vendor = $this->vendor();
        $meeting = $this->meeting($organiser, $vendor);

        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        Sanctum::actingAs($organiser);

        $res = $this->putJson("/api/kickoff/meetings/{$meeting->id}/link", ['link' => self::ROOM])
            ->assertOk()
            ->assertJsonPath('notified.smtp_ready', true);

        // The response carries the plan, so the screen can say "link sent to N".
        $this->assertSame(3, $res->json('notified.recipients'), 'attendee, vendor and organiser');
        $this->assertSame(3, $res->json('notified.reachable'));

        // The send itself runs on terminate; the test kernel does not flush a
        // response, so it is invoked directly against the same service.
        app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $to = $this->addresses($sent);
        $this->assertContains('ravi@alphaco.local', $to);
        $this->assertContains(strtolower($vendor->email), $to);
        $this->assertContains(strtolower($organiser->email), $to);

        foreach ($sent as $mail) {
            $this->assertStringContainsString(self::ROOM, $mail['html'], 'the actual link is in the HTML');
            $this->assertStringContainsString(self::ROOM, $mail['text'], 'and in the text part');
            $this->assertNotEmpty($mail['attachments'], 'an .ics rides along');
            $this->assertStringContainsString('BEGIN:VCALENDAR', $mail['attachments'][0]['data']);
            $this->assertStringContainsString(self::ROOM, $mail['attachments'][0]['data'],
                'the calendar entry carries the room, so the diary shows a Join button');
        }

        // One ledger row per recipient, so "does Ravi have the current room?"
        // is answerable per person rather than per meeting.
        $this->assertSame(3, MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_LINK)->count());
    }

    public function test_an_address_is_found_in_the_database_when_the_roster_row_has_none(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');
        $vendor = $this->vendor();
        $meeting = $this->meeting($organiser, $vendor, ['meeting_link' => self::ROOM]);

        // Typed as a name, with a login behind it. This person used to be
        // skipped, and the send still reported success.
        $colleague = $this->staff();
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'user_id' => $colleague->id, 'name' => $colleague->name, 'side' => 'internal',
        ]);

        // Picked from the vendor's contact master, which holds the address the
        // roster row does not.
        $contact = VendorContact::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'name' => 'Sunita', 'email' => 'sunita@alphaco.local',
        ]);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'vendor_contact_id' => $contact->id, 'name' => 'Sunita', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        $result = app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $to = $this->addresses($sent);
        $this->assertContains(strtolower($colleague->email), $to, 'resolved through the login account');
        $this->assertContains('sunita@alphaco.local', $to, 'resolved through the vendor contact record');
        $this->assertSame([], $result['unreachable']);
    }

    public function test_a_participant_with_no_address_anywhere_is_reported_by_name_not_skipped(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');
        $vendor = $this->vendor();
        $meeting = $this->meeting($organiser, $vendor, ['meeting_link' => self::ROOM]);

        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Mohan Lal', 'side' => 'external',
        ]);

        $this->captureMail();
        $result = app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $names = array_column($result['unreachable'], 'name');
        $this->assertContains('Mohan Lal', $names,
            'the admin is told WHO was not reached, so the record can be fixed');
        $this->assertNotEmpty($result['unreachable'][0]['reason']);

        // And the ledger says so too, rather than recording a delivery.
        $row = MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_LINK)->where('name', 'Mohan Lal')->first();
        $this->assertNotNull($row);
        $this->assertSame(MeetingDistribution::SKIPPED, $row->status);
    }

    public function test_an_instant_start_link_is_never_mailed_to_anybody(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');
        $vendor = $this->vendor();
        // Left on meet.google.com/new — not a room, and handing it out puts
        // every recipient in an empty meeting of their own.
        $meeting = $this->meeting($organiser, $vendor);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        $result = app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $this->assertCount(0, $sent, 'nothing was sent');
        $this->assertSame(0, $result['recipients']);

        // And the "send it again" button refuses for the same reason.
        Sanctum::actingAs($organiser);
        $this->postJson("/api/kickoff/meetings/{$meeting->id}/link/announce")->assertStatus(422);
    }

    public function test_the_send_goes_through_the_tenants_own_smtp_settings(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');
        $meeting = $this->meeting($organiser, $this->vendor(), ['meeting_link' => self::ROOM]);

        // The double replaces TenantMailer. Anything that reached it went
        // through the tenant path; a Mailable posted at the Mail facade, or a
        // read of config('mail.*'), would produce an empty capture here.
        $sent = $this->captureMail();
        app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $this->assertNotEmpty($sent, 'the mail went through TenantMailer, not the global mailer');
    }

    public function test_a_tenant_with_no_smtp_is_told_so_rather_than_reported_as_sent(): void
    {
        // No TenantMailSetting row at all — the state a fresh deployment is in.
        $organiser = $this->staff('admin');
        $vendor = $this->vendor();
        $meeting = $this->meeting($organiser, $vendor, ['meeting_link' => self::ROOM]);
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi', 'email' => 'ravi@alphaco.local', 'side' => 'external',
        ]);

        Sanctum::actingAs($organiser);
        $res = $this->postJson("/api/kickoff/meetings/{$meeting->id}/link/announce")->assertOk();

        $this->assertFalse($res->json('notified.smtp_ready'));
        $this->assertStringContainsString('Settings', (string) $res->json('notified.smtp_reason'));
        $this->assertSame(0, $res->json('notified.reachable'),
            'nobody is reachable when there is no transport, and the screen must not say otherwise');

        // Everyone is named, with the SMTP reason — not silently dropped.
        $reasons = array_column($res->json('notified.unreachable'), 'reason');
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('Settings', $reasons[0]);
    }

    public function test_switched_off_smtp_is_reported_the_same_way(): void
    {
        $this->smtp(enabled: false);
        $organiser = $this->staff('admin');
        $meeting = $this->meeting($organiser, $this->vendor(), ['meeting_link' => self::ROOM]);

        Sanctum::actingAs($organiser);
        $res = $this->postJson("/api/kickoff/meetings/{$meeting->id}/link/announce")->assertOk();

        $this->assertFalse($res->json('notified.smtp_ready'));
        $this->assertStringContainsString('switched off', strtolower((string) $res->json('notified.smtp_reason')));
    }

    public function test_a_non_host_cannot_set_or_send_the_link(): void
    {
        $this->smtp();
        $organiser = $this->staff();
        $meeting = $this->meeting($organiser, $this->vendor(), ['meeting_link' => self::ROOM]);

        Sanctum::actingAs($this->staff());
        $this->putJson("/api/kickoff/meetings/{$meeting->id}/link", ['link' => 'https://meet.google.com/zzz-zzzz-zzz'])
            ->assertStatus(403);
        $this->postJson("/api/kickoff/meetings/{$meeting->id}/link/announce")->assertStatus(403);
    }

    /* ── parity: the Purchase engine does all of the above ───────────── */

    public function test_the_purchase_engine_sends_the_room_the_same_way(): void
    {
        $this->smtp();
        $organiser = $this->staff('admin');

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => 'bolt-'.Str::random(5).'@vendor.local',
        ]);
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff — Bolt Supplies', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDays(2), 'meeting_platform' => 'google_meet',
            'meeting_link' => 'https://meet.google.com/new', 'created_by' => $organiser->id,
        ]);
        $meeting->participants()->create([
            'tenant_id' => self::TENANT, 'name' => 'Asha', 'email' => 'asha@bolt.local', 'side' => 'external',
        ]);

        $sent = $this->captureMail();
        Sanctum::actingAs($organiser);

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => self::ROOM])
            ->assertOk()
            ->assertJsonPath('notified.smtp_ready', true);

        app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting->fresh(), $organiser);

        $to = $this->addresses($sent);
        $this->assertContains('asha@bolt.local', $to);
        $this->assertContains(strtolower($vendor->email), $to);

        // The ledger is told which engine the id belongs to, so Purchase #7 and
        // shared #7 do not overwrite each other's history.
        $this->assertSame(MeetingDistribution::ENGINE_PURCHASE,
            MeetingDistribution::where('kind', MeetingDistribution::KIND_LINK)->first()->engine);
    }
}
