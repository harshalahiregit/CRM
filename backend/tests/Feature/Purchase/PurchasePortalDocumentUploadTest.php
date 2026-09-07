<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseDocumentService;
use App\Support\Purchase\PurchaseDocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Purchase vendor can upload its own statutory documents.
 *
 * It could not. The portal authenticates as a PurchaseVendor — its own model with
 * its own Sanctum token — while `PurchaseDocumentService::upload()` typed its
 * actor as `User`. The controller asserted the caller IS a PurchaseVendor on one
 * line and handed that same object to the `User` parameter on the next, so every
 * upload from the vendor portal was a TypeError: a 500, shown as "Something went
 * wrong on our side", with step 3 of onboarding impossible to complete. It was
 * not intermittent and it was not load — it could never have worked.
 *
 * The fix is the pattern PurchaseOnboardingService already used for exactly this
 * reason: accept either identity, and record in the audit trail which it was.
 *
 * The admin path stays typed `User` where it must — a vendor may not approve or
 * delete its own document, and the signature is what says so. That is asserted
 * here too, because widening a type is only safe if it did not widen a door.
 */
class PurchasePortalDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('purchase_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    /** The first type on the vendor's own required list — a real one, not invented. */
    private function docType(): string
    {
        return PurchaseDocument::requiredFor($this->vendor->vendor_type ?? 'standard')[0];
    }

    private function pdf(string $name = 'gst-certificate.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /* ── the reported failure ────────────────────────────────────────────── */

    /**
     * The exact request that was returning 500 to every Purchase vendor.
     */
    public function test_a_vendor_can_upload_its_own_document(): void
    {
        Sanctum::actingAs($this->vendor);

        $res = $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(),
            'file' => $this->pdf(),
        ])->assertCreated();

        $doc = PurchaseDocument::sole();

        $this->assertSame($this->docType(), $doc->type);
        $this->assertSame((int) $this->vendor->id, (int) $doc->purchase_vendor_id);
        $this->assertSame(PurchaseDocumentStatus::UNDER_REVIEW, $doc->status,
            'a vendor uploads for review — it does not approve its own paperwork');
        $this->assertSame('gst-certificate.pdf', $doc->original_name);
        $this->assertNotNull($res->json('id'));

        Storage::disk('purchase_docs')->assertExists($doc->file_path);
    }

    /**
     * And the audit trail says who — by name, not as an anonymous row.
     *
     * A PurchaseVendor has no `users.id` to record, so writing its id into the
     * actor column would point at whichever unrelated user happened to share the
     * number. It signs as its company instead.
     */
    public function test_the_upload_is_attributed_to_the_vendor(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        $audit = PurchaseDocument::sole()->auditLogs()->first();

        $this->assertNotNull($audit, 'an upload is a thing that happened and must be recorded');
        $this->assertSame('Document Uploaded', $audit->action);
        $this->assertNull($audit->actor_id,
            'a vendor is not a user — its id in a users reference points at an unrelated row');
        $this->assertStringContainsString('Southgate Industrial', (string) $audit->actor_name);
    }

    /** A rejected document can be replaced by the vendor — the same identity path. */
    public function test_a_vendor_can_resubmit_a_rejected_document(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        $doc = PurchaseDocument::sole();
        $doc->forceFill([
            'status' => PurchaseDocumentStatus::REJECTED,
            'remarks' => 'Illegible scan.',
        ])->save();

        $this->post("/api/portal/purchase/documents/{$doc->id}/resubmit", [
            'file' => $this->pdf('gst-certificate-v2.pdf'),
        ])->assertOk();

        $doc->refresh();

        $this->assertSame(PurchaseDocumentStatus::UNDER_REVIEW, $doc->status);
        $this->assertSame('gst-certificate-v2.pdf', $doc->original_name);
        $this->assertNull($doc->remarks, 'the old rejection reason does not survive the new file');
    }

    /** Uploading again replaces the file rather than piling up duplicates. */
    public function test_uploading_the_same_type_twice_replaces_it(): void
    {
        Sanctum::actingAs($this->vendor);

        foreach (['first.pdf', 'second.pdf'] as $name) {
            $this->post('/api/portal/purchase/documents', [
                'type' => $this->docType(), 'file' => $this->pdf($name),
            ])->assertCreated();
        }

        $this->assertSame(1, PurchaseDocument::count());
        $this->assertSame('second.pdf', PurchaseDocument::sole()->original_name);
    }

    /**
     * The checklist's status vocabulary, which the screen is built on.
     *
     * The documents screen keyed its status pill and its admin buttons on the
     * literal `'Pending'`. No such status exists — an uploaded, unreviewed
     * document is `Under_Review` — so a file the vendor had just uploaded was
     * labelled "Not Uploaded", and the admin's approve and reject buttons never
     * rendered at all. Pinning the contract here means the screen and the server
     * cannot drift apart again in silence.
     */
    public function test_the_checklist_reports_the_real_status_and_a_label(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        $row = collect($this->getJson('/api/portal/purchase/documents')->assertOk()->json('required'))
            ->firstWhere('type', $this->docType());

        $this->assertTrue($row['uploaded']);
        $this->assertSame(PurchaseDocumentStatus::UNDER_REVIEW, $row['status'],
            "the screen must key off this exact value — 'Pending' is not a status");
        $this->assertSame('Under Review', $row['status_label'],
            'and a human label travels with it, so an unknown status still reads sensibly');
    }

    /* ── widening the type must not widen the door ───────────────────────── */

    /** An approved document is finished. A vendor cannot quietly swap it. */
    public function test_a_vendor_cannot_replace_an_approved_document(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        PurchaseDocument::sole()->forceFill(['status' => PurchaseDocumentStatus::APPROVED])->save();

        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf('swapped.pdf'),
        ])->assertStatus(422);

        $this->assertSame('gst-certificate.pdf', PurchaseDocument::sole()->original_name);
    }

    /**
     * Review stays an administrator's act.
     *
     * `upload` and `resubmit` now take either identity; `review` deliberately
     * does not, and the type is the guard. If that ever widens, a vendor could
     * approve its own compliance paperwork.
     */
    public function test_review_still_refuses_anything_but_a_real_user(): void
    {
        $review = new \ReflectionMethod(PurchaseDocumentService::class, 'review');
        $actor = collect($review->getParameters())->firstWhere(fn ($p) => $p->getName() === 'actor');

        $this->assertSame(User::class, $actor->getType()->getName(),
            'a vendor must never be able to approve its own document');
    }

    /** And another vendor's document is not the vendor's to resubmit. */
    public function test_one_vendor_cannot_resubmit_anothers_document(): void
    {
        Sanctum::actingAs($this->vendor);
        $this->post('/api/portal/purchase/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();
        $doc = PurchaseDocument::sole();

        $rival = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'rv-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        Sanctum::actingAs($rival);
        $this->post("/api/portal/purchase/documents/{$doc->id}/resubmit", [
            'file' => $this->pdf('theirs.pdf'),
        ])->assertStatus(404);

        $this->assertSame('gst-certificate.pdf', $doc->fresh()->original_name);
    }

    /** An unknown type is refused with a reason, not stored. */
    public function test_an_unknown_document_type_is_refused(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/documents', [
            'type' => 'not_a_real_type', 'file' => $this->pdf(),
        ])->assertStatus(422);

        $this->assertSame(0, PurchaseDocument::count());
    }
}
