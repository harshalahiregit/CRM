<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    private function tid(): int { return auth()->user()->tenant_id; }

    public function index(Request $request)
    {
        $query = DeliveryNote::forTenant($this->tid())->with('invoice');
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id'       => 'nullable|exists:sales_invoices,id',
            'client_id'        => 'nullable|integer',
            'delivery_date'    => 'required|date',
            'shipping_address' => 'nullable|string',
            'shipping_city'    => 'nullable|string',
            'shipping_state'   => 'nullable|string',
            'shipping_country' => 'nullable|string',
            'shipping_zip'     => 'nullable|string',
            'note'             => 'nullable|string',
        ]);

        $dn = DeliveryNote::create([
            ...$validated,
            'tenant_id'  => $this->tid(),
            'created_by' => auth()->id(),
            'status'     => 'Draft',
        ]);

        return response()->json($dn, 201);
    }

    public function show(DeliveryNote $deliveryNote)
    {
        $this->authorize($deliveryNote);
        return response()->json($deliveryNote->load('invoice'));
    }

    public function update(Request $request, DeliveryNote $deliveryNote)
    {
        $this->authorize($deliveryNote);
        $deliveryNote->update($request->only([
            'delivery_date', 'status', 'shipping_address',
            'shipping_city', 'shipping_state', 'shipping_country', 'shipping_zip', 'note',
        ]));
        return response()->json($deliveryNote->fresh());
    }

    public function markDelivered(DeliveryNote $deliveryNote)
    {
        $this->authorize($deliveryNote);
        $deliveryNote->update(['status' => 'Delivered']);
        return response()->json($deliveryNote->fresh());
    }

    public function destroy(DeliveryNote $deliveryNote)
    {
        $this->authorize($deliveryNote);
        $deliveryNote->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function authorize(DeliveryNote $dn): void
    {
        if ($dn->tenant_id !== $this->tid()) abort(403);
    }
}
