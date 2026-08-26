<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Vendor\VendorDocumentStatus as Status;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §30 — verify is a distinct step before approve, with its own actor/timestamp,
 * and the single-step approve path still works.
 */
class DocumentVerifyStepTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function doc(): VendorDocument
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return VendorDocument::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'type' => 'GST',
            'file_path' => 'docs/gst.pdf', 'original_name' => 'gst.pdf', 'mime' => 'application/pdf',
            'size' => 1024, 'status' => Status::UNDER_REVIEW,
        ]);
    }

    public function test_verify_sets_a_distinct_state_and_actor(): void
    {
        $doc = $this->doc();
        $verifier = $this->admin();

        $out = app(VendorDocumentService::class)->verify($doc, 'Legible and genuine', $verifier);

        $this->assertSame(Status::VERIFIED, $out->status);
        $this->assertSame($verifier->id, $out->verified_by);
        $this->assertNotNull($out->verified_at);
    }

    public function test_approve_after_verify_records_both_actors(): void
    {
        $doc = $this->doc();
        $verifier = $this->admin();
        $approver = $this->admin();

        app(VendorDocumentService::class)->verify($doc, null, $verifier);
        $out = app(VendorDocumentService::class)->review($doc->fresh(), 'approve', null, $approver);

        $this->assertSame(Status::APPROVED, $out->status);
        $this->assertSame($verifier->id, $out->verified_by);   // verify actor preserved
        $this->assertSame($approver->id, $out->reviewed_by);   // approve actor distinct
    }
}
