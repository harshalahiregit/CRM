<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrDemoRequest;
use Illuminate\Http\Request;

/**
 * Demo requests: the inbound queue, and what staff do with one.
 *
 * SangoeTrack collects these and the CRM could only read them through a proxy.
 * Everything they store is stored here; the additions are an owner, a scheduled
 * time, and the enquirer's own message kept apart from staff notes — theirs has
 * one `notes` field and the two get written over each other.
 */
class DemoRequestController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|in:' . implode(',', HrDemoRequest::STATUSES),
            'search' => 'nullable|string|max:120',
            'mine'   => 'nullable|boolean',
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        $rows = HrDemoRequest::query()
            // Unclaimed enquiries are everybody's: a request that arrived before
            // anybody assigned it must not be invisible to all of them.
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($data['search'] ?? null, fn ($q, $t) => $q->where(function ($w) use ($t) {
                $w->where('name', 'like', "%{$t}%")
                    ->orWhere('company_name', 'like', "%{$t}%")
                    ->orWhere('email', 'like', "%{$t}%")
                    ->orWhere('phone', 'like', "%{$t}%");
            }))
            ->with(['assignee:id,name'])
            // Anything nobody has answered first.
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $rows,
            'meta'   => ['statuses' => HrDemoRequest::STATUSES],
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['status' => 'success', 'data' => $this->find($request, $id)->load('assignee:id,name')]);
    }

    /** Logging an enquiry that arrived by phone or email. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'company_name'  => 'nullable|string|max:160',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|string|max:40',
            'address'       => 'nullable|string|max:500',
            'num_employees' => 'nullable|integer|min:0',
            'message'       => 'nullable|string|max:2000',
            'source'        => 'nullable|string|max:60',
        ]);

        $row = HrDemoRequest::create($data + [
            'tenant_id' => $request->user()->tenant_id,
            'status'    => 'new',
            'source'    => $data['source'] ?? 'manual',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Demo request logged.', 'data' => $row], 201);
    }

    /**
     * Update what is known about a request.
     *
     * `message` is not editable — it is what the enquirer said, and a record of
     * somebody's own words that staff can rewrite is not a record.
     */
    public function update(Request $request, int $id)
    {
        $row = $this->find($request, $id);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:120',
            'company_name'  => 'nullable|string|max:160',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|string|max:40',
            'address'       => 'nullable|string|max:500',
            'num_employees' => 'nullable|integer|min:0',
            'notes'         => 'nullable|string|max:5000',
            'status'        => 'sometimes|in:' . implode(',', HrDemoRequest::STATUSES),
            'demo_at'       => 'nullable|date',
            'assigned_to'   => 'nullable|integer|exists:users,id',
        ]);

        $row->update($data + [
            'updated_by' => $request->user()->id,
            // Claiming it for this workspace, so an unassigned enquiry stops
            // being everybody's the moment somebody starts working on it.
            'tenant_id'  => $row->tenant_id ?? $request->user()->tenant_id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Updated.',
            'data'    => $row->fresh()->load('assignee:id,name'),
        ]);
    }

    private function find(Request $request, int $id): HrDemoRequest
    {
        $tenantId = (int) $request->user()->tenant_id;

        return HrDemoRequest::where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->findOrFail($id);
    }
}
