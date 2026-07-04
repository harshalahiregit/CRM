<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\SalesLineItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProposalController extends Controller
{
    private function tid(): int
    {
        return auth()->user()->tenant_id;
    }

    public function index(Request $request)
    {
        $query = Proposal::forTenant($this->tid())->with(['lineItems', 'assignedUser']);

        if ($request->filled('status') && $request->status !== 'All') {
            $query->ofStatus($request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('subject', 'like', '%' . $request->search . '%')
                  ->orWhere('proposal_to', 'like', '%' . $request->search . '%');
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'       => 'required|string|max:255',
            'rel_type'      => 'nullable|in:lead,customer',
            'rel_id'        => 'nullable|integer',
            'project_id'    => 'nullable|integer',
            'date'          => 'required|date',
            'open_till'     => 'nullable|date',
            'currency'      => 'nullable|string|size:3',
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'status'        => 'nullable|in:Draft,Open,Sent,Accepted,Declined,Expired',
            'assigned_to'   => 'nullable|exists:users,id',
            'proposal_to'   => 'nullable|string|max:255',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string',
            'state'         => 'nullable|string',
            'country'       => 'nullable|string',
            'zip'           => 'nullable|string',
            'email'         => 'nullable|email',
            'phone'         => 'nullable|string',
            'allow_comments'=> 'nullable|boolean',
            'tags'          => 'nullable|string',
            'notes'         => 'nullable|string',
            'line_items'    => 'nullable|array',
            'line_items.*.item_name'   => 'required_with:line_items|string',
            'line_items.*.qty'         => 'required_with:line_items|numeric|min:0',
            'line_items.*.rate'        => 'required_with:line_items|numeric|min:0',
            'line_items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'line_items.*.discount'    => 'nullable|numeric|min:0',
            'line_items.*.unit'        => 'nullable|string',
            'line_items.*.description' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $proposal = Proposal::create([
                ...$validated,
                'tenant_id'  => $this->tid(),
                'created_by' => auth()->id(),
                'status'     => $validated['status'] ?? 'Draft',
            ]);

            $this->syncLineItems($proposal, $request->input('line_items', []));
            $proposal->recalcTotals();

            DB::commit();
            return response()->json($proposal->load('lineItems'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Proposal $proposal)
    {
        $this->authorize($proposal);
        return response()->json($proposal->load(['lineItems', 'assignedUser']));
    }

    public function update(Request $request, Proposal $proposal)
    {
        $this->authorize($proposal);

        $validated = $request->validate([
            'subject'       => 'sometimes|string|max:255',
            'date'          => 'sometimes|date',
            'open_till'     => 'nullable|date',
            'status'        => 'sometimes|in:Draft,Open,Sent,Accepted,Declined,Expired',
            'notes'         => 'nullable|string',
            'tags'          => 'nullable|string',
            'allow_comments'=> 'nullable|boolean',
            'line_items'    => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $proposal->update($validated);

            if ($request->has('line_items')) {
                $this->syncLineItems($proposal, $request->input('line_items', []));
                $proposal->recalcTotals();
            }

            DB::commit();
            return response()->json($proposal->fresh()->load('lineItems'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Proposal $proposal)
    {
        $this->authorize($proposal);
        $proposal->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function send(Proposal $proposal)
    {
        $this->authorize($proposal);
        $proposal->update(['status' => 'Sent', 'sent_at' => now()]);
        return response()->json($proposal->fresh());
    }

    public function updateStatus(Request $request, Proposal $proposal)
    {
        $this->authorize($proposal);
        $request->validate(['status' => 'required|in:Draft,Open,Sent,Accepted,Declined,Expired']);

        $data = ['status' => $request->status];
        if ($request->status === 'Accepted') $data['accepted_at'] = now();
        if ($request->status === 'Declined') $data['declined_at'] = now();

        $proposal->update($data);
        return response()->json($proposal->fresh());
    }

    /* ── Private helpers ─────────────────────────────────── */
    private function authorize(Proposal $proposal): void
    {
        if ($proposal->tenant_id !== $this->tid()) {
            abort(403, 'Unauthorized');
        }
    }

    private function syncLineItems(Proposal $proposal, array $items): void
    {
        // Delete all existing items then re-insert (simpler than diffing)
        SalesLineItem::where('lineable_type', Proposal::class)
                     ->where('lineable_id', $proposal->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            $total = SalesLineItem::computeTotal($item);
            SalesLineItem::create([
                'lineable_type' => Proposal::class,
                'lineable_id'   => $proposal->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => $total,
                'sort_order'    => $idx,
            ]);
        }
    }
}
