<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadNote;
use App\Models\LeadQuestionnaireResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    private function tid(): int
    {
        return auth()->user()->tenant_id;
    }

    /* ── List (Table View) ─────────────────────────────────────── */
    public function index(Request $request)
    {
        $query = Lead::forTenant($this->tid())
                     ->with(['status', 'source', 'assignedUser:id,name']);

        // Filters
        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }
        if ($request->filled('source_id')) {
            $query->where('source_id', $request->source_id);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('temperature')) {
            $query->where('lead_temperature', $request->temperature);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('email', 'like', $s)
                  ->orWhere('phone', 'like', $s)
                  ->orWhere('company', 'like', $s);
            });
        }
        if ($request->filled('min_value')) {
            $query->where('lead_value', '>=', $request->min_value);
        }
        if ($request->filled('max_value')) {
            $query->where('lead_value', '<=', $request->max_value);
        }

        // Lost/Junk filter — by default show only active
        if ($request->boolean('lost')) {
            $query->lost();
        } elseif ($request->boolean('junk')) {
            $query->junk();
        } else {
            $query->active();
        }

        // Sorting
        $sort  = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        return response()->json($query->get());
    }

    /* ── Kanban View ───────────────────────────────────────────── */
    public function kanban(Request $request)
    {
        $statuses = LeadStatus::forTenant($this->tid())
                              ->ordered()
                              ->get();

        $leads = Lead::forTenant($this->tid())
                     ->active()
                     ->with(['source', 'assignedUser:id,name'])
                     ->orderBy('lead_order')
                     ->get();

        $columns = $statuses->map(function ($status) use ($leads) {
            $statusLeads = $leads->where('status_id', $status->id)->values();
            return [
                'id'          => $status->id,
                'name'        => $status->name,
                'color'       => $status->color,
                'is_won'      => $status->is_won_status,
                'leads'       => $statusLeads,
                'count'       => $statusLeads->count(),
                'total_value' => $statusLeads->sum('lead_value'),
            ];
        });

        return response()->json($columns);
    }

    /* ── Summary / KPIs ────────────────────────────────────────── */
    public function summary()
    {
        $tid   = $this->tid();
        $leads = Lead::forTenant($tid);

        $total     = (clone $leads)->count();
        $active    = (clone $leads)->active()->count();
        $hot       = (clone $leads)->active()->where('lead_temperature', 'Hot')->count();
        $warm      = (clone $leads)->active()->where('lead_temperature', 'Warm')->count();
        $cold      = (clone $leads)->active()->where('lead_temperature', 'Cold')->count();
        $converted = (clone $leads)->converted()->count();
        $lost      = (clone $leads)->lost()->count();
        $pipeline  = (clone $leads)->active()->sum('lead_value');

        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

        // This month
        $thisMonth      = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
        $thisMonthValue = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('lead_value');

        // By status
        $byStatus = LeadStatus::forTenant($tid)->ordered()->get()->map(function ($s) use ($tid) {
            $count = Lead::forTenant($tid)->active()->where('status_id', $s->id)->count();
            $value = Lead::forTenant($tid)->active()->where('status_id', $s->id)->sum('lead_value');
            return [
                'name'  => $s->name,
                'color' => $s->color,
                'count' => $count,
                'value' => $value,
            ];
        });

        // By source
        $bySource = Lead::forTenant($tid)->active()
            ->selectRaw('source_id, count(*) as count')
            ->groupBy('source_id')
            ->with('source:id,name')
            ->get()
            ->map(fn($r) => [
                'name'  => $r->source->name ?? 'Unknown',
                'count' => $r->count,
            ]);

        return response()->json([
            'total'           => $total,
            'active'          => $active,
            'hot'             => $hot,
            'warm'            => $warm,
            'cold'            => $cold,
            'converted'       => $converted,
            'lost'            => $lost,
            'pipeline_value'  => $pipeline,
            'conversion_rate' => $conversionRate,
            'this_month'      => $thisMonth,
            'this_month_value'=> $thisMonthValue,
            'by_status'       => $byStatus,
            'by_source'       => $bySource,
        ]);
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'title'             => 'nullable|string|max:255',
            'company'           => 'nullable|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'website'           => 'nullable|string|max:500',
            'description'       => 'nullable|string',
            'lead_value'        => 'nullable|numeric|min:0',
            'conversion_chance' => 'nullable|integer|min:0|max:100',
            'country'           => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'city'              => 'nullable|string|max:100',
            'zip'               => 'nullable|string|max:20',
            'address'           => 'nullable|string',
            'status_id'         => 'nullable|exists:lead_statuses,id',
            'source_id'         => 'nullable|exists:lead_sources,id',
            'assigned_to'       => 'nullable|exists:users,id',
            'is_public'         => 'nullable|boolean',
            'referral_type'     => 'nullable|in:none,percentage,fixed',
            'referral_value'    => 'nullable|numeric|min:0',
            'referral_contact'  => 'nullable|string|max:255',
            'tags'              => 'nullable|string',
        ]);

        $lead = Lead::create([
            ...$validated,
            'tenant_id'  => $this->tid(),
            'created_by' => auth()->id(),
            'hash'       => Str::random(32),
        ]);

        // Auto-assign date if assigned_to is set
        if (!empty($validated['assigned_to'])) {
            $lead->update(['date_assigned' => now()]);
        }

        $lead->logActivity('created', "Lead \"{$lead->name}\" created");

        return response()->json($lead->load(['status', 'source', 'assignedUser:id,name']), 201);
    }

    /* ── Show ──────────────────────────────────────────────────── */
    public function show(Lead $lead)
    {
        $this->authorize_lead($lead);

        return response()->json(
            $lead->load([
                'status', 'source',
                'assignedUser:id,name',
                'creator:id,name',
                'notes.creator:id,name',
                'activities.performer:id,name',
                'proposals',
                'questionnaireResponses.questionnaire',
            ])
        );
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'title'             => 'nullable|string|max:255',
            'company'           => 'nullable|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'website'           => 'nullable|string|max:500',
            'description'       => 'nullable|string',
            'lead_value'        => 'nullable|numeric|min:0',
            'conversion_chance' => 'nullable|integer|min:0|max:100',
            'country'           => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'city'              => 'nullable|string|max:100',
            'zip'               => 'nullable|string|max:20',
            'address'           => 'nullable|string',
            'source_id'         => 'nullable|exists:lead_sources,id',
            'is_public'         => 'nullable|boolean',
            'referral_type'     => 'nullable|in:none,percentage,fixed',
            'referral_value'    => 'nullable|numeric|min:0',
            'referral_contact'  => 'nullable|string|max:255',
            'tags'              => 'nullable|string',
            'last_contact_date' => 'nullable|date',
        ]);

        $lead->update($validated);
        $lead->logActivity('updated', "Lead \"{$lead->name}\" updated");

        return response()->json($lead->fresh()->load(['status', 'source', 'assignedUser:id,name']));
    }

    /* ── Delete ────────────────────────────────────────────────── */
    public function destroy(Lead $lead)
    {
        $this->authorize_lead($lead);
        $lead->delete();
        return response()->json(['message' => 'Lead deleted']);
    }

    /* ── Change Status ─────────────────────────────────────────── */
    public function updateStatus(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);
        $request->validate(['status_id' => 'required|exists:lead_statuses,id']);

        $oldStatus = $lead->status->name ?? 'None';
        $lead->update(['status_id' => $request->status_id]);
        $newStatus = $lead->fresh()->status->name ?? 'None';

        $lead->logActivity('status_changed', "Status changed from {$oldStatus} to {$newStatus}", $oldStatus, $newStatus);

        return response()->json($lead->fresh()->load(['status', 'source', 'assignedUser:id,name']));
    }

    /* ── Assign Staff ──────────────────────────────────────────── */
    public function assign(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);
        $request->validate(['assigned_to' => 'required|exists:users,id']);

        $lead->update([
            'assigned_to'   => $request->assigned_to,
            'date_assigned' => now(),
        ]);

        $assignee = $lead->fresh()->assignedUser;
        $lead->logActivity('assigned', "Lead assigned to {$assignee->name}", null, $assignee->name);

        return response()->json($lead->fresh()->load(['status', 'source', 'assignedUser:id,name']));
    }

    /* ── Mark Lost ─────────────────────────────────────────────── */
    public function markLost(Lead $lead)
    {
        $this->authorize_lead($lead);
        $lead->markAsLost(auth()->id());
        return response()->json($lead->fresh()->load(['status', 'source']));
    }

    /* ── Mark Junk ─────────────────────────────────────────────── */
    public function markJunk(Lead $lead)
    {
        $this->authorize_lead($lead);
        $lead->markAsJunk(auth()->id());
        return response()->json($lead->fresh()->load(['status', 'source']));
    }

    /* ── Restore from Lost/Junk ────────────────────────────────── */
    public function restore(Lead $lead)
    {
        $this->authorize_lead($lead);
        $lead->restoreFromLostJunk(auth()->id());
        return response()->json($lead->fresh()->load(['status', 'source']));
    }

    /* ── Convert to Customer ───────────────────────────────────── */
    public function convert(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);

        if ($lead->date_converted) {
            return response()->json(['error' => 'Lead already converted'], 422);
        }

        $request->validate([
            'company'   => 'nullable|string|max:255',
            'firstname' => 'nullable|string|max:255',
            'lastname'  => 'nullable|string|max:255',
            'email'     => 'nullable|email|max:255',
            'phone'     => 'nullable|string|max:50',
        ]);

        DB::beginTransaction();
        try {
            // Get the "Won" status
            $wonStatus = LeadStatus::getWonStatus($this->tid());

            // Update lead as converted
            $lead->update([
                'status_id'      => $wonStatus?->id ?? $lead->status_id,
                'date_converted' => now(),
                'lost'           => false,
                'junk'           => false,
            ]);

            // Move linked proposals from lead → customer context
            $lead->proposals()->update([
                'rel_type' => 'customer',
            ]);

            // Update goals achievement
            $this->updateGoalAchievement($lead);

            $lead->logActivity('converted', "Lead converted to customer", null, $request->company ?? $lead->company);

            DB::commit();

            return response()->json([
                'message' => 'Lead converted successfully',
                'lead'    => $lead->fresh()->load(['status', 'source']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ── Add Note ──────────────────────────────────────────────── */
    public function addNote(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);

        $request->validate([
            'content'      => 'required|string',
            'contact_date' => 'nullable|date',
        ]);

        $note = LeadNote::create([
            'tenant_id'    => $this->tid(),
            'lead_id'      => $lead->id,
            'content'      => $request->content,
            'contact_date' => $request->contact_date,
            'created_by'   => auth()->id(),
        ]);

        // Update last contact date if contact_date is provided
        if ($request->filled('contact_date')) {
            $lead->update(['last_contact_date' => $request->contact_date]);
        }

        $lead->logActivity('note_added', 'Note added to lead');

        return response()->json($note->load('creator:id,name'), 201);
    }

    /* ── Submit Questionnaire Response ─────────────────────────── */
    public function submitQuestionnaireResponse(Request $request, Lead $lead)
    {
        $this->authorize_lead($lead);

        $request->validate([
            'questionnaire_id' => 'required|exists:lead_questionnaires,id',
            'answers'          => 'required|array',
        ]);

        $response = LeadQuestionnaireResponse::updateOrCreate(
            [
                'tenant_id'        => $this->tid(),
                'questionnaire_id' => $request->questionnaire_id,
                'lead_id'          => $lead->id,
            ],
            [
                'answers'      => $request->answers,
                'submitted_at' => now(),
            ]
        );

        $lead->logActivity('questionnaire_submitted', 'Questionnaire response submitted');

        return response()->json($response, 201);
    }

    /* ── Bulk Actions ──────────────────────────────────────────── */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action'  => 'required|in:delete,status,assign,lost,junk,source',
            'lead_ids'=> 'required|array|min:1',
            'lead_ids.*' => 'integer',
            'value'   => 'nullable',
        ]);

        $leads = Lead::forTenant($this->tid())
                      ->whereIn('id', $request->lead_ids)
                      ->get();

        $count = 0;

        foreach ($leads as $lead) {
            switch ($request->action) {
                case 'delete':
                    $lead->delete();
                    break;
                case 'status':
                    $lead->update(['status_id' => $request->value]);
                    $lead->logActivity('status_changed', 'Status changed via bulk action');
                    break;
                case 'assign':
                    $lead->update(['assigned_to' => $request->value, 'date_assigned' => now()]);
                    $lead->logActivity('assigned', 'Assigned via bulk action');
                    break;
                case 'lost':
                    $lead->markAsLost(auth()->id());
                    break;
                case 'junk':
                    $lead->markAsJunk(auth()->id());
                    break;
                case 'source':
                    $lead->update(['source_id' => $request->value]);
                    break;
            }
            $count++;
        }

        return response()->json(['message' => "Bulk action applied to {$count} leads"]);
    }

    /* ── Private Helpers ───────────────────────────────────────── */
    private function authorize_lead(Lead $lead): void
    {
        if ($lead->tenant_id !== $this->tid()) {
            abort(403, 'Unauthorized');
        }
    }

    private function updateGoalAchievement(Lead $lead): void
    {
        $goals = \App\Models\LeadGoal::forTenant($this->tid())
            ->active()
            ->where(function ($q) use ($lead) {
                $q->whereNull('user_id')                           // team-wide goal
                  ->orWhere('user_id', $lead->assigned_to)         // assigned user's goal
                  ->orWhere('user_id', $lead->created_by);         // creator's goal
            })
            ->get();

        foreach ($goals as $goal) {
            $goal->increment('achieved_count');
            $goal->increment('achieved_value', $lead->lead_value);
        }
    }
}
