<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDeliveryNoteRequest;
use App\Models\DeliveryNote;
use App\Services\Sales\DeliveryNoteService;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    public function __construct(private DeliveryNoteService $deliveryNoteService)
    {
    }

    public function index(Request $request)
    {
        $deliveryNotes = $this->deliveryNoteService->list(
            $request->user()->tenant_id,
            $request->filled('status') ? $request->status : null
        );

        return response()->json($deliveryNotes);
    }

    public function store(StoreDeliveryNoteRequest $request)
    {
        $dn = $this->deliveryNoteService->create(
            $request->validated(),
            $request->user()->tenant_id,
            auth()->id()
        );

        return response()->json($dn, 201);
    }

    public function show(DeliveryNote $deliveryNote)
    {
        $deliveryNote = $this->deliveryNoteService->show($deliveryNote, auth()->user()->tenant_id);

        return response()->json($deliveryNote);
    }

    public function update(Request $request, DeliveryNote $deliveryNote)
    {
        $deliveryNote = $this->deliveryNoteService->update(
            $deliveryNote,
            $request->only([
                'delivery_date', 'status', 'shipping_address',
                'shipping_city', 'shipping_state', 'shipping_country', 'shipping_zip', 'note',
            ]),
            auth()->user()->tenant_id
        );

        return response()->json($deliveryNote);
    }

    public function markDelivered(DeliveryNote $deliveryNote)
    {
        $deliveryNote = $this->deliveryNoteService->markDelivered($deliveryNote, auth()->user()->tenant_id);

        return response()->json($deliveryNote);
    }

    public function destroy(DeliveryNote $deliveryNote)
    {
        $this->deliveryNoteService->delete($deliveryNote, auth()->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }
}
