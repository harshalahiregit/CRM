<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreCandidateNoteRequest;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateNote;
use App\Services\Hr\CandidateNoteService;
use Illuminate\Http\Request;

class CandidateNoteController extends Controller
{
    public function __construct(private CandidateNoteService $noteService)
    {
    }

    public function index(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json($this->noteService->list($candidate));
    }

    public function store(StoreCandidateNoteRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $note = $this->noteService->add($candidate, $request->validated('body'), $request->user());

        return response()->json($note, 201);
    }

    public function destroy(Request $request, HrCandidate $candidate, HrCandidateNote $note)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);
        abort_unless((int) $note->candidate_id === (int) $candidate->id, 404, 'Note not found');

        $this->noteService->delete($candidate, $note);

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
}
