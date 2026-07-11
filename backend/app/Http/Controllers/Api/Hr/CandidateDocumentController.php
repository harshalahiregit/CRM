<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreCandidateDocumentRequest;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateDocument;
use App\Services\Hr\CandidateDocumentService;
use Illuminate\Http\Request;

class CandidateDocumentController extends Controller
{
    public function __construct(private CandidateDocumentService $documentService)
    {
    }

    public function index(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json($this->documentService->list($candidate));
    }

    public function store(StoreCandidateDocumentRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $document = $this->documentService->upload(
            $candidate,
            $request->file('document'),
            $request->input('type', 'other'),
            $request->user()
        );

        return response()->json($document, 201);
    }

    public function download(Request $request, HrCandidate $candidate, HrCandidateDocument $document)
    {
        $this->assertTenant($request, $candidate);
        $this->assertDocumentBelongs($candidate, $document);

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function destroy(Request $request, HrCandidate $candidate, HrCandidateDocument $document)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);
        $this->assertDocumentBelongs($candidate, $document);

        $this->documentService->delete($candidate, $document);

        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(Request $request, HrCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $request->user()->tenant_id, 404, 'Candidate not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage candidates');
    }

    private function assertDocumentBelongs(HrCandidate $candidate, HrCandidateDocument $document): void
    {
        abort_unless((int) $document->candidate_id === (int) $candidate->id, 404, 'Document not found');
    }
}
