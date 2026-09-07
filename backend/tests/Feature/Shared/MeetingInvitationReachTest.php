<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\MeetingDistribution;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Notifications\NotificationService;
use App\Services\Purchase\PurchaseKickoffService;
use App\Services\Shared\MeetingInviteService;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who an invitation actually reaches.
 *
 * The report was "no one got the mail", and the ledger bore it out: meetings
 * with invitations recorded as sent, to nobody. Both engines built their
 * recipient list from the typed participant roster and nothing else, so a
 * meeting scheduled for a vendor — with no separate roster row hand-added for
 * that vendor — invited literally no one, reported success, and left the vendor
 * to find out when the meeting did not happen. The organiser got no copy of
 * their own invitation either.
 *
 * The two people certainly involved are the vendor the meeting is about and the
 * person who called it, and neither had to be on the roster.
 */
class MeetingInvitationReachTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->organiser = User::factory()->create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair',
            'email' => 'priya@sangoe.test', 'role' => 'admin',
        ]);
    }

    /* ── the shared / TPV engine ─────────────────────────────────────── */

    private function sharedMeeting(?string $vendorEmail): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Contractors',
            'email' => $vendorEmail, 'status' => VendorStatus::ACTIVE,
        ]);

        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $this->organiser->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => KickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);
    }

    /** The reported failure: an empty roster meant an invitation to nobody. */
    public function test_a_meeting_with_an_empty_roster_still_reaches_the_vendor(): void
    {
        $meeting = $this->sharedMeeting('ops@acme.test');

        $this->assertCount(0, $meeting->attendees, 'nobody was hand-added — the usual case');

        $counts = app(MeetingInviteService::class)->sendInvitations($meeting, $this->organiser);

        $this->assertGreaterThan(0, $counts['sent'],
            'an invitation that e-mails nobody and reports success is how this went unnoticed');

        $to = MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_INVITE)
            ->pluck('email')->map(fn ($e) => strtolower((string) $e))->all();

        $this->assertContains('ops@acme.test', $to, 'the vendor the meeting is about');
        $this->assertContains('priya@sangoe.test', $to, 'and the person who called it');
    }

    /** Somebody explicitly listed keeps their own name and party. */
    public function test_a_rostered_person_is_not_duplicated_by_the_fallback(): void
    {
        $meeting = $this->sharedMeeting('ops@acme.test');
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose',
            'email' => 'ops@acme.test', 'role' => 'Vendor rep', 'side' => 'external',
        ]);

        app(MeetingInviteService::class)->sendInvitations($meeting, $this->organiser);

        $rows = MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_INVITE)
            ->where('email', 'ops@acme.test')->get();

        $this->assertCount(1, $rows, 'one address, one invitation');
        $this->assertSame('Rita Bose', $rows->first()->name,
            'the roster row wins — it names a person, the vendor record names a company');
    }

    /** A vendor with no address on file is simply skipped, not invented. */
    public function test_a_vendor_without_an_address_adds_no_recipient(): void
    {
        $meeting = $this->sharedMeeting(null);

        app(MeetingInviteService::class)->sendInvitations($meeting, $this->organiser);

        $to = MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_INVITE)->pluck('email')->filter()->all();

        $this->assertSame(['priya@sangoe.test'], array_values(array_map('strtolower', $to)),
            'only the organiser is reachable, and nothing is fabricated for the vendor');
    }

    /* ── Purchase gets the same fix ──────────────────────────────────── */

    public function test_purchase_publishes_to_the_vendor_and_the_organiser(): void
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sales@bolt.test', 'status' => 'Active', 'portal_status' => 'active',
        ]);

        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $this->organiser->id,
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::DRAFT, 'mode' => 'online',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);

        /*
         * Asserted on who the service decides to write to, not on the transport.
         *
         * This engine sends through TenantMailer, which builds each message with
         * a closure rather than a Mailable so it can apply the tenant's own SMTP
         * settings — Mail::fake() records nothing useful from that. The
         * recipient list is the thing that was wrong and the thing that changed,
         * so that is what is checked.
         */
        $sent = new \ArrayObject();
        $this->swap(NotificationService::class, new class($sent) extends NotificationService
        {
            public function __construct(private \ArrayObject $seen)
            {
                // Deliberately does not call the parent constructor: nothing
                // here sends, so its dependencies are not needed.
            }

            public function email(?string $to, string $subject, string $body, array $context = [], ?int $tenantId = null): string
            {
                $this->seen[] = strtolower((string) $to);

                return 'sent';
            }
        });

        // Publishing is what sends the invitation on this engine.
        app(PurchaseKickoffService::class)->transition(
            $meeting, PurchaseKickoffStatus::SCHEDULED, [], $this->organiser,
        );

        $to = $sent->getArrayCopy();

        $this->assertContains('sales@bolt.test', $to, 'the vendor the meeting is about');
        $this->assertContains('priya@sangoe.test', $to, 'and the person who called it');
    }
}
