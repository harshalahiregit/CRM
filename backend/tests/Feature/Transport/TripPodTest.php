<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\PodReceived;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripDocument;
use App\Models\User;
use App\Services\Transport\TripDocumentService;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripDocumentStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * SNG-TRN-014 — "POD required before billable state unless approved exception."
 *
 * The acceptance criterion has two arms and both are tested, including the one
 * that cannot fire yet: a gate with no bypass would contradict the criterion the
 * moment waivers become reachable, so the waiver arm is written and proven now.
 *
 * STT-008 is the transition under test:
 *
 *   delivered -> pod_verified | guard "POD valid" | effect "Unlock billing"
 *
 * and CTR-012 is the constraint: "Immutable after verification". That one is
 * asserted at the MODEL level, not just the service, because a rule that only
 * lives in a service is the rule the next ::update() forgets.
 */
class TripPodTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripDocumentService $documents;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->documents = app(TripDocumentService::class);
        $this->actor     = $this->user('accounts');
    }

    private function user(string $internalRole): User
    {
        return User::create([
            'tenant_id' => self::TENANT_A, 'name' => ucfirst($internalRole), 'role' => 'staff',
            'internal_role' => $internalRole,
            'email' => $internalRole.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A, ?string $status = null): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(2), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill([
            'status' => $status ?? TripStatus::APPROVED, 'approved_freight' => '100000.00',
        ])->save();

        return $trip->fresh();
    }

    /** A real JPEG, so getMimeType() reading the bytes agrees with the name. */
    private function podFile(string $name = 'pod.jpg', int $kb = 40): UploadedFile
    {
        return UploadedFile::fake()->image($name)->size($kb);
    }

    private function file(TransportTrip $trip, ?UploadedFile $upload = null, array $data = []): TripDocument
    {
        return $this->documents->file(
            $trip, $upload ?? $this->podFile(), $data, (int) $trip->tenant_id, $this->actor
        );
    }

    /* ── Filing ───────────────────────────────────────────────────────── */

    public function test_a_pod_is_filed_against_its_trip_and_stored_privately(): void
    {
        $trip = $this->trip();
        $doc  = $this->file($trip);

        $this->assertSame($trip->id, $doc->trip_id);
        $this->assertSame(TransportDocumentType::POD, $doc->document_type);
        $this->assertSame(TripDocumentStatus::RECEIVED, $doc->status);
        $this->assertSame(64, strlen((string) $doc->file_hash), 'a SHA-256 is 64 hex characters');
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_the_default_type_is_pod_but_other_paperwork_is_accepted(): void
    {
        // DB-009 is the "LR/POD/EWB/attachments index", not a POD table.
        $trip = $this->trip();

        $lr = $this->file($trip, $this->podFile('lr.jpg'), ['document_type' => TransportDocumentType::LR]);

        $this->assertSame(TransportDocumentType::LR, $lr->document_type);
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->file($this->trip(), $this->podFile(), ['document_type' => 'invented']);
    }

    public function test_a_file_that_is_not_a_pdf_or_image_is_refused(): void
    {
        $trip = $this->trip();

        $this->expectException(BusinessException::class);
        $this->file($trip, UploadedFile::fake()->createWithContent('notes.txt', 'just some text'));
    }

    /**
     * What this suite can and cannot prove about MIME.
     *
     * The service checks `getMimeType()`, which on a REAL upload asks finfo to
     * read the file's leading bytes — so a shell script renamed to `.jpg` is
     * caught in production. It cannot be demonstrated here:
     * `UploadedFile::fake()->createWithContent('x.jpg', ...)` sets its mime type
     * from the EXTENSION, so the fake reports image/jpeg whatever the content,
     * and a test asserting otherwise would be asserting the fake's behaviour
     * rather than the guard's.
     *
     * Recorded rather than quietly dropped, so nobody later reads a green suite
     * as proof of a property it never tested. The disguised-file case needs a
     * real upload against a running server.
     */
    public function test_the_mime_check_reads_bytes_not_the_filename(): void
    {
        $disguised = UploadedFile::fake()->createWithContent('payload.jpg', "#!/bin/sh\nrm -rf /");

        $this->assertSame(
            'image/jpeg', $disguised->getMimeType(),
            'the fake guesses from the extension — see this test\'s docblock'
        );

        // What IS provable: the allow-list contains no executable type, so a
        // correctly-detected script can never satisfy it.
        foreach (TripDocumentService::ALLOWED_MIME as $mime) {
            $this->assertMatchesRegularExpression('#^(image/|application/pdf$)#', $mime);
        }
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->file($this->trip(), $this->podFile('huge.jpg', 11 * 1024));
    }

    public function test_a_trip_from_another_tenant_reads_as_not_found(): void
    {
        $foreign = $this->trip(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->documents->file($foreign, $this->podFile(), [], self::TENANT_A, $this->actor);
    }

    /* ── Duplicate uploads — the retry case ───────────────────────────── */

    public function test_the_same_file_uploaded_twice_is_absorbed(): void
    {
        $trip = $this->trip();

        $first  = $this->file($trip, UploadedFile::fake()->createWithContent('pod.pdf', '%PDF-1.4 signed'));
        $second = $this->file($trip, UploadedFile::fake()->createWithContent('pod.pdf', '%PDF-1.4 signed'));

        $this->assertSame($first->id, $second->id, 'a retried upload must return the original row');
        $this->assertSame(1, TripDocument::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_a_different_file_on_the_same_trip_is_accepted(): void
    {
        // Multi-drop trips produce one POD per stop. A unique key on
        // (trip, type) would make the honest case impossible.
        $trip = $this->trip();

        $this->file($trip, UploadedFile::fake()->createWithContent('stop1.pdf', '%PDF stop one'));
        $this->file($trip, UploadedFile::fake()->createWithContent('stop2.pdf', '%PDF stop two'));

        $this->assertSame(2, TripDocument::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /* ── EVT-009 ──────────────────────────────────────────────────────── */

    public function test_filing_a_pod_emits_pod_received_with_the_registry_payload(): void
    {
        Event::fake([PodReceived::class]);
        $trip = $this->trip();
        $doc  = $this->file($trip);

        Event::assertDispatched(PodReceived::class, function (PodReceived $e) use ($doc, $trip) {
            // The registry's two fields, under the registry's own names.
            return $e->payload() === ['trip_id' => $trip->id, 'attachment_id' => $doc->id];
        });
    }

    public function test_a_non_pod_document_does_not_emit_pod_received(): void
    {
        Event::fake([PodReceived::class]);

        $this->file($this->trip(), $this->podFile('ewb.jpg'), [
            'document_type' => TransportDocumentType::EWAYBILL,
        ]);

        Event::assertNotDispatched(PodReceived::class);
    }

    /* ── STT-008 ──────────────────────────────────────────────────────── */

    public function test_verifying_a_pod_on_a_delivered_trip_moves_it_to_pod_verified(): void
    {
        $trip = $this->trip(self::TENANT_A, TripStatus::DELIVERED);
        $doc  = $this->file($trip);

        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::POD_VERIFIED, $trip->fresh()->status);
        $this->assertSame(TripDocumentStatus::VERIFIED, $doc->fresh()->status);
        $this->assertNotNull($doc->fresh()->verified_at);
    }

    public function test_verifying_a_pod_on_a_trip_that_is_not_delivered_leaves_the_trip_alone(): void
    {
        // STT-006 (dispatched -> in_transit) is not wired — it is P1's half of
        // SNG-TRN-013 — so no trip can currently REACH `delivered`. Verifying
        // must still record the document rather than throw over a gap that is
        // not the verifier's fault.
        $trip = $this->trip(self::TENANT_A, TripStatus::APPROVED);
        $doc  = $this->file($trip);

        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(TripDocumentStatus::VERIFIED, $doc->fresh()->status);
    }

    public function test_a_document_cannot_be_decided_twice(): void
    {
        $doc = $this->file($this->trip());
        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->documents->verify($doc->fresh(), self::TENANT_A, $this->actor);
    }

    public function test_rejecting_requires_a_reason_and_is_terminal(): void
    {
        $doc = $this->file($this->trip());

        try {
            $this->documents->reject($doc, '   ', self::TENANT_A, $this->actor);
            $this->fail('a blank reason should have been refused');
        } catch (BusinessException) {
            // expected
        }

        $this->documents->reject($doc, 'illegible signature', self::TENANT_A, $this->actor);

        $this->assertSame(TripDocumentStatus::REJECTED, $doc->fresh()->status);
        $this->assertSame('illegible signature', $doc->fresh()->rejection_reason);

        // Terminal: a replacement is a new row, not a re-decision.
        $this->expectException(BusinessException::class);
        $this->documents->verify($doc->fresh(), self::TENANT_A, $this->actor);
    }

    /* ── CTR-012, immutable after verification ────────────────────────── */

    public function test_the_file_is_frozen_once_verified_even_through_a_direct_update(): void
    {
        $doc = $this->file($this->trip());
        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $fresh = $doc->fresh();

        $this->expectException(RuntimeException::class);
        $fresh->update(['file_hash' => str_repeat('0', 64)]);
    }

    public function test_notes_may_still_be_added_to_a_verified_document(): void
    {
        // The guard is on the FILE, not the row. Annotating evidence is not
        // altering it.
        $doc = $this->file($this->trip());
        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $fresh = $doc->fresh();
        $fresh->update(['notes' => 'checked against the consignment note']);

        $this->assertSame('checked against the consignment note', $fresh->fresh()->notes);
    }

    public function test_a_received_document_is_still_replaceable(): void
    {
        $doc = $this->file($this->trip());

        $doc->update(['file_name' => 'rescanned.jpg']);

        $this->assertSame('rescanned.jpg', $doc->fresh()->file_name);
    }

    /* ── The billing gate — the acceptance criterion ──────────────────── */

    public function test_a_trip_with_no_pod_is_not_billable(): void
    {
        $trip = $this->trip();

        $verdict = $this->documents->billingReadiness($trip, self::TENANT_A);

        $this->assertFalse($verdict['billable']);
        $this->assertFalse($verdict['has_verified_pod']);
        $this->assertFalse($verdict['waived']);
    }

    public function test_an_unverified_pod_does_not_unlock_billing(): void
    {
        // Receiving proof is not the same as checking it. STT-008's guard is
        // "POD valid", and only `verified` satisfies it.
        $trip = $this->trip();
        $this->file($trip);

        $this->assertFalse($this->documents->billingReadiness($trip, self::TENANT_A)['billable']);
    }

    public function test_a_rejected_pod_does_not_unlock_billing(): void
    {
        $trip = $this->trip();
        $doc  = $this->file($trip);
        $this->documents->reject($doc, 'wrong consignment', self::TENANT_A, $this->actor);

        $this->assertFalse($this->documents->billingReadiness($trip, self::TENANT_A)['billable']);
    }

    public function test_a_verified_pod_unlocks_billing(): void
    {
        $trip = $this->trip();
        $doc  = $this->file($trip);
        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        $verdict = $this->documents->billingReadiness($trip, self::TENANT_A);

        $this->assertTrue($verdict['billable']);
        $this->assertTrue($verdict['has_verified_pod']);
    }

    public function test_a_verified_non_pod_document_does_not_unlock_billing(): void
    {
        // An e-way bill is not proof of delivery.
        $trip = $this->trip();
        $ewb  = $this->file($trip, $this->podFile('ewb.jpg'), [
            'document_type' => TransportDocumentType::EWAYBILL,
        ]);
        $this->documents->verify($ewb, self::TENANT_A, $this->actor);

        $this->assertFalse($this->documents->billingReadiness($trip, self::TENANT_A)['billable']);
    }

    public function test_a_waived_exception_unlocks_billing_without_a_pod(): void
    {
        // The "unless approved exception" arm. Cannot fire in production yet —
        // ExceptionStatus::WAIVED needs an authorising role and BLK-10 means no
        // CRM account maps to one — so the row is written directly here to prove
        // the gate honours it the moment waivers become reachable.
        $trip = $this->trip();

        DB::table('trip_exceptions')->insert([
            'tenant_id' => self::TENANT_A, 'trip_id' => $trip->id,
            'exception_number' => 'EXC-'.Str::random(6),
            'category' => 'documentation', 'severity' => 'medium',
            'status' => ExceptionStatus::WAIVED, 'source' => 'manual',
            'cause' => 'consignee refused to sign; delivery confirmed by gate log',
            'raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $verdict = $this->documents->billingReadiness($trip, self::TENANT_A);

        $this->assertTrue($verdict['billable']);
        $this->assertTrue($verdict['waived']);
        $this->assertFalse($verdict['has_verified_pod']);
    }

    public function test_an_open_exception_does_not_unlock_billing(): void
    {
        $trip = $this->trip();

        DB::table('trip_exceptions')->insert([
            'tenant_id' => self::TENANT_A, 'trip_id' => $trip->id,
            'exception_number' => 'EXC-'.Str::random(6),
            'category' => 'documentation', 'severity' => 'medium',
            'status' => ExceptionStatus::OPEN, 'source' => 'manual',
            'cause' => 'POD not yet returned',
            'raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($this->documents->billingReadiness($trip, self::TENANT_A)['billable']);
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_documents_and_the_gate_are_tenant_scoped(): void
    {
        $tripA = $this->trip(self::TENANT_A);
        $tripB = $this->trip(self::TENANT_B);

        $docB = $this->documents->file($tripB, $this->podFile(), [], self::TENANT_B, null);
        $this->documents->verify($docB, self::TENANT_B, null);

        $this->assertCount(0, $this->documents->forTrip($tripB->id, self::TENANT_A));
        $this->assertFalse($this->documents->billingReadiness($tripA, self::TENANT_A)['billable']);
        $this->assertTrue($this->documents->billingReadiness($tripB, self::TENANT_B)['billable']);
    }

    public function test_another_tenants_document_cannot_be_verified(): void
    {
        $doc = $this->file($this->trip());

        $this->expectException(ResourceNotFoundException::class);
        $this->documents->verify($doc, self::TENANT_B, $this->actor);
    }

    /* ── Auditing ─────────────────────────────────────────────────────── */

    public function test_filing_and_verifying_are_both_audited(): void
    {
        $doc = $this->file($this->trip());
        $this->assertSame(1, $doc->auditTrail()->count());

        $this->documents->verify($doc, self::TENANT_A, $this->actor);
        $this->assertSame(2, $doc->auditTrail()->count());
    }

    /* ── The vocabulary ───────────────────────────────────────────────── */

    public function test_only_the_edges_this_ticket_owns_are_wired(): void
    {
        $this->assertTrue(TripDocumentStatus::canTransition(
            TripDocumentStatus::RECEIVED, TripDocumentStatus::VERIFIED));
        $this->assertTrue(TripDocumentStatus::canTransition(
            TripDocumentStatus::RECEIVED, TripDocumentStatus::REJECTED));

        // Both decisions are terminal.
        $this->assertFalse(TripDocumentStatus::canTransition(
            TripDocumentStatus::VERIFIED, TripDocumentStatus::REJECTED));
        $this->assertFalse(TripDocumentStatus::canTransition(
            TripDocumentStatus::REJECTED, TripDocumentStatus::VERIFIED));
    }
}
