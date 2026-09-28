<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Purchase\PurchaseDocumentStatus;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A vendor may undo and inspect its OWN document work — on both engines.
 *
 * The onboarding wizard has always drawn a Delete button and a History button
 * on the portal. Neither did anything: `portalApi.documents.delete` was a stub
 * that rejected with "Admin only", and `versions` resolved to a hardcoded empty
 * array, so History reported no history however many times the vendor had
 * replaced the file. Purchase's portal client said exactly the same. There were
 * no routes behind either.
 *
 * The consequence was the wrong scan: a vendor who uploaded the wrong page had
 * no way to take it back, and it sat under review until somebody happened to
 * reject it.
 *
 * What must stay true is the boundary. A vendor may remove what has not been
 * approved and read its own history; it may not touch another vendor's, and it
 * may not delete a document an admin has already approved. This test states all
 * four, for TPV and for Purchase, because the two engines are meant to behave
 * identically and only a test makes that a fact rather than an intention.
 */
class VendorOwnsItsDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('vendor_docs');
        Storage::fake('purchase_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    /* ── TPV ─────────────────────────────────────────────────────────────── */

    private function tpvVendor(string $company): array
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $company, 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    private function tpvDoc(Vendor $vendor, string $status = 'Under_Review'): VendorDocument
    {
        Storage::disk('vendor_docs')->put("v{$vendor->id}/gst.pdf", '%PDF-1.4');

        return VendorDocument::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'type' => 'gst',
            'file_path' => "v{$vendor->id}/gst.pdf", 'original_name' => 'gst.pdf',
            'mime' => 'application/pdf', 'size' => 8, 'status' => $status,
        ]);
    }

    public function test_tpv_vendor_removes_its_own_unapproved_document(): void
    {
        [$user, $vendor] = $this->tpvVendor('Acme Contractors');
        $doc = $this->tpvDoc($vendor);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/portal/documents/{$doc->id}")->assertOk();

        // Soft-deleted: the row stays for the audit trail, and the checklist
        // stops returning it, which is what the vendor sees.
        $this->assertSoftDeleted('vendor_documents', ['id' => $doc->id]);
    }

    public function test_tpv_vendor_cannot_remove_an_approved_document(): void
    {
        [$user, $vendor] = $this->tpvVendor('Acme Contractors');
        $doc = $this->tpvDoc($vendor, 'Approved');

        Sanctum::actingAs($user);
        $this->deleteJson("/api/portal/documents/{$doc->id}")->assertStatus(422);

        $this->assertDatabaseHas('vendor_documents', ['id' => $doc->id]);
    }

    public function test_tpv_vendor_cannot_touch_another_vendors_document(): void
    {
        [, $mine] = $this->tpvVendor('Acme Contractors');
        [$rivalUser] = $this->tpvVendor('Rival Ltd');
        $doc = $this->tpvDoc($mine);

        Sanctum::actingAs($rivalUser);
        $this->deleteJson("/api/portal/documents/{$doc->id}")->assertNotFound();
        $this->getJson("/api/portal/documents/{$doc->id}/versions")->assertNotFound();

        $this->assertDatabaseHas('vendor_documents', ['id' => $doc->id]);
    }

    /**
     * Replacing a file archives the one it displaced, and the vendor can read it.
     *
     * This is the assertion the History stub could never fail: it returned []
     * unconditionally, so an empty drawer looked exactly like a document that
     * had never been replaced.
     */
    public function test_tpv_vendor_reads_the_versions_its_own_replacement_created(): void
    {
        [$user, $vendor] = $this->tpvVendor('Acme Contractors');
        $doc = $this->tpvDoc($vendor);

        Sanctum::actingAs($user);
        $this->post("/api/portal/documents/{$doc->id}/resubmit", [
            'file' => UploadedFile::fake()->create('gst-corrected.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $versions = $this->getJson("/api/portal/documents/{$doc->id}/versions")->assertOk()->json();

        // The lineage, newest first: the file now in place, and the one it
        // displaced. What matters is that the displaced file is still reachable
        // - that is the whole point of keeping a history.
        $names = array_column($versions, 'original_name');
        $this->assertContains('gst.pdf', $names, 'the displaced file must still be reachable');
        $this->assertContains('gst-corrected.pdf', $names);
        $this->assertSame('gst-corrected.pdf', $versions[0]['original_name'], 'newest first');
    }

    /* ── Purchase — the same four, on the other engine ───────────────────── */

    private function purchaseVendor(string $company): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'purchase_vendor_code' => 'PV-'.Str::random(8),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
    }

    private function purchaseDoc(PurchaseVendor $vendor, string $status = PurchaseDocumentStatus::UNDER_REVIEW): PurchaseDocument
    {
        Storage::disk('purchase_docs')->put("v{$vendor->id}/gst.pdf", '%PDF-1.4');

        return PurchaseDocument::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'type' => 'gst',
            'file_path' => "v{$vendor->id}/gst.pdf", 'original_name' => 'gst.pdf',
            'mime' => 'application/pdf', 'size' => 8, 'status' => $status,
        ]);
    }

    public function test_purchase_vendor_removes_its_own_unapproved_document(): void
    {
        $vendor = $this->purchaseVendor('Bolt Supplies');
        $doc = $this->purchaseDoc($vendor);

        Sanctum::actingAs($vendor);
        $this->deleteJson("/api/portal/purchase/documents/{$doc->id}")->assertOk();

        $this->assertSoftDeleted('purchase_documents', ['id' => $doc->id]);
    }

    public function test_purchase_vendor_cannot_remove_an_approved_document(): void
    {
        $vendor = $this->purchaseVendor('Bolt Supplies');
        $doc = $this->purchaseDoc($vendor, PurchaseDocumentStatus::APPROVED);

        Sanctum::actingAs($vendor);
        $this->deleteJson("/api/portal/purchase/documents/{$doc->id}")->assertStatus(422);

        $this->assertDatabaseHas('purchase_documents', ['id' => $doc->id]);
    }

    public function test_purchase_vendor_cannot_touch_another_vendors_document(): void
    {
        $mine = $this->purchaseVendor('Bolt Supplies');
        $rival = $this->purchaseVendor('Rival Ltd');
        $doc = $this->purchaseDoc($mine);

        Sanctum::actingAs($rival);
        $this->deleteJson("/api/portal/purchase/documents/{$doc->id}")->assertNotFound();
        $this->getJson("/api/portal/purchase/documents/{$doc->id}/versions")->assertNotFound();

        $this->assertDatabaseHas('purchase_documents', ['id' => $doc->id]);
    }

    public function test_purchase_vendor_reads_the_versions_its_own_replacement_created(): void
    {
        $vendor = $this->purchaseVendor('Bolt Supplies');
        $doc = $this->purchaseDoc($vendor);

        Sanctum::actingAs($vendor);
        $this->post("/api/portal/purchase/documents/{$doc->id}/resubmit", [
            'file' => UploadedFile::fake()->create('gst-corrected.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $versions = $this->getJson("/api/portal/purchase/documents/{$doc->id}/versions")->assertOk()->json();

        $names = array_column($versions, 'original_name');
        $this->assertContains('gst.pdf', $names, 'the displaced file must still be reachable');
        $this->assertContains('gst-corrected.pdf', $names);
        $this->assertSame('gst-corrected.pdf', $versions[0]['original_name'], 'newest first');
    }

    /**
     * Both engines answer the checklist in the shape the shared panel reads.
     *
     * The Purchase admin's Documents tab read this response as an array (or
     * `r.checklist` / `r.data`, neither of which exists) and fell through to an
     * empty list, so the tab drew "No Documents" for every vendor that had ever
     * uploaded anything. Nothing errored — it just silently showed nothing. The
     * shape is the contract; this states it.
     */
    public function test_the_checklist_shape_is_the_same_on_both_engines(): void
    {
        [$tpvUser, $tpvVendor] = $this->tpvVendor('Acme Contractors');
        $this->tpvDoc($tpvVendor);

        Sanctum::actingAs($tpvUser);
        $tpv = $this->getJson('/api/portal/documents')->assertOk()->json();

        $purchaseVendor = $this->purchaseVendor('Bolt Supplies');
        $this->purchaseDoc($purchaseVendor);

        Sanctum::actingAs($purchaseVendor);
        $purchase = $this->getJson('/api/portal/purchase/documents')->assertOk()->json();

        foreach (['required', 'summary', 'complete'] as $key) {
            $this->assertArrayHasKey($key, $tpv, "TPV checklist must carry `{$key}`");
            $this->assertArrayHasKey($key, $purchase, "Purchase checklist must carry `{$key}`");
        }

        // `required` is a LIST of rows, not the response itself — the exact
        // thing the admin tab got wrong.
        $this->assertIsArray($tpv['required']);
        $this->assertIsArray($purchase['required']);
        $this->assertNotEmpty($purchase['required']);

        foreach (['type', 'type_label', 'uploaded', 'status', 'document_id'] as $key) {
            $this->assertArrayHasKey($key, $purchase['required'][0], "each row must carry `{$key}`");
        }
    }
}
