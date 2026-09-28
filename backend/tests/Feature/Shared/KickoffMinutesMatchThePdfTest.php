<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\KickoffPdfService;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The minutes on screen describe the same meeting as the minutes in the PDF.
 *
 * The onboarding wizard shows the kickoff MOM twice over: as a document you can
 * open, and as text you can read. Those came from two different resolvers — the
 * PDF from `findKickoffMeeting()`, which follows the onboarding's own
 * `kickoff_meeting_id` and insists on Completed with a document; the screen from
 * whichever meeting it happened to pick out of the vendor's meetings list.
 *
 * A vendor with more than one meeting therefore saw a populated PDF beside empty
 * sections and concluded the screen was broken. It was not broken; it was
 * describing a different meeting. Both now go through the one resolver, and this
 * is what stops them drifting apart again.
 */
class KickoffMinutesMatchThePdfTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $vendorUser;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kickoff_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Contractors',
            'status' => VendorStatus::ACTIVE, 'user_id' => $this->vendorUser->id,
        ]);
    }

    private function meeting(string $status, bool $withMom, string $title): KickoffMeeting
    {
        $m = KickoffMeeting::create([
            'tenant_id' => self::TENANT,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $this->vendor->id,
            'title' => $title, 'meeting_type' => 'kickoff', 'status' => $status,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
            'agenda' => "Site rules for {$title}",
        ]);

        if ($withMom) {
            Storage::disk('kickoff_docs')->put("mom/{$m->id}.pdf", '%PDF-1.4');
            $m->forceFill(['mom_path' => "mom/{$m->id}.pdf"])->save();
        }

        return $m->fresh();
    }

    private function onboarding(?KickoffMeeting $linked = null): TpvOnboarding
    {
        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id,
            'status' => 'In_Progress', 'current_step' => 1,
            'kickoff_meeting_id' => $linked?->id,
        ]);
    }

    /**
     * The case that produced the report: more than one meeting on the vendor.
     *
     * A cancelled meeting with a stray document sorts first in the list the
     * screen used to read, while the PDF followed the onboarding's own link.
     */
    public function test_the_screen_and_the_pdf_pick_the_same_meeting(): void
    {
        $decoy = $this->meeting(KickoffStatus::CANCELLED, true, 'Cancelled meeting');
        $real = $this->meeting(KickoffStatus::COMPLETED, true, 'The real kickoff');
        $ob = $this->onboarding($real);

        // What the PDF will render.
        $pdfMeeting = app(KickoffPdfService::class)->findKickoffMeeting($ob);
        $this->assertSame((int) $real->id, (int) $pdfMeeting->id);

        Sanctum::actingAs($this->vendorUser);
        $body = $this->getJson("/api/portal/onboarding/{$ob->id}/kickoff-data")->assertOk()->json();

        $this->assertSame((int) $real->id, (int) $body['meeting']['id'],
            'the text and the document must describe one meeting, not two');
        $this->assertNotSame((int) $decoy->id, (int) $body['meeting']['id']);
    }

    /**
     * A free-text agenda reaches the screen.
     *
     * The view read only the structured agenda ROWS, so a meeting whose agenda
     * was typed as a paragraph said "No agenda was recorded" while the PDF
     * printed that very paragraph.
     */
    public function test_a_free_text_agenda_reaches_the_screen(): void
    {
        $m = $this->meeting(KickoffStatus::COMPLETED, true, 'The real kickoff');
        $ob = $this->onboarding($m);

        Sanctum::actingAs($this->vendorUser);
        $body = $this->getJson("/api/portal/onboarding/{$ob->id}/kickoff-data")->assertOk()->json();

        $this->assertSame('Site rules for The real kickoff', $body['agenda_text']);
    }

    /** The participants the PDF lists are carried too — they were absent entirely. */
    public function test_participants_are_carried(): void
    {
        $m = $this->meeting(KickoffStatus::COMPLETED, true, 'The real kickoff');
        $m->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose',
            'designation' => 'Site Lead', 'side' => 'external', 'attended' => true,
        ]);
        $ob = $this->onboarding($m);

        Sanctum::actingAs($this->vendorUser);
        $body = $this->getJson("/api/portal/onboarding/{$ob->id}/kickoff-data")->assertOk()->json();

        $this->assertCount(1, $body['participants']);
        $this->assertSame('Rita Bose', $body['participants'][0]['name']);
        $this->assertTrue($body['participants'][0]['attended']);
    }

    /**
     * No meeting is a normal answer, not an error.
     *
     * The screen has to be able to say "not published yet" rather than spin, so
     * this must be a 200 with a null meeting.
     */
    public function test_no_meeting_answers_plainly(): void
    {
        $ob = $this->onboarding();

        Sanctum::actingAs($this->vendorUser);
        $this->getJson("/api/portal/onboarding/{$ob->id}/kickoff-data")
            ->assertOk()
            ->assertJsonPath('meeting', null);
    }

    /** And another vendor's onboarding is not readable. */
    public function test_one_vendor_cannot_read_anothers_minutes(): void
    {
        $m = $this->meeting(KickoffStatus::COMPLETED, true, 'The real kickoff');
        $ob = $this->onboarding($m);

        $other = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rival', 'role' => 'third_party_vendor',
            'email' => 'r-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'status' => VendorStatus::ACTIVE, 'user_id' => $other->id,
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/portal/onboarding/{$ob->id}/kickoff-data")->assertNotFound();
    }
}
