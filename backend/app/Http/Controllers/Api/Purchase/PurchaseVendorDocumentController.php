<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\ResubmitVendorDocumentRequest;
use App\Http\Requests\Vendor\ReviewVendorDocumentRequest;
use App\Http\Requests\Vendor\UploadVendorDocumentRequest;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorDocumentVersion;
use App\Services\Vendor\VendorDocumentService;
use App\Services\Vendor\VendorDocumentVersionService;
use Illuminate\Http\Request;

/**
 * Purchase-vendor statutory documents. Reuses the SHARED VendorDocumentService /
 * VendorDocument engine unchanged (private disk, versioning) — only the route
 * surface is Purchase-scoped. Hardened: every method is tenant-guarded AND
 * engagement-guarded (the vendor must be a Purchase vendor), returning 404.
 */
class PurchaseVendorDocumentController extends Controller
{
    public function __construct(
        private VendorDocumentService $documentService,
        private VendorDocumentVersionService $versionService,
    ) {
    }

    public function checklist(Request $request, Vendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        return response()->json($this->documentService->checklist($vendor));
    }

    public function upload(UploadVendorDocumentRequest $request, Vendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        $doc = $this->documentService->upload($vendor, $request->input('type'), $request->file('file'), $request->user());

        return response()->json($doc, 201);
    }

    public function download(Request $request, VendorDocument $document)
    {
        $this->assertDocument($request, $document);

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function resubmit(ResubmitVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertDocument($request, $document);

        return response()->json($this->documentService->resubmit($document, $request->file('file'), $request->user()));
    }

    public function destroy(Request $request, VendorDocument $document)
    {
        $this->assertDocument($request, $document);

        $this->documentService->destroy($document);

        return response()->json(['message' => 'Deleted']);
    }

    public function versions(Request $request, VendorDocument $document)
    {
        $this->assertDocument($request, $document);

        return response()->json($document->versions()->orderByDesc('version_no')->get());
    }

    public function downloadVersion(Request $request, VendorDocument $document, VendorDocumentVersion $version)
    {
        $this->assertDocument($request, $document);
        abort_unless((int) $version->vendor_document_id === (int) $document->id, 404, 'Version not found');

        $file = $this->versionService->resolveDownload($version, $request->user());

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function restoreVersion(Request $request, VendorDocument $document, VendorDocumentVersion $version)
    {
        $this->assertDocument($request, $document);

        return response()->json($this->versionService->restore($document, $version, $request->user()));
    }

    /** Admin approve/reject a document (route group is role:admin). */
    public function review(ReviewVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertDocument($request, $document);

        $doc = $this->documentService->review(
            $document,
            $request->input('decision'),
            $request->input('remarks'),
            $request->user()
        );

        return response()->json($doc);
    }

    /* ── Guards: tenant + Purchase engagement, 404 on any miss ──────────── */

    private function assertVendor(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
        abort_unless($vendor->hasEngagement('purchase'), 404, 'Vendor not found');
    }

    private function assertDocument(Request $request, VendorDocument $document): void
    {
        abort_unless((int) $document->tenant_id === (int) $request->user()->tenant_id, 404, 'Document not found');
        abort_unless($document->vendor && $document->vendor->hasEngagement('purchase'), 404, 'Document not found');
    }
}
