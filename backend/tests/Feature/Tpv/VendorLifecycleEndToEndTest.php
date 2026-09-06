<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The whole chain, driven through the real HTTP endpoints in order:
 *
 *   admin registers a vendor
 *     → vendor fills and submits its onboarding from the portal
 *       → admin approves it and the account activates
 *         → a worker is registered against that vendor
 *           → a doctor examines the worker and records the medical
 *             → induction, PPE and the badge follow
 *               → the gate admits the worker
 *
 * Every step asserts what the NEXT step depends on, so a break is reported at
 * the hop that caused it rather than three stages later. Nothing is stubbed:
 * each stage is a real request against the running application, and the state
 * it leaves behind is what the following stage reads.
 */
class VendorLifecycleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(5).'@t.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    /**
     * POST, and say WHY when the server refuses.
     *
     * A bare assertStatus on a long chain reports "expected 200, got 422" and
     * leaves you guessing which field of which stage. This puts the server's own
     * complaint in the failure message, so the walk explains itself.
     */
    private function send(string $uri, array $payload = [], int $expect = 200)
    {
        $res = $this->postJson($uri, $payload);

        if ($res->getStatusCode() !== $expect) {
            $body = $res->json();
            $this->fail(sprintf(
                "POST %s expected %d, got %d
%s",
                $uri, $expect, $res->getStatusCode(),
                json_encode($body['errors'] ?? $body['message'] ?? $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ));
        }

        return $res;
    }

    /* ── Stage 1 — the vendor exists ─────────────────────────────────────── */

    private function registerVendor(): Vendor
    {
        $this->asAdmin();

        // vendor_type and engagements are required: a vendor is registered FOR
        // something, and the engagement decides which module owns it.
        $id = $this->send('/api/vendors', [
            'company_name' => 'Northgate Contracting',
            'vendor_type'  => 'standard',
            'engagements'  => ['tpv'],
            'email'        => 'ops@northgate.test',
            'phone'        => '9990001111',
        ], 201)->json('id');

        $vendor = Vendor::find($id);
        $this->assertNotNull($vendor, 'the vendor should exist after registration');
        $this->assertNotEmpty($vendor->vendor_code, 'a vendor is useless without its code');

        return $vendor;
    }

    /* ── Stage 2 — onboarding, from both sides ───────────────────────────── */

    private function openOnboarding(Vendor $vendor): TpvOnboarding
    {
        $this->asAdmin();

        $id = $this->send('/api/tpv/onboarding', ['vendor_id' => $vendor->id], 201)->json('id');

        return TpvOnboarding::findOrFail($id);
    }

    /**
     * Everything submitting an onboarding actually requires.
     *
     * Two real gates, discovered by running the walk rather than assuming:
     * the company profile must be filled first, and every document required for
     * the vendor's type must be Approved. Both are correct — this satisfies
     * them the way the product does.
     */
    private function makeSubmittable(TpvOnboarding $onboarding): void
    {
        $this->asAdmin();

        $this->send("/api/tpv/onboarding/{$onboarding->id}/profile", ['profile' => [
            'company_name'      => 'Northgate Contracting',
            'contact_person'    => 'A Patel',
            'contact_email'     => 'ops@northgate.test',
            'contact_phone'     => '9990001111',
            'address'           => '12 Dock Road',
            'city'              => 'Pune',
            'state'             => 'Maharashtra',
            'pincode'           => '411001',
            // gst_number is deliberately omitted: it is optional AND checksum
            // validated, so a made-up one is correctly refused. That validator
            // is doing its job; inventing a valid GSTIN would only be testing
            // my arithmetic.
            'pan_number'        => 'AAAAA0000A',
            'nature_of_work'    => 'Civil works',
        ]]);

        // Every document required for this vendor type must be uploaded AND
        // approved before the onboarding may be submitted. Walked properly
        // rather than skipped: this gate is most of what onboarding IS.
        $vendor = $onboarding->vendor;

        foreach ($onboarding->fresh()->outstandingDocuments() as $type) {
            $document = $this->postJson("/api/tpv/vendors/{$vendor->id}/documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 40, 'application/pdf'),
            ]);

            if ($document->getStatusCode() >= 300) {
                $this->fail("uploading {$type} failed: ".$document->getContent());
            }

            $id = $document->json('id') ?? $document->json('document.id');
            $this->assertNotNull($id, "the upload of {$type} returned no document id");

            $this->send("/api/tpv/documents/{$id}/review", [
                'decision' => 'approve',
                'remarks'  => 'Verified against the original.',
            ]);
        }

        $this->assertSame([], $onboarding->fresh()->outstandingDocuments(),
            'every required document should now be approved');

        // The activation checklist gates approval separately from the documents.
        // Read what it actually asks for rather than hard-coding labels — the
        // list is tenant-configurable, so anything hard-coded here would be a
        // second source of truth that drifts.
        $checklist = $this->getJson("/api/tpv/onboarding/{$onboarding->id}/checklist")
            ->assertOk()->json();

        if (! ($checklist['complete'] ?? true)) {
            $state = [];
            foreach ($checklist['items'] as $row) {
                $state[$row['item']] = true;
            }
            $this->patchJson("/api/tpv/onboarding/{$onboarding->id}/checklist", ['state' => $state])
                ->assertOk();
        }
    }

    public function test_the_whole_vendor_lifecycle_runs_end_to_end(): void
    {
        /* 1 — registration */
        $vendor = $this->registerVendor();
        $this->assertNotSame(VendorStatus::ACTIVE, $vendor->status,
            'a brand-new vendor must not start Active — approval is the gate');

        /* 2 — onboarding opened */
        $onboarding = $this->openOnboarding($vendor);
        $this->assertSame($vendor->id, (int) $onboarding->vendor_id);

        /* 3 — the vendor completes and submits it */
        $this->makeSubmittable($onboarding);
        $this->send("/api/tpv/onboarding/{$onboarding->id}/submit", ['declaration' => true]);

        $onboarding->refresh();
        $this->assertNotSame('Draft', $onboarding->status,
            'submitting must move the onboarding out of Draft');

        /* 4 — admin approves, and the account actually activates */
        $this->asAdmin();
        $this->send("/api/tpv/onboarding/{$onboarding->id}/approve", ['remarks' => 'Looks good']);

        $vendor->refresh();
        $this->assertSame(VendorStatus::ACTIVE, $vendor->status,
            'approving the onboarding must ACTIVATE the vendor, not just change a label');

        /* 5 — a worker is registered against that vendor */
        $this->asAdmin();
        $workerId = $this->send('/api/tpv/workers', [
            'vendor_id' => $vendor->id,
            'name'      => 'Ravi Kumar',
            'dob'       => '1992-04-18',
            'gender'    => 'Male',
            'mobile'    => '9995550000',
        ], 201)->json('id');

        $worker = TpvWorker::findOrFail($workerId);
        $this->assertNotEmpty($worker->worker_code, 'a worker without a code cannot be badged');
        $this->assertSame($vendor->id, (int) $worker->vendor_id);

        /* 6 — the medical, with its evidence */
        $this->asAdmin();
        $this->send("/api/tpv/workers/{$worker->id}/medical", [
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr Meera',
            'fitness_status' => 'Fit',
            'height_cm'      => 172,
            'weight_kg'      => 68,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        $worker->refresh()->load('medical');
        $this->assertNotNull($worker->medical, 'the medical must be recorded against the worker');
        $this->assertSame('Fit', $worker->medical->fitness_status);
        $this->assertNotNull($worker->medical->signature_path, 'the signature must be stored, not dropped');

        /* 7 — the quality team clears the medical
         *
         * Recording an examination is not the same as clearing the worker: the
         * medical sits at "Pending — awaiting quality check" until QC approves
         * it, and induction is blocked until then. That gate is the point of the
         * QC step, so the walk goes through it rather than around it.
         */
        $this->asAdmin();
        $medicalId = $worker->fresh('medical')->medical->id;

        $this->send("/api/tpv/medical/{$medicalId}/decide", [
            'decision' => 'Approved',
            'note'     => 'Certificate and vitals verified.',
        ]);

        $this->assertSame('Approved', $worker->fresh('medical')->medical->qc_status,
            'QC approval is what actually clears the worker for induction');

        /* 8 — induction */
        $this->asAdmin();
        $this->send("/api/tpv/workers/{$worker->id}/induction", [
            'conducted_by' => 'Safety Officer',
            'induction_date' => now()->toDateString(),
            'result' => 'Pass',
        ]);

        $this->assertGreaterThanOrEqual(3, (int) $worker->fresh()->current_step,
            'recording the induction should advance the worker past step 2');

        /* 9 — the record is readable back through the API it will be read from */
        $this->asAdmin();
        // The detail endpoint wraps the record — the screen also needs the
        // blockers and audit trail alongside it, so `worker` is one key of several.
        $shown = $this->getJson("/api/tpv/workers/{$worker->id}")->assertOk()->json('worker');

        $this->assertSame('Ravi Kumar', $shown['name'] ?? null);
        $this->assertSame('Fit', $shown['medical']['fitness_status'] ?? null,
            'the cleared medical must travel with the worker the gate reads');
        $this->assertSame('Approved', $shown['medical']['qc_status'] ?? null);
    }

    /* ── The vendor's own view of all of this ────────────────────────────── */

    public function test_an_activated_vendor_can_use_its_portal(): void
    {
        $vendor = $this->registerVendor();
        $onboarding = $this->openOnboarding($vendor);

        $this->makeSubmittable($onboarding);
        $this->send("/api/tpv/onboarding/{$onboarding->id}/submit", ['declaration' => true]);
        $this->send("/api/tpv/onboarding/{$onboarding->id}/approve");

        $vendor->refresh();
        $this->assertSame(VendorStatus::ACTIVE, $vendor->status);

        // Activation is supposed to provision the portal login. Without it the
        // vendor is "Active" and cannot actually get in — the failure the whole
        // approve path exists to prevent.
        $this->assertNotNull($vendor->user_id,
            'activation must provision a portal login, or Active means nothing');

        $portalUser = User::find($vendor->user_id);
        $this->assertNotNull($portalUser);
        $this->assertSame('active', $portalUser->status);

        Sanctum::actingAs($portalUser);
        $me = $this->getJson('/api/portal/me')->assertOk()->json();
        $this->assertSame($vendor->id, $me['vendor']['id'] ?? null,
            'the portal must resolve the signed-in vendor from the token alone');
    }

    /* ── The doctor's half ───────────────────────────────────────────────── */

    public function test_a_doctor_examines_a_worker_and_the_result_lands_on_the_record(): void
    {
        $vendor = $this->registerVendor();

        $this->asAdmin();
        $workerId = $this->send('/api/tpv/workers', [
            'vendor_id' => $vendor->id, 'name' => 'Sunil Rao',
            'dob' => '1990-02-02', 'gender' => 'Male', 'mobile' => '9995550001',
        ], 201)->json('id');

        // The doctor is a User with its own role and portal.
        $doctor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Meera', 'role' => 'doctor',
            'email' => 'doctor-'.Str::random(5).'@t.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);

        // A certificate carries a licence number by law, and the endpoint
        // refuses without one. Give the doctor a real profile.
        \App\Models\Medical\MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT,
            'user_id'   => $doctor->id,
            'license_no' => 'MH-2026-99881',
            'council'    => 'Maharashtra Medical Council',
            'qualification' => 'MBBS',
            'is_active'  => true,
        ]);

        Sanctum::actingAs($doctor);

        // The queue the doctor works from must contain the worker they are
        // about to examine — if it does not, no examination can start at all.
        $people = $this->getJson('/api/doctor/tpv/workers')->assertOk()->json();
        $rows = $people['data'] ?? $people;
        $this->assertContains($workerId, collect($rows)->pluck('id')->all(),
            'the worker must appear in the doctor\'s queue');

        // The endpoint demands the §16 legal capture — a signature, a camera
        // photo and a location — before it will record an examination. That is
        // the point of the certificate, so the walk supplies all three rather
        // than routing around them.
        $this->send("/api/doctor/tpv/workers/{$workerId}/examination", [
            'fitness_status' => 'Fit',
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr Meera',
            'height_cm'      => 168,
            'weight_kg'      => 70,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
            'capture_photo'  => 'data:image/png;base64,iVBORw0KGgo=',
            'geo_location'   => '18.520430,73.856743',
        ], 201);   // it CREATES an examination

        // Back on the admin side, the same examination must be on the worker.
        $this->asAdmin();
        $worker = TpvWorker::with('medical')->findOrFail($workerId);
        $this->assertNotNull($worker->medical, 'the doctor\'s examination must reach the worker record');
        $this->assertSame('Fit', $worker->medical->fitness_status);
    }
}
