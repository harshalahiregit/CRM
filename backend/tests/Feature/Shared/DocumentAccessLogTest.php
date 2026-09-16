<?php

namespace Tests\Feature\Shared;

use App\Models\DocumentAccessLog;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\IpLocation;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who opened which document, from what, and where.
 *
 * Files leave this system from more than fifty endpoints and exactly one of
 * them recorded anything — so "who downloaded that certificate?" had no answer,
 * which is the kind of question only ever asked after the fact.
 *
 * The recording is middleware rather than fifty edits, and it keys off what
 * actually went over the wire. That is the property worth protecting: an
 * endpoint added next month is covered without anybody remembering to cover it.
 */
class DocumentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kickoff_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'role' => 'admin',
            'email' => 'a-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A meeting with a minutes PDF actually on disk. */
    private function meetingWithPdf(): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);

        $m = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id, 'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => KickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);

        Storage::disk('kickoff_docs')->put('mom/m.pdf', '%PDF-1.4 minutes');
        $m->forceFill(['mom_path' => 'mom/m.pdf'])->save();

        return $m->fresh();
    }

    /* ── it records, without the endpoint knowing ────────────────────────── */

    public function test_opening_a_pdf_is_recorded_with_the_device_and_address(): void
    {
        $m = $this->meetingWithPdf();
        Sanctum::actingAs($this->admin);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) AppleWebKit Safari')
            ->get("/api/kickoff/meetings/{$m->id}/mom")
            ->assertOk();

        $log = DocumentAccessLog::sole();

        $this->assertSame((int) $this->admin->id, (int) $log->user_id);
        $this->assertSame('Priya Nair', $log->actor_label);
        $this->assertSame('Mobile', $log->device, 'what they were using');
        $this->assertSame('Safari', $log->browser);
        $this->assertNotNull($log->ip, 'and where the request came from');
        $this->assertStringContainsString('meetings', (string) $log->path);
    }

    /**
     * A view and a download are different facts.
     *
     * The endpoint already says which through Content-Disposition, so nothing
     * has to be passed in and no endpoint can get it wrong.
     */
    public function test_a_view_and_a_download_are_told_apart(): void
    {
        $m = $this->meetingWithPdf();
        Sanctum::actingAs($this->admin);

        $this->get("/api/kickoff/meetings/{$m->id}/mom")->assertOk();
        $this->get("/api/kickoff/meetings/{$m->id}/mom?download=1")->assertOk();

        $actions = DocumentAccessLog::orderBy('id')->pluck('action')->all();

        $this->assertSame(['view', 'download'], $actions);
    }

    /** Every opening is its own row — a second one is a different fact. */
    public function test_each_opening_is_recorded_separately(): void
    {
        $m = $this->meetingWithPdf();
        Sanctum::actingAs($this->admin);

        $this->get("/api/kickoff/meetings/{$m->id}/mom")->assertOk();
        $this->get("/api/kickoff/meetings/{$m->id}/mom")->assertOk();

        $this->assertSame(2, DocumentAccessLog::count());
    }

    /** Ordinary JSON is not a document and must not fill the trail with noise. */
    public function test_a_normal_json_request_is_not_logged(): void
    {
        $m = $this->meetingWithPdf();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/kickoff/meetings/{$m->id}")->assertOk();
        $this->getJson('/api/kickoff/meetings')->assertOk();

        $this->assertSame(0, DocumentAccessLog::count());
    }

    /** A request that failed served no document, so there is nothing to record. */
    public function test_a_missing_document_is_not_recorded_as_opened(): void
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);
        $m = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id, 'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => KickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/kickoff/meetings/{$m->id}/mom")->assertNotFound();

        $this->assertSame(0, DocumentAccessLog::count());
    }

    /* ── location is a choice, not a default ─────────────────────────────── */

    /**
     * Nothing is sent anywhere unless an administrator asked for it.
     *
     * Turning an address into a place means handing that address to a third
     * party. The trail still carries the address, the device and the browser,
     * which is what most questions actually need.
     */
    public function test_no_location_lookup_happens_by_default(): void
    {
        $m = $this->meetingWithPdf();
        Sanctum::actingAs($this->admin);

        $this->get("/api/kickoff/meetings/{$m->id}/mom")->assertOk();

        $this->assertNull(DocumentAccessLog::sole()->location,
            'off by default — no third party is asked anything');
        $this->assertNull(IpLocation::for('8.8.8.8', self::TENANT));
    }

    /** A private address has no public location to look up, configured or not. */
    public function test_a_private_address_is_never_looked_up(): void
    {
        $this->assertNull(IpLocation::for('127.0.0.1', self::TENANT));
        $this->assertNull(IpLocation::for('192.168.1.20', self::TENANT));
    }

    /* ── it must never cost somebody their file ──────────────────────────── */

    /**
     * The audit line is secondary to the document.
     *
     * If the log cannot be written the file still goes out — a download that
     * fails because its own audit row failed would be a worse bug than the one
     * this fixes.
     */
    public function test_a_failing_log_does_not_break_the_download(): void
    {
        $m = $this->meetingWithPdf();

        // Nothing can be written into a table that is not there.
        \Illuminate\Support\Facades\Schema::drop('document_access_logs');

        Sanctum::actingAs($this->admin);
        $this->get("/api/kickoff/meetings/{$m->id}/mom")->assertOk();
    }
}
