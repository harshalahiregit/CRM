<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Directory\StandaloneDriverDirectory;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Services\DriverDocumentService;
use App\Domains\Fleet\Services\DriverService;
use App\Models\Tenant;
use App\Models\Transport\TransportDocument;
use App\Models\User;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — a driver's paperwork (T-43).
 *
 * The twin of `VehicleDocumentTest`, and the last thing keeping half of Dev 1's
 * driver controller alive. Same rules as the vehicle side, and one that matters
 * more here: a driving licence is the single document that stops a PERSON being
 * sent out, so photographing it must never clear them.
 *
 * Fleet does not own documents. Every write goes through STOS-DOC's service.
 * What Fleet owns is the consequence — a verified licence sets
 * `driver_profiles.licence_expiry`, which is what `DriverService` blocks on.
 */
class DriverDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();

        // Standalone, so a test does not need a CRM person to exist.
        $this->app->bind(DriverDirectory::class, fn () => new StandaloneDriverDirectory());
    }

    private function user(string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => $role,
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A person in the register, with a Fleet profile over them. */
    private function driver(array $profile = []): int
    {
        $personId = DB::table('stos_drivers')->insertGetId([
            'company_id' => self::COMPANY, 'name' => 'Rajesh Kumar',
            'phone' => '98765'.random_int(10000, 99999), 'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(DriverService::class)->saveProfile(self::COMPANY, 'stos', $personId, $profile, 1);

        return $personId;
    }

    private function svc(): DriverDocumentService
    {
        return app(DriverDocumentService::class);
    }

    private function fileDoc(int $personId, string $type, ?string $validUntil = null, ?string $number = null): TransportDocument
    {
        return $this->svc()->file(self::COMPANY, 'stos', $personId, $type, [
            'document_number' => $number ?? 'DL-'.Str::random(5),
            'valid_until' => $validUntil ?? now()->addYear()->toDateString(),
        ], $this->user())['document'];
    }

    private function profileFor(int $personId): DriverProfile
    {
        return DriverProfile::forCompany(self::COMPANY)
            ->where('source', 'stos')->where('source_id', $personId)->firstOrFail();
    }

    /* ── An upload is not a clearance ───────────────────────────── */

    public function test_filing_a_licence_does_not_clear_the_driver(): void
    {
        // No licence on file at all: DriverService calls that "unknown", which
        // is a hard block — nobody should be dispatched on a licence nobody
        // has seen.
        $personId = $this->driver();

        $result = $this->svc()->file(self::COMPANY, 'stos', $personId,
            TransportDocumentType::DRIVING_LICENSE,
            ['document_number' => 'DL-123', 'valid_until' => now()->addYear()->toDateString()],
            $this->user());

        $this->assertFalse($result['gate_moved']);
        $this->assertStringContainsString('verified', strtolower($result['notice']));

        // The profile is untouched. Attaching a photograph is not a decision.
        $this->assertNull($this->profileFor($personId)->licence_expiry);
    }

    public function test_verifying_the_licence_is_what_sets_the_expiry(): void
    {
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE,
            now()->addYear()->toDateString());

        $result = $this->svc()->verify($document->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertTrue($result['gate_moved']);
        $this->assertSame(
            now()->addYear()->toDateString(),
            $this->profileFor($personId)->licence_expiry->toDateString()
        );
    }

    public function test_a_rejected_licence_sets_nothing_and_has_to_say_why(): void
    {
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->verify($document->id, self::COMPANY, 'REJECTED', null, $this->user());
    }

    public function test_a_rejection_with_a_reason_leaves_the_driver_blocked(): void
    {
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE);

        $result = $this->svc()->verify($document->id, self::COMPANY, 'REJECTED',
            'The photograph is unreadable.', $this->user());

        $this->assertFalse($result['gate_moved']);
        $this->assertNull($this->profileFor($personId)->licence_expiry);
        $this->assertSame('The photograph is unreadable.', $result['document']->rejection_reason);
    }

    /* ── The projection, and what it refuses to do ──────────────── */

    public function test_an_older_licence_never_moves_the_date_backwards(): void
    {
        // Licences get verified out of order whenever somebody clears a
        // backlog. A stale one overwriting a current one would ground a legal
        // driver with nothing on screen explaining it.
        $personId = $this->driver(['licence_expiry' => now()->addYear()->toDateString()]);

        $old = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE,
            now()->addMonth()->toDateString());

        $this->svc()->verify($old->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertSame(
            now()->addYear()->toDateString(),
            $this->profileFor($personId)->licence_expiry->toDateString()
        );
    }

    public function test_a_verified_medical_certificate_now_sets_the_medical_expiry(): void
    {
        // T-41 closed the gap this test used to record. When it was written
        // `driver_profiles` had no `medical_expiry`, so a verified certificate
        // was kept and gated nothing; the column exists now and it projects
        // exactly as the licence does.
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::MEDICAL_CERTIFICATE,
            now()->addMonths(6)->toDateString());

        $result = $this->svc()->verify($document->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertTrue($result['gate_moved']);
        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $this->profileFor($personId)->medical_expiry->toDateString()
        );
    }

    public function test_a_medical_certificate_number_never_lands_in_the_licence_field(): void
    {
        // Both documents carry a number. Writing a doctor's reference into
        // `licence_number` would put it where a roadside check looks for a
        // licence, so the carry-across is guarded on the document type.
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::MEDICAL_CERTIFICATE, null, 'MED-99887');

        $this->svc()->verify($document->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertNull($this->profileFor($personId)->licence_number);
    }

    public function test_the_licence_number_is_carried_across_but_never_overwritten(): void
    {
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE, null, 'MH0120110099999');

        $this->svc()->verify($document->id, self::COMPANY, 'VERIFIED', null, $this->user());
        $this->assertSame('MH0120110099999', $this->profileFor($personId)->licence_number);

        // A profile that already carries one is left alone — correcting a
        // licence number belongs on the profile, not in a document upload.
        $second = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE, null, 'DIFFERENT-1');
        $this->svc()->verify($second->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertSame('MH0120110099999', $this->profileFor($personId)->licence_number);
    }

    /* ── It actually clears the dispatch block ──────────────────── */

    public function test_verifying_a_licence_makes_the_driver_allocatable(): void
    {
        $personId = $this->driver();

        // Blocked before: no licence on file is 'unknown', which excludes.
        $before = app(DriverService::class)->eligible(self::COMPANY);
        $this->assertCount(0, $before['eligible']);
        $this->assertSame('driver_license_unrecorded', $before['excluded'][0]['blockers'][0]['code']);

        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE,
            now()->addYear()->toDateString());

        // Still blocked after the upload — this is the whole point of T-57/T-43.
        $this->assertCount(0, app(DriverService::class)->eligible(self::COMPANY)['eligible']);

        $this->svc()->verify($document->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $after = app(DriverService::class)->eligible(self::COMPANY);
        $this->assertCount(1, $after['eligible'], 'a verified licence must clear the driver');
    }

    /* ── Boundaries ─────────────────────────────────────────────── */

    public function test_a_vehicle_document_cannot_be_filed_against_a_driver(): void
    {
        $personId = $this->driver();

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->fileDoc($personId, TransportDocumentType::RC);
    }

    public function test_a_person_with_no_profile_is_told_what_to_do_first(): void
    {
        $personId = DB::table('stos_drivers')->insertGetId([
            'company_id' => self::COMPANY, 'name' => 'Not A Driver Yet',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Refused rather than created. `saveProfile()` is the one place a
        // profile comes into existence; a document upload quietly making a
        // second one is the duplicate entry point the owner ruled against.
        try {
            $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE);
            $this->fail('filing against a person with no profile should be refused');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('drivers board', $e->getMessage());
        }

        $this->assertSame(0, DriverProfile::forCompany(self::COMPANY)->count());
    }

    public function test_the_deprecated_fitness_type_is_accepted_but_not_offered(): void
    {
        $personId = $this->driver();

        // Tenants configured `fitness` as a required driver document before
        // `medical_certificate` existed, and documents are already filed under
        // it. Still accepted...
        $document = $this->fileDoc($personId, TransportDocumentType::FITNESS);
        $this->assertSame('fitness', $document->document_type);

        // ...and not offered, because it reads as a vehicle document on a
        // driver screen.
        $types = collect($this->svc()->forDriver(self::COMPANY, 'stos', $personId)['types'])->pluck('value');
        $this->assertFalse($types->contains('fitness'));
        $this->assertTrue($types->contains(TransportDocumentType::DRIVING_LICENSE));
    }

    /* ── The read ───────────────────────────────────────────────── */

    public function test_the_listing_says_the_licence_is_filed_but_not_yet_verified(): void
    {
        $personId = $this->driver();
        $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE);

        $data = $this->svc()->forDriver(self::COMPANY, 'stos', $personId);
        $gate = $data['gating'][TransportDocumentType::DRIVING_LICENSE];

        // "Filed but not cleared" is the state people assume does not exist, so
        // it is the one the screen has to be able to show.
        $this->assertFalse($gate['verified']);
        $this->assertTrue($gate['awaiting']);
        $this->assertNull($gate['driver_date']);
    }

    public function test_another_companys_driver_cannot_be_read(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $personId = $this->driver();

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->forDriver(2, 'stos', $personId);
    }

    /* ── The endpoints Dev 1's controller is waiting on ─────────── */

    public function test_the_http_surface_files_and_verifies(): void
    {
        $personId = $this->driver();
        $admin = $this->user();

        $this->actingAs($admin)
            ->postJson("/api/v1/fleet/drivers/stos/{$personId}/documents", [
                'document_type' => TransportDocumentType::DRIVING_LICENSE,
                'document_number' => 'DL-HTTP-1',
                'valid_until' => now()->addYear()->toDateString(),
            ])->assertCreated()->assertJsonPath('data.gate_moved', false);

        $documentId = TransportDocument::where('entity_type', 'driver')->value('id');

        $this->actingAs($admin)
            ->patchJson("/api/v1/fleet/driver-documents/{$documentId}/verify", ['verdict' => 'VERIFIED'])
            ->assertOk()->assertJsonPath('data.gate_moved', true);

        $this->assertNotNull($this->profileFor($personId)->licence_expiry);
    }

    public function test_a_non_admin_cannot_verify(): void
    {
        $personId = $this->driver();
        $document = $this->fileDoc($personId, TransportDocumentType::DRIVING_LICENSE);

        $this->actingAs($this->user('staff'))
            ->patchJson("/api/v1/fleet/driver-documents/{$document->id}/verify", ['verdict' => 'VERIFIED'])
            ->assertStatus(403);

        $this->assertNull($this->profileFor($personId)->licence_expiry);
    }

    public function test_eligible_is_still_a_word_and_not_a_directory_name(): void
    {
        // `/drivers/{source}/{person}` sits beside `/drivers/eligible`. The
        // route constraints have to keep those apart, or the document routes
        // swallow the allocation one.
        $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/drivers/eligible')
            ->assertOk()->assertJsonStructure(['data' => ['eligible', 'excluded']]);
    }
}
