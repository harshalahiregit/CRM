<?php

namespace Tests\Feature\Tpv;

use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorDocumentVersionService;
use App\Support\Vendor\VendorDocumentStatus as DS;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies additive document versioning: capture on every file change, permanent
 * retention of prior versions, and restore → Pending. Self-provisions only the
 * tables it needs + a fake disk, so it is independent of the full migration set.
 */
class DocumentVersioningTest extends TestCase
{
    private VendorDocumentVersionService $versions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vendor_document_versions', 'vendor_documents', 'vendors', 'audit_logs'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('vendor_code')->nullable();
            $t->string('company_name')->nullable();
            $t->string('vendor_type')->default('standard');
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('vendor_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('vendor_id');
            $t->string('type');
            $t->string('file_path')->nullable();
            $t->string('original_name')->nullable();
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('status')->nullable();
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('vendor_document_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('vendor_document_id');
            $t->unsignedInteger('version_no');
            $t->string('file_path');
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('status_at_capture')->nullable();
            $t->unsignedBigInteger('captured_by')->nullable();
            $t->boolean('is_current')->default(false);
            $t->unsignedBigInteger('restored_from_version_id')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('auditable_type');
            $t->unsignedBigInteger('auditable_id');
            $t->string('action');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('actor_role')->nullable();
            $t->text('comment')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Storage::fake('vendor_docs');
        $this->versions = app(VendorDocumentVersionService::class);
    }

    protected function tearDown(): void
    {
        foreach (['vendor_document_versions', 'vendor_documents', 'vendors', 'audit_logs'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function makeDoc(): VendorDocument
    {
        $vendor = Vendor::create(['tenant_id' => 1, 'vendor_code' => 'V1', 'company_name' => 'V', 'vendor_type' => 'temporary', 'status' => 'Pending_Approval']);
        Storage::disk('vendor_docs')->put('working/a.pdf', 'AAA');

        return VendorDocument::create([
            'tenant_id' => 1, 'vendor_id' => $vendor->id, 'type' => 'gst',
            'file_path' => 'working/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 3, 'status' => DS::UNDER_REVIEW,
        ]);
    }

    public function test_first_upload_creates_version_one(): void
    {
        $doc = $this->makeDoc();

        $this->assertSame(1, $doc->versions()->count());
        $v1 = $doc->versions()->first();
        $this->assertSame(1, $v1->version_no);
        $this->assertTrue($v1->is_current);
        $this->assertSame(DS::UNDER_REVIEW, $v1->status_at_capture);
        $this->assertTrue(Storage::disk('vendor_docs')->exists($v1->file_path));
    }

    public function test_replace_creates_new_version_and_keeps_previous(): void
    {
        $doc = $this->makeDoc();
        $v1Path = $doc->versions()->first()->file_path;

        // Simulate a replace: new working file + file_path change (as the service does).
        Storage::disk('vendor_docs')->put('working/b.pdf', 'BBB');
        $doc->update(['file_path' => 'working/b.pdf', 'original_name' => 'b.pdf', 'status' => DS::UNDER_REVIEW]);

        $this->assertSame(2, $doc->versions()->count());
        $this->assertSame(1, $doc->versions()->where('is_current', true)->count());
        $this->assertSame(2, $doc->versions()->where('is_current', true)->first()->version_no);
        // The previous version's immutable copy still exists.
        $this->assertTrue(Storage::disk('vendor_docs')->exists($v1Path));
    }

    public function test_restore_returns_to_pending_and_records_new_version(): void
    {
        $doc = $this->makeDoc();
        Storage::disk('vendor_docs')->put('working/b.pdf', 'BBB');
        $doc->update(['file_path' => 'working/b.pdf', 'original_name' => 'b.pdf', 'status' => DS::APPROVED]);

        $v1 = $doc->versions()->where('version_no', 1)->first();
        $actor = (new \App\Models\User())->forceFill(['id' => 99, 'name' => 'Admin']);
        $restored = $this->versions->restore($doc->fresh(), $v1, $actor);

        $this->assertSame(DS::UNDER_REVIEW, $restored->status);           // back to Pending
        $this->assertSame(3, $restored->versions()->count());              // v3 = restore
        $current = $restored->versions()->where('is_current', true)->first();
        $this->assertSame(3, $current->version_no);
        $this->assertSame($v1->id, $current->restored_from_version_id);
    }

    public function test_audit_events_are_recorded(): void
    {
        $doc = $this->makeDoc();
        Storage::disk('vendor_docs')->put('working/b.pdf', 'BBB');
        $doc->update(['file_path' => 'working/b.pdf', 'original_name' => 'b.pdf']);
        $v1 = $doc->versions()->where('version_no', 1)->first();
        $this->versions->restore($doc->fresh(), $v1, (new \App\Models\User())->forceFill(['id' => 99]));

        $actions = $doc->auditLogs()->pluck('action')->all();
        $this->assertContains('Document Version Created', $actions);
        $this->assertContains('Document Version Restored', $actions);
    }
}
