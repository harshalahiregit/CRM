<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The TPV half of the same journey: a vendor uploads its own documents.
 *
 * The Purchase portal's upload was a guaranteed 500 — it authenticates as a
 * PurchaseVendor and the service demanded a User. TPV does not have that bug,
 * because its portal authenticates as an ordinary User carrying the
 * `third_party_vendor` role, so the same call is well-typed.
 *
 * That is worth PROVING rather than reasoning about, and worth holding still.
 * Neither engine had a single test covering this endpoint, which is exactly how
 * a step-3 blocker reached a real vendor unnoticed. If TPV's portal identity is
 * ever changed to its own model — the direction Purchase already went — this
 * fails immediately instead of silently repeating the same outage.
 *
 * @see \Tests\Feature\Purchase\PurchasePortalDocumentUploadTest
 */
class TpvPortalDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $vendorUser;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('vendor_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose', 'role' => 'third_party_vendor',
            'email' => 'rita-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Contractors',
            'status' => VendorStatus::ACTIVE, 'user_id' => $this->vendorUser->id,
        ]);
    }

    private function docType(): string
    {
        return VendorDocument::requiredFor($this->vendor->vendor_type ?? 'standard')[0];
    }

    private function pdf(string $name = 'pf-certificate.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_a_vendor_can_upload_its_own_document(): void
    {
        Sanctum::actingAs($this->vendorUser);

        $this->post('/api/portal/documents', [
            'type' => $this->docType(),
            'file' => $this->pdf(),
        ])->assertCreated();

        $doc = VendorDocument::sole();

        $this->assertSame($this->docType(), $doc->type);
        $this->assertSame((int) $this->vendor->id, (int) $doc->vendor_id);
        $this->assertSame('pf-certificate.pdf', $doc->original_name);

        Storage::disk('vendor_docs')->assertExists($doc->file_path);
    }

    /** The TPV portal's identity is an ordinary User — which is why this works. */
    public function test_the_upload_is_attributed_to_the_person_who_made_it(): void
    {
        Sanctum::actingAs($this->vendorUser);

        $this->post('/api/portal/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        $audit = VendorDocument::sole()->auditLogs()->first();

        $this->assertNotNull($audit);
        $this->assertSame((int) $this->vendorUser->id, (int) $audit->actor_id,
            'a TPV vendor IS a user, so the trail carries a real users reference');
    }

    /** An approved document is finished — the vendor cannot swap it. */
    public function test_a_vendor_cannot_replace_an_approved_document(): void
    {
        Sanctum::actingAs($this->vendorUser);

        $this->post('/api/portal/documents', [
            'type' => $this->docType(), 'file' => $this->pdf(),
        ])->assertCreated();

        VendorDocument::sole()->forceFill(['status' => 'Approved'])->save();

        $this->post('/api/portal/documents', [
            'type' => $this->docType(), 'file' => $this->pdf('swapped.pdf'),
        ])->assertStatus(422);

        $this->assertSame('pf-certificate.pdf', VendorDocument::sole()->original_name);
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        Sanctum::actingAs($this->vendorUser);

        $this->post('/api/portal/documents', [
            'type' => 'not_a_real_type', 'file' => $this->pdf(),
        ])->assertStatus(422);

        $this->assertSame(0, VendorDocument::count());
    }
}
