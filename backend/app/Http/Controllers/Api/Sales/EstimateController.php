<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\SalesInvoice;
use App\Models\SalesLineItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EstimateController extends Controller
{
    private function tid(): int { return auth()->user()->tenant_id; }

    public function index(Request $request)
    {
        $query = Estimate::forTenant($this->tid())->with('lineItems');
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'       => 'required|string|max:255',
            'client_id'     => 'nullable|integer',
            'project_id'    => 'nullable|integer',
            'date'          => 'required|date',
            'valid_until'   => 'nullable|date',
            'currency'      => 'nullable|string|size:3',
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'sale_agent'    => 'nullable|exists:users,id',
            'status'        => 'nullable|in:Draft,Sent,Accepted,Declined,Expired',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string',
            'state'         => 'nullable|string',
            'country'       => 'nullable|string',
            'zip'           => 'nullable|string',
            'adminnote'     => 'nullable|string',
            'clientnote'    => 'nullable|string',
            'terms'         => 'nullable|string',
            'tags'          => 'nullable|string',
            'line_items'    => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $estimate = Estimate::create([
                ...$validated,
                'tenant_id'  => $this->tid(),
                'created_by' => auth()->id(),
                'status'     => $validated['status'] ?? 'Draft',
            ]);

            $this->syncLineItems($estimate, $request->input('line_items', []));
            $estimate->recalcTotals();

            DB::commit();
            return response()->json($estimate->load('lineItems'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Estimate $estimate)
    {
        $this->authorize($estimate);
        return response()->json($estimate->load(['lineItems', 'agent']));
    }

    public function update(Request $request, Estimate $estimate)
    {
        $this->authorize($estimate);
        $validated = $request->validate([
            'subject'    => 'sometimes|string|max:255',
            'status'     => 'sometimes|in:Draft,Sent,Accepted,Declined,Expired',
            'valid_until'=> 'nullable|date',
            'line_items' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $estimate->update($validated);
            if ($request->has('line_items')) {
                $this->syncLineItems($estimate, $request->input('line_items', []));
                $estimate->recalcTotals();
            }
            DB::commit();
            return response()->json($estimate->fresh()->load('lineItems'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Estimate $estimate)
    {
        $this->authorize($estimate);
        $estimate->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function send(Estimate $estimate)
    {
        $this->authorize($estimate);
        $estimate->update(['status' => 'Sent', 'sent_at' => now()]);
        return response()->json($estimate->fresh());
    }

    /**
     * Convert accepted estimate → new invoice
     */
    public function convertToInvoice(Request $request, Estimate $estimate)
    {
        $this->authorize($estimate);

        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::create([
                'tenant_id'   => $this->tid(),
                'client_id'   => $estimate->client_id,
                'project_id'  => $estimate->project_id,
                'estimate_id' => $estimate->id,
                'date'        => now()->toDateString(),
                'due_date'    => $request->input('due_date', now()->addDays(30)->toDateString()),
                'currency'    => $estimate->currency,
                'sale_agent'  => $estimate->sale_agent,
                'discount_type' => $estimate->discount_type,
                'status'      => 'Draft',
                'terms'       => $estimate->terms,
                'adminnote'   => $estimate->adminnote,
                'clientnote'  => $estimate->clientnote,
                'created_by'  => auth()->id(),
            ]);

            // Copy line items
            foreach ($estimate->lineItems as $idx => $li) {
                SalesLineItem::create([
                    'lineable_type' => SalesInvoice::class,
                    'lineable_id'   => $invoice->id,
                    'item_id'       => $li->item_id,
                    'item_name'     => $li->item_name,
                    'description'   => $li->description,
                    'qty'           => $li->qty,
                    'unit'          => $li->unit,
                    'rate'          => $li->rate,
                    'tax'           => $li->tax,
                    'discount'      => $li->discount,
                    'total'         => $li->total,
                    'sort_order'    => $idx,
                ]);
            }

            $invoice->recalcTotals();
            $estimate->update(['status' => 'Accepted']);

            DB::commit();
            return response()->json(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ── Helpers ─────────────────────────────────── */
    private function authorize(Estimate $estimate): void
    {
        if ($estimate->tenant_id !== $this->tid()) abort(403);
    }

    private function syncLineItems(Estimate $estimate, array $items): void
    {
        SalesLineItem::where('lineable_type', Estimate::class)
                     ->where('lineable_id', $estimate->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            SalesLineItem::create([
                'lineable_type' => Estimate::class,
                'lineable_id'   => $estimate->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => SalesLineItem::computeTotal($item),
                'sort_order'    => $idx,
            ]);
        }
    }
}
