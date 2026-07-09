<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SalesLineItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    private function tid(): int { return auth()->user()->tenant_id; }

    public function index(Request $request)
    {
        $query = SalesInvoice::forTenant($this->tid())->with(['lineItems', 'payments']);

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Auto-mark overdue before returning
        $invoices = $query->latest()->get();
        $invoices->each(fn($inv) => $inv->updateOverdueStatus());

        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'     => 'nullable|integer',
            'project_id'    => 'nullable|integer',
            'date'          => 'required|date',
            'due_date'      => 'required|date',
            'currency'      => 'nullable|string|size:3',
            'sale_agent'    => 'nullable|exists:users,id',
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'recurring'     => 'nullable|boolean',
            'recur_interval'=> 'nullable|string',
            'recur_type'    => 'nullable|string',
            'cycles'        => 'nullable|integer',
            'allowed_payment_modes' => 'nullable|array',
            'cancel_overdue_reminders' => 'nullable|boolean',
            'adminnote'     => 'nullable|string',
            'clientnote'    => 'nullable|string',
            'terms'         => 'nullable|string',
            'tags'          => 'nullable|string',
            'line_items'    => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::create([
                ...$validated,
                'tenant_id'  => $this->tid(),
                'created_by' => auth()->id(),
                'status'     => 'Draft',
            ]);

            $this->syncLineItems($invoice, $request->input('line_items', []));
            $invoice->recalcTotals();
            $invoice->update(['balance' => $invoice->total]);

            DB::commit();
            return response()->json($invoice->load('lineItems'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(SalesInvoice $invoice)
    {
        $this->authorize($invoice);
        return response()->json($invoice->load(['lineItems', 'payments', 'creditApplications']));
    }

    public function update(Request $request, SalesInvoice $invoice)
    {
        $this->authorize($invoice);
        $validated = $request->validate([
            'status'     => 'sometimes|in:Draft,Unpaid,Partially Paid,Paid,Overdue,Cancelled',
            'due_date'   => 'sometimes|date',
            'adminnote'  => 'nullable|string',
            'clientnote' => 'nullable|string',
            'terms'      => 'nullable|string',
            'line_items' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $invoice->update($validated);
            if ($request->has('line_items')) {
                $this->syncLineItems($invoice, $request->input('line_items', []));
                $invoice->recalcTotals();
            }
            DB::commit();
            return response()->json($invoice->fresh()->load(['lineItems', 'payments']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(SalesInvoice $invoice)
    {
        $this->authorize($invoice);
        $invoice->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function send(SalesInvoice $invoice)
    {
        $this->authorize($invoice);
        $invoice->update(['status' => 'Unpaid', 'sent_at' => now()]);
        return response()->json($invoice->fresh());
    }

    /**
     * POST /api/sales/invoices/{invoice}/payments
     */
    public function recordPayment(Request $request, SalesInvoice $invoice)
    {
        $this->authorize($invoice);

        $request->validate([
            'amount'         => 'required|numeric|min:0.01|max:' . $invoice->balance,
            'date'           => 'required|date',
            'mode'           => 'required|string',
            'transaction_id' => 'nullable|string|max:255',
            'note'           => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $payment = SalesPayment::create([
                'tenant_id'      => $this->tid(),
                'invoice_id'     => $invoice->id,
                'date'           => $request->date,
                'amount'         => $request->amount,
                'mode'           => $request->mode,
                'transaction_id' => $request->transaction_id,
                'note'           => $request->note,
                'created_by'     => auth()->id(),
            ]);

            $invoice->recalcBalance();
            DB::commit();

            return response()->json([
                'payment'       => $payment,
                'invoice_status'=> $invoice->status,
                'balance'       => $invoice->balance,
                'paid'          => $invoice->paid,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ── Helpers ─────────────────────────────────── */
    private function authorize(SalesInvoice $invoice): void
    {
        if ($invoice->tenant_id !== $this->tid()) abort(403);
    }

    private function syncLineItems(SalesInvoice $invoice, array $items): void
    {
        SalesLineItem::where('lineable_type', SalesInvoice::class)
                     ->where('lineable_id', $invoice->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            SalesLineItem::create([
                'lineable_type' => SalesInvoice::class,
                'lineable_id'   => $invoice->id,
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
