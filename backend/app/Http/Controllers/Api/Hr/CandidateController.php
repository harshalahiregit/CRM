<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AssignRecruiterRequest;
use App\Http\Requests\Hr\LinkedinParseRequest;
use App\Http\Requests\Hr\StoreCandidateRequest;
use App\Http\Requests\Hr\UpdateCandidateDecisionRequest;
use App\Http\Requests\Hr\UpdateCandidateStageRequest;
use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\HrCandidate;
use App\Services\Hr\CandidateService;
use App\Services\Hr\CommunicationService;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    public function __construct(
        private CandidateService $candidateService,
        private CommunicationService $communicationService,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->candidateService->list($request->user()->tenant_id, $request->only(['stage', 'job_posting_id', 'source', 'search']))
        );
    }

    public function store(StoreCandidateRequest $request)
    {
        $this->assertCanManage($request);

        $candidate = $this->candidateService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($candidate, 201);
    }

    public function show(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json(
            $candidate->load([
                'jobPosting', 'interviewRounds', 'offer', 'onboarding',
                // onboarding.documents drives the Candidate 360° onboarding checklist,
                // which is derived from verified documents rather than read from the
                // stored document_checklist map (that map is not the source of truth).
                'onboarding.documents',
                // Employee Status (SPK-1) — present once the candidate has joined.
                'employee',
                'assignedRecruiter:id,name,internal_role', 'candidateNotes', 'documents',
                'auditLogs.actor',
            ])
        );
    }

    /**
     * The single AI score endpoint. Every surface that shows a score, a band or a
     * confidence figure reads THIS -- there are no thresholds or labels in the
     * frontend any more.
     *
     * Serves the stored AIR row rather than re-scoring: scoring happens on the queue
     * via RecalculateCandidateScore, so a page load must never trigger a computation.
     * A candidate with no row yet is "not scored", which is a real state (the
     * confidence floor withholds thin scores) and not an error.
     */
    public function score(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json(AirCandidateScore::payloadFor($candidate));
    }

    /** Complete recruitment journey for one candidate (read-only aggregation). */
    public function journey(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json($this->candidateService->journey($candidate));
    }

    /* ─────────────── Communication Center (SPK-1) ─────────────── */

    /** Unified communication history + delivery status. */
    public function communications(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json($this->communicationService->history($candidate));
    }

    /** Preview an email/WhatsApp for a stage event (variables auto-replaced). */
    public function communicationPreview(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $data = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'event'   => 'required|string',
        ]);

        return response()->json($this->communicationService->preview($candidate, $data['channel'], $data['event']));
    }

    /** Send an edited email/WhatsApp — reuses Mail / WhatsAppService + audit. */
    public function communicate(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);
        $data = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'event'   => 'required|string',
            'subject' => 'nullable|string|max:255',
            'body'    => 'required|string',
        ]);

        return response()->json($this->communicationService->send(
            $candidate, $data['channel'], $data['event'], $data['subject'] ?? null, $data['body'], $request->user()
        ));
    }

    /** Configure a reminder (interview reuses the existing scheduler). */
    public function scheduleReminder(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);
        $data = $request->validate([
            'type' => 'required|in:interview,offer,joining',
            'days' => 'required|integer|min:0|max:60',
        ]);

        return response()->json($this->communicationService->scheduleReminder($candidate, $data['type'], $data['days'], $request->user()));
    }

    public function update(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->update($candidate, $request->all());

        return response()->json($updated);
    }

    public function updateStage(UpdateCandidateStageRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->updateStage($candidate, $request->validated('stage'));

        return response()->json($updated);
    }

    public function updateDecision(UpdateCandidateDecisionRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->updateDecision($candidate, $request->validated('final_decision'));

        return response()->json($updated);
    }

    public function destroy(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $this->candidateService->destroy($candidate);

        return response()->json(['message' => 'Deleted']);
    }

    public function assign(AssignRecruiterRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->assignRecruiter($candidate, $request->validated('recruiter_id'));

        return response()->json($updated);
    }

    /** Assignable recruiters (internal, active users) in the caller's tenant. */
    public function recruiters(Request $request)
    {
        return response()->json(
            $this->candidateService->assignableRecruiters($request->user()->tenant_id)
        );
    }

    // ── LinkedIn Profile Extractor ─────────────────────────────────────
    public function linkedinParse(LinkedinParseRequest $request)
    {
        return response()->json($this->candidateService->linkedinParse($request->validated('url')));
    }

    /**
     * Tenant guard for route-model-bound actions. Implicit binding resolves by
     * global id, so this ensures a user only ever touches their own tenant's
     * candidates (row-level isolation).
     */
    private function assertTenant(Request $request, HrCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $request->user()->tenant_id, 404, 'Candidate not found');
    }

    /** Only HR-queue managers may mutate candidates. */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage candidates');
    }
}
