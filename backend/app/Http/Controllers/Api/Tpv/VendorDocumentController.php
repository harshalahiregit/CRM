<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\ResubmitVendorDocumentRequest;
use App\Http\Requests\Vendor\ReviewVendorDocumentRequest;
use App\Http\Requests\Vendor\UploadVendorDocumentRequest;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorDocumentService;
use Illuminate\Http\Request;

class VendorDocumentController extends Controller
{
    public function __construct(private VendorDocumentService $documentService)
    {
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
