<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\VehicleDocumentService;
use App\Models\Tenant;
use App\Models\Transport\TransportDocument;
use App\Models\User;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — statutory paperwork, and the rule that an upload is not a
 * clearance (T-57, and the first half of T-53).
 *
 * Fleet does NOT own documents. Every write goes through STOS-DOC's service so
 * versioning and the audit trail happen once, in Person 3's code. What Fleet
 * owns is the consequence: five documents gate dispatch, and the vehicle's five
 * date columns are a projection of the VERIFIED ones — never of an upload.
 */
class VehicleDocumentTest extends TestCase
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
    }

    private function user(string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => $role,
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'status' => Vehicle::STATUS_AVAILABLE,
        ], $over));
    }

    private function svc(): VehicleDocumentService
    {
        return app(VehicleDocumentService::class);
    }

    private function fileDoc(Vehicle $v, string $type, ?string $validUntil = null): TransportDocument
    {
        return $this->svc()->file($v->id, self::COMPANY, $type, [
            'document_number' => 'DOC-'.Str::random(5),
            'valid_until' => $validUntil ?? now()->addYear()->toDateString(),
        ], $this->user())['document'];
    }

    /* ── The file itself ────────────────────────────────────────── */

    public function test_an_uploaded_certificate_is_actually_kept(): void
    {
        // Found by putting a real PDF through the endpoint, not by a test.
        // `TransportDocumentService` stores file_path/file_name/file_hash and
        // never takes an UploadedFile, so the validated `file` key was
        // intersected away: the API answered 201, the screen said "Filed", and
        // the certificate was gone. Every test here passed a number and a date
        // and no file, so none of them noticed.
        Storage::fake('local');

        $vehicle = $this->vehicle();
        $file = UploadedFile::fake()->create('insurance.pdf', 20, 'application/pdf');

        $document = $this->svc()->file($vehicle->id, self::COMPANY, 'insurance', [
            'document_number' => 'INS-FILE-1',
            'valid_until' => now()->addYear()->toDateString(),
            'file' => $file,
        ], $this->user())['document'];

        $this->assertNotNull($document->file_path, 'the upload was dropped');
        $this->assertSame('insurance.pdf', $document->file_name);
        $this->assertNotNull($document->file_hash);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_a_document_with_no_file_still_files(): void
    {
        // Recording a certificate number without a scan is normal — the file
        // is evidence, not the record.
        $vehicle = $this->vehicle();

        $document = $this->svc()->file($vehicle->id, self::COMPANY, 'insurance', [
            'document_number' => 'INS-NOFILE-1',
            'valid_until' => now()->addYear()->toDateString(),
        ], $this->user())['document'];

        $this->assertNull($document->file_path);
        $this->assertSame('INS-NOFILE-1', $document->document_number);
    }

    /* ── The types the owner approved ───────────────────────────── */

    public function test_rc_and_puc_can_finally_be_filed_as_themselves(): void
    {
        $vehicle = $this->vehicle();

        // Before the 2026-09-19 approval these could only be filed as the
        // catch-all `vehicle_doc`, so nothing could tell which of the five
        // dispatch dates a given certificate was meant to set.
        $rc  = $this->fileDoc($vehicle, TransportDocumentType::RC);
        $puc = $this->fileDoc($vehicle, TransportDocumentType::PUC);

        $this->assertSame('rc', $rc->document_type);
        $this->assertSame('puc', $puc->document_type);
    }

    public function test_all_five_gate_documents_map_to_a_vehicle_date(): void
    {
        $svc = $this->svc();

        $this->assertSame('registration_expiry', $svc->gatedBy('rc'));
        $this->assertSame('insurance_expiry', $svc->gatedBy('insurance'));
        $this->assertSame('fitness_expiry', $svc->gatedBy('fitness'));
        $this->assertSame('permit_expiry', $svc->gatedBy('permit'));
        $this->assertSame('puc_expiry', $svc->gatedBy('puc'));
    }

    public function test_road_tax_is_statutory_but_does_not_gate_dispatch(): void
    {
        // It has to be filed and tracked; it is not a reason to stop a truck.
        $this->assertNull($this->svc()->gatedBy('tax'));
    }

    public function test_a_driver_document_cannot_be_filed_against_a_vehicle(): void
    {
        $vehicle = $this->vehicle();

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/documents", [
                'document_type' => 'driving_license',
            ])->assertStatus(422);
    }

    /* ── An upload is not a clearance ───────────────────────────── */

    public function test_uploading_a_certificate_does_not_move_the_dispatch_date(): void
    {
        $vehicle = $this->vehicle();

        $result = $this->svc()->file($vehicle->id, self::COMPANY, 'insurance', [
            'valid_until' => now()->addYear()->toDateString(),
        ], $this->user());

        // The whole rule. A truck is not cleared because somebody managed to
        // photograph a certificate.
        $this->assertFalse($result['gate_moved']);
        $this->assertNull($vehicle->fresh()->insurance_expiry);
    }

    public function test_the_response_says_the_vehicle_is_still_blocked(): void
    {
        $vehicle = $this->vehicle();

        $result = $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/documents", [
                'document_type' => 'insurance',
                'valid_until' => now()->addYear()->toDateString(),
            ])->assertCreated()->json('data');

        // Said out loud rather than left to be inferred from an unchanged date.
        $this->assertFalse($result['gate_moved']);
        $this->assertStringContainsString('until it is verified', $result['notice']);
    }

    public function test_a_document_arrives_as_uploaded_not_verified(): void
    {
        $doc = $this->fileDoc($this->vehicle(), 'insurance');

        $this->assertSame(TransportDocument::VERIFICATION_UPLOADED, $doc->verification_status);
    }

    /* ── Verifying is what moves the gate ───────────────────────── */

    public function test_verifying_projects_the_expiry_onto_the_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $expiry = now()->addYear()->toDateString();
        $doc = $this->fileDoc($vehicle, 'insurance', $expiry);

        $result = $this->svc()->verify($doc->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertTrue($result['gate_moved']);
        $this->assertSame($expiry, $vehicle->fresh()->insurance_expiry->toDateString());
    }

    public function test_each_document_type_sets_its_own_date(): void
    {
        $vehicle = $this->vehicle();

        foreach (TransportDocumentType::GATES_DISPATCH as $type => $column) {
            $doc = $this->fileDoc($vehicle, $type, now()->addMonths(6)->toDateString());
            $this->svc()->verify($doc->id, self::COMPANY, 'VERIFIED', null, $this->user());
        }

        $fresh = $vehicle->fresh();

        // Five documents, five distinct dates — the ambiguity the approval fixed.
        foreach (TransportDocumentType::GATES_DISPATCH as $column) {
            $this->assertNotNull($fresh->{$column}, $column.' was never set');
        }
    }

    public function test_rejecting_a_document_moves_nothing_and_needs_a_reason(): void
    {
        $vehicle = $this->vehicle();
        $doc = $this->fileDoc($vehicle, 'insurance');

        // A rejection without a reason cannot be acted on — whoever uploaded it
        // has to know what to fix.
        $this->actingAs($this->user())
            ->patchJson("/api/v1/fleet/documents/{$doc->id}/verify", ['verdict' => 'REJECTED'])
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->patchJson("/api/v1/fleet/documents/{$doc->id}/verify", [
                'verdict' => 'REJECTED', 'reason' => 'Photograph is unreadable',
            ])->assertOk();

        $this->assertNull($vehicle->fresh()->insurance_expiry);
        $this->assertSame('Photograph is unreadable', $doc->fresh()->rejection_reason);
    }

    public function test_an_older_certificate_verified_late_does_not_pull_the_date_back(): void
    {
        $vehicle = $this->vehicle();

        $current = $this->fileDoc($vehicle, 'insurance', now()->addYear()->toDateString());
        $this->svc()->verify($current->id, self::COMPANY, 'VERIFIED', null, $this->user());
        $good = $vehicle->fresh()->insurance_expiry->toDateString();

        // Somebody clears a backlog and verifies last year's certificate after
        // this year's. Letting it win would block a compliant truck with
        // nothing on screen explaining why.
        $old = $this->fileDoc($vehicle, 'insurance', now()->subMonth()->toDateString());
        $this->svc()->verify($old->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertSame($good, $vehicle->fresh()->insurance_expiry->toDateString());
    }

    public function test_verifying_a_non_gating_document_changes_no_date(): void
    {
        $vehicle = $this->vehicle();
        $doc = $this->fileDoc($vehicle, 'tax');

        $result = $this->svc()->verify($doc->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $this->assertFalse($result['gate_moved']);
    }

    public function test_only_an_admin_may_verify(): void
    {
        $doc = $this->fileDoc($this->vehicle(), 'insurance');

        $this->actingAs($this->user('staff'))
            ->patchJson("/api/v1/fleet/documents/{$doc->id}/verify", ['verdict' => 'VERIFIED'])
            ->assertForbidden();
    }

    /* ── Fleet does not own the store ───────────────────────────── */

    public function test_a_renewal_supersedes_rather_than_overwrites(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->fileDoc($vehicle, 'insurance', now()->addMonth()->toDateString());

        $this->svc()->renew($first->id, self::COMPANY, [
            'valid_until' => now()->addYear()->toDateString(),
        ], $this->user());

        // Versioning is STOS-DOC's and history is never edited away.
        $this->assertSame(TransportDocument::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(2, TransportDocument::where('entity_id', $vehicle->id)->count());
    }

    public function test_a_renewal_still_has_to_be_verified(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->fileDoc($vehicle, 'insurance', now()->addMonth()->toDateString());
        $this->svc()->verify($first->id, self::COMPANY, 'VERIFIED', null, $this->user());

        $result = $this->svc()->renew($first->id, self::COMPANY, [
            'valid_until' => now()->addYears(2)->toDateString(),
        ], $this->user());

        // The new version does not inherit the old one's clearance.
        $this->assertFalse($result['gate_moved']);
        $this->assertSame(
            now()->addMonth()->toDateString(),
            $vehicle->fresh()->insurance_expiry->toDateString()
        );
    }

    /* ── What the screen is told ────────────────────────────────── */

    public function test_the_listing_shows_which_gates_are_satisfied(): void
    {
        $vehicle = $this->vehicle();
        $doc = $this->fileDoc($vehicle, 'insurance');
        $this->svc()->verify($doc->id, self::COMPANY, 'VERIFIED', null, $this->user());
        $this->fileDoc($vehicle, 'fitness');   // filed, not verified

        $data = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/documents")
            ->assertOk()->json('data');

        $this->assertTrue($data['gating']['insurance']['verified']);
        $this->assertFalse($data['gating']['fitness']['verified']);
        // "Nothing filed" and "filed but not checked yet" are different
        // problems with different owners.
        $this->assertTrue($data['gating']['fitness']['awaiting']);
        $this->assertFalse($data['gating']['rc']['awaiting']);
    }

    public function test_the_screen_is_only_offered_types_a_vehicle_may_carry(): void
    {
        $vehicle = $this->vehicle();

        $types = collect($this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/documents")
            ->assertOk()->json('data.types'))->pluck('value');

        $this->assertTrue($types->contains('rc'));
        $this->assertTrue($types->contains('puc'));
        // Never offer something the service would then refuse.
        $this->assertFalse($types->contains('driving_license'));
        $this->assertFalse($types->contains('pod'));
    }

    /* ── Tenancy ────────────────────────────────────────────────── */

    public function test_another_companys_vehicle_cannot_be_filed_against(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $theirs = Vehicle::create([
            'company_id' => 2, 'registration_number' => 'MH99ZZ0001',
            'vehicle_type' => 'truck', 'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$theirs->id}/documents", ['document_type' => 'insurance'])
            ->assertStatus(404);
    }
}
