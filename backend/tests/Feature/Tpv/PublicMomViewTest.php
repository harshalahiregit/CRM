<?php

namespace Tests\Feature\Tpv;

use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingSubject;
use App\Models\Tenant;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /kickoff/ack/{token}/mom — reading the minutes from the e-mail, no login.
 *
 * The vendor's signatory has no CRM account, so acknowledging used to mean
 * signing a document they could not open. This serves the ONE generated PDF the
 * meeting already owns; there is deliberately no second copy, so a MOM
 * regenerated after an attendance change is what the same link returns.
 *
 * Reading is separated from signing on purpose: acknowledge() burns its token,
 * this one never does, or a vendor could not reopen the document while deciding.
 */
class PublicMomViewTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const DISK   = 'kickoff_docs';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function vendor(string $name, int $tenant = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    /** A published meeting with a stored MOM and one pivot row per vendor. */
    private function meeting(array $vendors, string $body = 'PDF-ONE'): KickoffMeeting
    {
        $path = 'tenant-1/meeting-x/mom-'.Str::random(8).'.pdf';
        Storage::disk(self::DISK)->put($path, $body);

        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendors[0]->id, 'title' => 'Kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay(), 'completed_at' => now(), 'mom_path' => $path,
            'ack_token' => Str::random(48),
            'acknowledgement_deadline' => now()->addHours(48),
        ]);

        foreach ($vendors as $i => $v) {
            KickoffMeetingSubject::create([
                'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $meeting->id,
                'subject_type' => Vendor::class, 'subject_id' => $v->id,
                'is_primary' => $i === 0, 'ack_token' => Str::random(48),
            ]);
        }

        return $meeting;
    }

    public function test_every_vendor_reads_the_same_pdf_without_logging_in(): void
    {
        $meeting = $this->meeting([$this->vendor('PrimaryCo'), $this->vendor('SecondCo'), $this->vendor('ThirdCo')]);

        $bodies = [];

        foreach ($meeting->subjects as $s) {
            $res = $this->get("/api/kickoff/ack/{$s->ack_token}/mom");

            $res->assertOk();
            $res->assertHeader('content-type', 'application/pdf');
            $bodies[] = $res->streamedContent();
        }

        $this->assertCount(3, $bodies);
        $this->assertCount(1, array_unique($bodies), 'All vendors must receive the identical file.');
        $this->assertSame('PDF-ONE', $bodies[0]);
    }

    /** Links already in inboxes used the meeting token; they must keep working. */
    public function test_meeting_level_token_still_resolves(): void
    {
        $meeting = $this->meeting([$this->vendor('SoloCo')]);

        $this->get("/api/kickoff/ack/{$meeting->ack_token}/mom")->assertOk();
    }

    /** The URL must carry nothing but the token — no path, no meeting id. */
    public function test_response_leaks_no_identifiers(): void
    {
        $meeting = $this->meeting([$this->vendor('QuietCo')]);
        $token   = $meeting->subjects->first()->ack_token;

        $res = $this->get("/api/kickoff/ack/{$token}/mom");

        $disposition = $res->headers->get('content-disposition');
        $this->assertStringContainsString('Kickoff-Minutes.pdf', $disposition);
        $this->assertStringNotContainsString((string) $meeting->id, $disposition);
        $this->assertStringNotContainsString('tenant-1', $disposition);
    }

    public function test_unknown_and_tampered_tokens_fail_safely(): void
    {
        $meeting = $this->meeting([$this->vendor('RealCo')]);
        $token   = $meeting->subjects->first()->ack_token;

        $this->get('/api/kickoff/ack/'.Str::random(48).'/mom')->assertNotFound();
        // One character changed — must not resolve. The replacement is chosen
        // against the original character rather than hard-coded: tokens are
        // alphanumeric, so a fixed 'X' silently rebuilt the REAL token on the
        // ~1-in-62 of runs where the token already ended in one, and the test
        // failed claiming a tampered token had resolved.
        $this->get('/api/kickoff/ack/'.substr($token, 0, 47).($token[47] === 'X' ? 'Y' : 'X').'/mom')
            ->assertNotFound();
    }

    /**
     * The regression that live testing caught: acknowledging sets acknowledged_at,
     * which makes $meeting->acknowledgement_expired false forever. Guarding on that
     * accessor left an acknowledged meeting's link readable past its deadline.
     */
    public function test_expired_link_fails_even_after_acknowledgement(): void
    {
        $meeting = $this->meeting([$this->vendor('LateCo')]);
        $token   = $meeting->subjects->first()->ack_token;

        $meeting->update([
            'acknowledged_at'          => now(),
            'acknowledgement_deadline' => now()->subHour(),
        ]);

        $this->assertFalse($meeting->fresh()->acknowledgement_expired, 'Precondition: the accessor reads false.');

        $this->get("/api/kickoff/ack/{$token}/mom")->assertStatus(410);
    }

    /** Reading is repeatable and survives signing — only acknowledge() burns. */
    public function test_read_token_is_not_single_use(): void
    {
        $meeting = $this->meeting([$this->vendor('RepeatCo')]);
        $token   = $meeting->subjects->first()->ack_token;

        $this->get("/api/kickoff/ack/{$token}/mom")->assertOk();
        $this->get("/api/kickoff/ack/{$token}/mom")->assertOk();

        $meeting->update(['acknowledged_at' => now(), 'ack_token' => null]);

        $this->get("/api/kickoff/ack/{$token}/mom")->assertOk();
    }

    /** Always the current file — never a copy taken when the mail was sent. */
    public function test_link_serves_the_regenerated_pdf(): void
    {
        $meeting = $this->meeting([$this->vendor('FreshCo')], 'PDF-OLD');
        $token   = $meeting->subjects->first()->ack_token;

        $this->assertSame('PDF-OLD', $this->get("/api/kickoff/ack/{$token}/mom")->streamedContent());

        // What generateMom() does: new path, old file dropped, column repointed.
        Storage::disk(self::DISK)->delete($meeting->mom_path);
        $new = 'tenant-1/meeting-x/mom-'.Str::random(8).'.pdf';
        Storage::disk(self::DISK)->put($new, 'PDF-NEW');
        $meeting->update(['mom_path' => $new]);

        $this->assertSame('PDF-NEW', $this->get("/api/kickoff/ack/{$token}/mom")->streamedContent());
    }

    /** A missing document is a 404, not a 500 from the storage layer. */
    public function test_missing_document_is_handled(): void
    {
        $meeting = $this->meeting([$this->vendor('GoneCo')]);
        $token   = $meeting->subjects->first()->ack_token;

        Storage::disk(self::DISK)->delete($meeting->mom_path);

        $this->get("/api/kickoff/ack/{$token}/mom")->assertNotFound();
    }

    /** A pivot row written across tenants must not hand over the document. */
    public function test_cross_tenant_subject_row_is_rejected(): void
    {
        (new Tenant())->forceFill([
            'id' => 999, 'name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'status' => 'active',
        ])->save();

        $meeting = $this->meeting([$this->vendor('HostCo')]);

        $foreignToken = Str::random(48);
        KickoffMeetingSubject::create([
            'tenant_id' => 999, 'kickoff_meeting_id' => $meeting->id,
            'subject_type' => Vendor::class, 'subject_id' => $this->vendor('ForeignCo', 999)->id,
            'is_primary' => false, 'ack_token' => $foreignToken,
        ]);

        $this->get("/api/kickoff/ack/{$foreignToken}/mom")->assertNotFound();
    }
}
