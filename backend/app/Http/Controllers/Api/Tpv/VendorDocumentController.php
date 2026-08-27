<?php

namespace App\Http\Controllers\Api\Tpv;

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

class VendorDocumentController extends Controller
{
    public function __construct(
        private VendorDocumentService $documentService,
        private VendorDocumentVersionService $versionService,
    ) {
    }

    /** Full version history for a document (newest first). */
    public function versions(Request $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        return response()->json($document->versions()->orderByDesc('version_no')->get());
    }

    /** Download a specific previous version (audited). */
    public function downloadVersion(Request $request, VendorDocument $document, VendorDocumentVersion $version)
    {
        $this->assertDocumentTenant($request, $document);
        abort_unless((int) $version->vendor_document_id === (int) $document->id, 404, 'Version not found');

        $file = $this->versionService->resolveDownload($version, $request->user());

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    /** Restore a previous version — moves the document back to Pending review. */
    public function restoreVersion(Request $request, VendorDocument $document, VendorDocumentVersion $version)
    {
        $this->assertDocumentTenant($request, $document);

        return response()->json($this->versionService->restore($document, $version, $request->user()));
    }

    /** The required-vs-uploaded validation matrix for a vendor. */
    public function checklist(Request $request, Vendor $vendor)
    {
        $this->assertVendorTenant($request, $vendor);

        return response()->json($this->documentService->checklist($vendor));
    }

    public function upload(UploadVendorDocumentRequest $request, Vendor $vendor)
    {
        $this->assertVendorTenant($request, $vendor);

        $doc = $this->documentService->upload($vendor, $request->input('type'), $request->file('file'), $request->user());

        return response()->json($doc, 201);
    }

    public function download(Request $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    /** Admin approve/reject a document. */
    public function review(ReviewVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        $doc = $this->documentService->review(
            $document,
            $request->input('decision'),
            $request->input('remarks'),
            $request->user()
        );

        return response()->json($doc);
    }

    /** §30 — verify a document (distinct intermediate step before approval). */
    public function verify(Request $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        return response()->json(
            $this->documentService->verify($document, $data['remarks'] ?? null, $request->user())
        );
    }

    public function resubmit(ResubmitVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        $doc = $this->documentService->resubmit($document, $request->file('file'), $request->user());

        return response()->json($doc);
    }

    public function destroy(Request $request, VendorDocument $document)
    {
        $this->assertDocumentTenant($request, $document);

        $this->documentService->destroy($document);

        return response()->json(['message' => 'Deleted']);
    }

    private function assertVendorTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }

    private function assertDocumentTenant(Request $request, VendorDocument $document): void
    {
        abort_unless((int) $document->tenant_id === (int) $request->user()->tenant_id, 404, 'Document not found');
    }
}
