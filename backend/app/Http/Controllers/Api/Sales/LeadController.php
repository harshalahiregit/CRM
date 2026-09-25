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
use App\Models\Sales\Lead;
use App\Services\Auth\StaffPermissionService;
use App\Services\Sales\LeadService;
use App\Support\Hr\StaffPermission;
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
        $loaded = $this->leadService->show($lead, $request->user()->tenant_id);

        // can_edit rides on the payload so the screen can hide a control it would
        // only be refused for pressing. The same rule decides both, one line
        // below, so the button and the gate cannot drift apart.
        return response()->json([
            ...$loaded->toArray(),
            'can_edit' => $this->canEdit($request->user()),
        ]);
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        abort_unless($this->canEdit($request->user()), 403, 'You do not have permission to edit leads.');

        $updated = $this->leadService->update($lead, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /**
     * May this user edit a lead?
     *
     * Leads are the FIRST module to read the staff permission grid. The grid has
     * been stored in users.meta.permissions since Staff Management shipped and
     * has never been consulted by anything. Nothing new had to be built for this:
     * an admin already has the tick box — Deals → edit, StaffModal.jsx — it
     * simply had nobody asking about it.
     *
     * ── WHY AN EMPTY GRID MEANS YES ──────────────────────────────────────────
     * Every account in this system currently has an empty grid, because nothing
     * read it and so nobody filled it in. Enforcing the grid literally would take
     * lead editing away from everyone at once, on the strength of boxes nobody
     * knew were load-bearing — precisely the lockout StaffPermissionService
     * warns about in its own docblock, and the reason it shipped uncalled.
     *
     * So an entirely empty grid is read as "nobody has ever expressed an opinion
     * about this person", and permits. Today's behaviour is preserved exactly.
     * The moment an admin configures ANY module for them, the grid becomes the
     * authority and Deals → edit must be granted explicitly.
     *
     * The test is deliberately the WHOLE grid, not the deals key. Were it
     * per-module, an admin who set someone up for HR alone would silently hand
     * them the sales pipeline too — a grant nobody made, which is the failure
     * mode this check exists to prevent.
     */
    private function canEdit($user): bool
    {
        $permissions = app(StaffPermissionService::class);

        if ($permissions->grantsFor($user) === []) {
            return true;
        }

        return $permissions->can($user, StaffPermission::EDIT, 'deals');
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
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $updated = $this->leadService->markLost($lead, $request->user()->tenant_id, $request->user()->id, $data['reason'] ?? null);
        return response()->json($updated);
    }

    /* ── Mark Junk ─────────────────────────────────────────────── */
    public function markJunk(Lead $lead, Request $request)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $updated = $this->leadService->markJunk($lead, $request->user()->tenant_id, $request->user()->id, $data['reason'] ?? null);
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
