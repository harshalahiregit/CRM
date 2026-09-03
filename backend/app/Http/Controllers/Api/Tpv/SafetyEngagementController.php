<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\SafetyObservation;
use App\Models\Tpv\ToolboxTalk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Proactive safety engagement (Doc_4 Phase 5/6) — behaviour-based observations
 * and toolbox talks. Simple tenant-scoped records; observations can be closed
 * once actioned. A per-tenant summary feeds the leading-indicator counts.
 */
class SafetyEngagementController extends Controller
{
    /* ── Observations ───────────────────────────────────────────────────── */

    public function observations(Request $request)
    {
        $rows = SafetyObservation::where('tenant_id', $request->user()->tenant_id)
            ->with(['vendor:id,company_name', 'observer:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('observed_at')->latest('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeObservation(Request $request)
    {
        $data = $request->validate([
            'category'     => ['required', Rule::in(SafetyObservation::CATEGORIES)],
            'severity'     => ['sometimes', Rule::in(SafetyObservation::SEVERITIES)],
            'vendor_id'    => 'nullable|integer|exists:vendors,id',
            // Purchase vendors live in their own table with unrelated ids, so
            // they need their own column: posting one into vendor_id would not
            // fail, it would attach the record to whichever shared vendor held
            // that number. A safety record filed against the wrong company is
            // worse than one filed against nobody.
            'purchase_vendor_id' => 'nullable|integer|exists:purchase_vendors,id',
            'observed_at'  => 'nullable|date',
            'location'     => 'nullable|string|max:200',
            'description'  => 'required|string',
            'action_taken' => 'nullable|string',
        ]);

        $obs = SafetyObservation::create([
            'tenant_id'    => $request->user()->tenant_id,
            'observed_by'  => $request->user()->id,
            'category'     => $data['category'],
            'severity'     => $data['severity'] ?? 'Low',
            'vendor_id'    => $data['vendor_id'] ?? null,
            'purchase_vendor_id' => $data['purchase_vendor_id'] ?? null,
            'observed_at'  => $data['observed_at'] ?? now(),
            'location'     => $data['location'] ?? null,
            'description'  => $data['description'],
            'action_taken' => $data['action_taken'] ?? null,
            'status'       => 'Open',
        ]);

        return response()->json($obs->fresh(['vendor:id,company_name', 'observer:id,name']), 201);
    }

    public function closeObservation(Request $request, SafetyObservation $observation)
    {
        abort_unless((int) $observation->tenant_id === (int) $request->user()->tenant_id, 404);
        $data = $request->validate(['action_taken' => 'nullable|string']);

        $observation->update([
            'status'       => 'Closed',
            'closed_at'    => now(),
            'action_taken' => $data['action_taken'] ?? $observation->action_taken,
        ]);

        return response()->json($observation->fresh(['vendor:id,company_name', 'observer:id,name']));
    }

    /* ── Toolbox talks ──────────────────────────────────────────────────── */

    public function talks(Request $request)
    {
        $rows = ToolboxTalk::where('tenant_id', $request->user()->tenant_id)
            ->with(['vendor:id,company_name', 'conductor:id,name'])
            ->latest('held_at')->latest('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeTalk(Request $request)
    {
        $data = $request->validate([
            'topic'            => 'required|string|max:200',
            'vendor_id'        => 'nullable|integer|exists:vendors,id',
            // Purchase vendors live in their own table with unrelated ids, so
            // they need their own column: posting one into vendor_id would not
            // fail, it would attach the record to whichever shared vendor held
            // that number. A safety record filed against the wrong company is
            // worse than one filed against nobody.
            'purchase_vendor_id' => 'nullable|integer|exists:purchase_vendors,id',
            'held_at'          => 'nullable|date',
            'location'         => 'nullable|string|max:200',
            'attendee_count'   => 'nullable|integer|min:0|max:5000',
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'notes'            => 'nullable|string',
        ]);

        $talk = ToolboxTalk::create([
            'tenant_id'        => $request->user()->tenant_id,
            'conducted_by'     => $request->user()->id,
            'topic'            => $data['topic'],
            'vendor_id'        => $data['vendor_id'] ?? null,
            'purchase_vendor_id' => $data['purchase_vendor_id'] ?? null,
            'held_at'          => $data['held_at'] ?? now(),
            'location'         => $data['location'] ?? null,
            'attendee_count'   => $data['attendee_count'] ?? 0,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'notes'            => $data['notes'] ?? null,
        ]);

        return response()->json($talk->fresh(['vendor:id,company_name', 'conductor:id,name']), 201);
    }
}
