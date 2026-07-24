<?php

namespace Tests\Feature\Tpv;

use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Vendor\VendorDocumentStatus as DS;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the additive Step-4 `progress_percent` and that the existing checklist
 * shape is unchanged. Self-provisions only the two tables the service reads, so it
 * is independent of the full migration set.
 */
class DocumentChecklistProgressTest extends TestCase
{
    private VendorDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vendor_documents');
        Schema::dropIfExists('vendors');

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
            $t->string('status')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->service = app(VendorDocumentService::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('vendor_documents');
        Schema::dropIfExists('vendors');
        parent::tearDown();
    }

    private function vendor(): Vendor
    {
        return Vendor::create([
            'tenant_id' => 1, 'vendor_code' => 'VEN-T', 'company_name' => 'T', 'vendor_type' => 'temporary', 'status' => 'Pending_Approval',
        ]);
    }

    private function doc(Vendor $v, string $type, string $status): void
    {
        VendorDocument::create([
            'tenant_id' => 1, 'vendor_id' => $v->id, 'type' => $type,
            'file_path' => "x/{$type}.pdf", 'original_name' => "{$type}.pdf", 'status' => $status,
        ]);
    }

    public function test_progress_is_zero_with_no_approved_docs(): void
    {
        $summary = $this->service->checklist($this->vendor())['summary'];
        $this->assertSame(0, $summary['progress_percent']);
    }

    public function test_progress_rounds_partial_approval(): void
    {
        $v = $this->vendor();
        $required = VendorDocument::requiredFor('temporary'); // 3 types
        $this->doc($v, $required[0], DS::APPROVED);          // 1 of 3 approved

        $summary = $this->service->checklist($v)['summary'];
        $this->assertSame(33, $summary['progress_percent']);
        $this->assertSame(1, $summary['approved']);
    }

    public function test_progress_is_100_and_complete_when_all_approved(): void
    {
        $v = $this->vendor();
        foreach (VendorDocument::requiredFor('temporary') as $type) {
            $this->doc($v, $type, DS::APPROVED);
        }

        $checklist = $this->service->checklist($v);
        $this->assertSame(100, $checklist['summary']['progress_percent']);
        $this->assertTrue($checklist['complete']);
    }

    public function test_existing_summary_shape_is_unchanged(): void
    {
        $summary = $this->service->checklist($this->vendor())['summary'];

        // Backward compatibility — all original keys still present.
        foreach (['required', 'uploaded', 'approved', 'rejected', 'pending'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        $this->assertArrayHasKey('progress_percent', $summary);
    }
}
