<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AddLeadNoteRequest;
use App\Http\Requests\Sales\AssignLeadRequest;
use App\Http\Requests\Sales\BulkLeadActionRequest;
use App\Http\Requests\Sales\ConvertLeadRequest;
use App\Http\Requests\Sales\StoreLeadRequest;
use App\Http\Requests\Sales\SubmitQuestionnaireResponseRequest;
use App\Http\Requests\Sales\UpdateLeadRequest;
use App\Http\Requests\Sales\UpdateLeadStatusRequest;
use App\Models\Lead;
use App\Services\Sales\LeadService;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(private LeadService $leadService)
    {
    }

    /* ── List (Table View) ─────────────────────────────────────── */
    public function index(Request $request)
    {
        $leads = $this->leadService->list($request->user()->tenant_id, $request->only([
            'status_id', 'source_id', 'assigned_to', 'temperature', 'search',
            'min_value', 'max_value', 'lost', 'junk', 'sort', 'order',
        ]));

        return response()->json($leads);
    }

    /* ── Kanban View ───────────────────────────────────────────── */
    public function kanban(Request $request)
    {
        return response()->json($this->leadService->kanban($request->user()->tenant_id));
    }

    /* ── Summary / KPIs ────────────────────────────────────────── */
    public function summary(Request $request)
    {
        return response()->json($this->leadService->summary($request->user()->tenant_id));
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(StoreLeadRequest $request)
    {
        $lead = $this->leadService->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($lead, 201);
    }

    /* ── Show ──────────────────────────────────────────────────── */
    public function show(Lead $lead, Request $request)
    {
        return response()->json($this->leadService->show($lead, $request->user()->tenant_id));
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        $updated = $this->leadService->update($lead, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /* ── Delete ────────────────────────────────────────────────── */
    public function destroy(Lead $lead, Request $request)
    {
        $this->leadService->delete($lead, $request->user()->tenant_id);
        return response()->json(['message' => 'Lead deleted']);
    }

    /* ── Change Status ─────────────────────────────────────────── */
    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead)
    {
        $updated = $this->leadService->updateStatus($lead, $request->validated('status_id'), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /* ── Assign Staff ──────────────────────────────────────────── */
    public function assign(AssignLeadRequest $request, Lead $lead)
    {
        $updated = $this->leadService->assign($lead, $request->validated('assigned_to'), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /* ── Mark Lost ─────────────────────────────────────────────── */
    public function markLost(Lead $lead, Request $request)
    {
        $updated = $this->leadService->markLost($lead, $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    /* ── Mark Junk ─────────────────────────────────────────────── */
    public function markJunk(Lead $lead, Request $request)
    {
        $updated = $this->leadService->markJunk($lead, $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    /* ── Restore from Lost/Junk ────────────────────────────────── */
    public function restore(Lead $lead, Request $request)
    {
        $updated = $this->leadService->restore($lead, $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    /* ── Convert to Customer ───────────────────────────────────── */
    public function convert(ConvertLeadRequest $request, Lead $lead)
    {
        $result = $this->leadService->convert($lead, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($result);
    }

    /* ── Add Note ──────────────────────────────────────────────── */
    public function addNote(AddLeadNoteRequest $request, Lead $lead)
    {
        $note = $this->leadService->addNote($lead, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($note, 201);
    }

    /* ── Submit Questionnaire Response ─────────────────────────── */
    public function submitQuestionnaireResponse(SubmitQuestionnaireResponseRequest $request, Lead $lead)
    {
        $response = $this->leadService->submitQuestionnaireResponse($lead, $request->validated(), $request->user()->tenant_id);
        return response()->json($response, 201);
    }

    /* ── Bulk Actions ──────────────────────────────────────────── */
    public function bulkAction(BulkLeadActionRequest $request)
    {
        $count = $this->leadService->bulkAction($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => "Bulk action applied to {$count} leads"]);
    }
}
