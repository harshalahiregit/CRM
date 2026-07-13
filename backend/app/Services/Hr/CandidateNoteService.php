<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Collaborative notes thread for a candidate. Tenant scoping is enforced by the
 * controller (assertTenant); this service owns creation, deletion and the audit
 * trail entries so the timeline reflects team collaboration.
 */
class CandidateNoteService
{
    public function list(HrCandidate $candidate): Collection
    {
        return $candidate->candidateNotes()->get();
    }

    public function add(HrCandidate $candidate, string $body, ?User $actor, bool $visibleToCandidate = false): HrCandidateNote
    {
        $note = $candidate->candidateNotes()->create([
            'tenant_id'            => $candidate->tenant_id,
            'user_id'              => $actor?->id,
            'body'                 => $body,
            'visible_to_candidate' => $visibleToCandidate,
        ]);

        $candidate->recordAudit($visibleToCandidate ? 'Message sent to candidate' : 'Note added', $actor);

        Log::channel('hr')->info('Candidate note added', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'note_id' => $note->id]);

        return $note->load('user');
    }

    public function delete(HrCandidate $candidate, HrCandidateNote $note): void
    {
        $note->delete();

        Log::channel('hr')->info('Candidate note deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'note_id' => $note->id]);
    }
}
