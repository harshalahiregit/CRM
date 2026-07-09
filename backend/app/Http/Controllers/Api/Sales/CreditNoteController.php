<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteRefund;
use App\Models\SalesInvoice;
use App\Models\SalesLineItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller
{
    private function tid(): int { return auth()->user()->tenant_id; }

    public function index(Request $request)
    {
        $query = CreditNote::forTenant($this->tid())->with('lineItems');
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'  => 'nullable|integer',
            'invoice_id' => 'nullable|exists:sales_invoices,id',
            'date'       => 'required|date',
            'currency'   => 'nullable|string|size:3',
            'reason'     => 'nullable|string',
            'adminnote'  => 'nullable|string',
            'clientnote' => 'nullable|string',
            'terms'      => 'nullable|string',
            'line_items' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $cn = CreditNote::create([
                ...$validated,
                'tenant_id'  => $this->tid(),
                'created_by' => auth()->id(),
                'status'     => 'Open',
            ]);

            if (!empty($validated['line_items'])) {
                $this->syncLineItems($cn, $validated['line_items']);
            }
            $cn->recalcRemaining(); // sets total from line items

            DB::commit();
            return response()->json($cn->load('lineItems'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(CreditNote $creditNote)
    {
        $this->authorize($creditNote);
        return response()->json($creditNote->load(['lineItems', 'applications', 'refunds']));
    }

    public function destroy(CreditNote $creditNote)
    {
        $this->authorize($creditNote);
        $creditNote->update(['status' => 'Void']);
        return response()->json(['message' => 'Voided']);
    }

    /**
     * POST /api/sales/credit-notes/{creditNote}/apply
     * Apply credit note balance to an invoice
     */
    public function applyToInvoice(Request $request, CreditNote $creditNote)
    {
        $this->authorize($creditNote);

        $request->validate([
            'invoice_id' => 'required|exists:sales_invoices,id',
        ]);

        $invoice = SalesInvoice::where('tenant_id', $this->tid())->findOrFail($request->invoice_id);

        if ($creditNote->remaining <= 0) {
            return response()->json(['error' => 'Credit note has no remaining balance'], 422);
        }
        if ($invoice->balance <= 0) {
            return response()->json(['error' => 'Invoice is already fully paid'], 422);
        }

        DB::beginTransaction();
        try {
            $applyAmount = min($creditNote->remaining, $invoice->balance);

            CreditNoteApplication::create([
                'credit_note_id' => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'amount'         => $applyAmount,
                'date'           => now()->toDateString(),
                'created_by'     => auth()->id(),
            ]);

            $creditNote->recalcRemaining();
            $invoice->recalcBalance();

            DB::commit();
            return response()->json([
                'applied'        => $applyAmount,
                'cn_remaining'   => $creditNote->remaining,
                'invoice_balance'=> $invoice->balance,
                'invoice_status' => $invoice->status,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/sales/credit-notes/{creditNote}/refund
     */
    public function refund(Request $request, CreditNote $creditNote)
    {
        $this->authorize($creditNote);

        $request->validate([
            'amount'         => 'required|numeric|min:0.01|max:' . $creditNote->remaining,
            'mode'           => 'required|string',
            'transaction_id' => 'nullable|string',
            'note'           => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            CreditNoteRefund::create([
                'credit_note_id' => $creditNote->id,
                'amount'         => $request->amount,
                'mode'           => $request->mode,
                'transaction_id' => $request->transaction_id,
                'note'           => $request->note,
                'date'           => now()->toDateString(),
                'created_by'     => auth()->id(),
            ]);

            $creditNote->recalcRemaining();
            DB::commit();
            return response()->json(['remaining' => $creditNote->remaining, 'status' => $creditNote->status]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ── Helpers ─────────────────────────────────── */
    private function authorize(CreditNote $cn): void
    {
        if ($cn->tenant_id !== $this->tid()) abort(403);
    }

    private function syncLineItems(CreditNote $cn, array $items): void
    {
        SalesLineItem::where('lineable_type', CreditNote::class)
                     ->where('lineable_id', $cn->id)->delete();

        $subtotal = $taxTotal = $total = 0;
        foreach ($items as $idx => $item) {
            $lineTotal = SalesLineItem::computeTotal($item);
            $total += $lineTotal;
            SalesLineItem::create([
                'lineable_type' => CreditNote::class,
                'lineable_id'   => $cn->id,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => $lineTotal,
                'sort_order'    => $idx,
            ]);
        }
        $cn->total     = round($total, 2);
        $cn->remaining = round($total, 2);
        $cn->saveQuietly();
    }
}
