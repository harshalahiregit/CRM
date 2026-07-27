<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\ResubmitPurchaseDocumentRequest;
use App\Http\Requests\Purchase\ReviewPurchaseDocumentRequest;
use App\Http\Requests\Purchase\UploadPurchaseDocumentRequest;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseDocumentVersion;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseDocumentService;
use App\Services\Purchase\PurchaseDocumentVersionService;
use Illuminate\Http\Request;

/**
 * Purchase-vendor statutory documents. Backed by the Purchase-owned document
 * engine (purchase_documents / purchase_document_versions, private `purchase_docs`
 * disk) — fully independent of the shared Vendor Master docs and of TPV. Every
 * method is tenant-guarded AND engagement-guarded (the vendor must be a Purchase
 * vendor), returning 404.
 */
class PurchaseVendorDocumentController extends Controller
{
    public function __construct(
        private PurchaseDocumentService $documentService,
        private PurchaseDocumentVersionService $versionService,
    ) {
    }

    public function checklist(Request $request, PurchaseVendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        return response()->json($this->documentService->checklist($vendor));
    }

    public function upload(UploadPurchaseDocumentRequest $request, PurchaseVendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        $doc = $this->documentService->upload($vendor, $request->input('type'), $request->file('file'), $request->user());

        return response()->json($doc, 201);
    }

    public function download(Request $request, PurchaseDocument $document)
    {
        $this->assertDocument($request, $document);

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function resubmit(ResubmitPurchaseDocumentRequest $request, PurchaseDocument $document)
    {
        $this->assertDocument($request, $document);

        return response()->json($this->documentService->resubmit($document, $request->file('file'), $request->user()));
    }

    public function destroy(Request $request, PurchaseDocument $document)
    {
        $this->assertDocument($request, $document);

        $this->documentService->destroy($document);

        return response()->json(['message' => 'Deleted']);
    }

    public function versions(Request $request, PurchaseDocument $document)
    {
        $this->assertDocument($request, $document);

        return response()->json($document->versions()->orderByDesc('version_no')->get());
    }

    public function downloadVersion(Request $request, PurchaseDocument $document, PurchaseDocumentVersion $version)
    {
        $this->assertDocument($request, $document);
        abort_unless((int) $version->purchase_document_id === (int) $document->id, 404, 'Version not found');

        $file = $this->versionService->resolveDownload($version, $request->user());

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function restoreVersion(Request $request, PurchaseDocument $document, PurchaseDocumentVersion $version)
    {
        $this->assertDocument($request, $document);

        return response()->json($this->versionService->restore($document, $version, $request->user()));
    }

    /** Admin approve/reject a document (route group is role:admin). */
    public function review(ReviewPurchaseDocumentRequest $request, PurchaseDocument $document)
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

    /* ── Guards: tenant-scoped, 404 on any miss ─────────────────────────── */

    private function assertVendor(Request $request, PurchaseVendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Purchase vendor not found');
    }

    private function assertDocument(Request $request, PurchaseDocument $document): void
    {
        abort_unless((int) $document->tenant_id === (int) $request->user()->tenant_id, 404, 'Document not found');
    }
}
