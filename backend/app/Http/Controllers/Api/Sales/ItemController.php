<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreItemRequest;
use App\Http\Requests\Sales\UpdateItemRequest;
use App\Models\SalesItem;
use App\Services\Sales\ItemService;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(private ItemService $itemService)
    {
    }

    public function index(Request $request)
    {
        $items = $this->itemService->list(
            $request->user()->tenant_id,
            $request->filled('category') ? $request->category : null,
            $request->filled('search') ? $request->search : null
        );

        return response()->json($items);
    }

    public function store(StoreItemRequest $request)
    {
        $item = $this->itemService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($item, 201);
    }

    public function show(SalesItem $item)
    {
        $item = $this->itemService->show($item, auth()->user()->tenant_id);

        return response()->json($item);
    }

    public function update(UpdateItemRequest $request, SalesItem $item)
    {
        $item = $this->itemService->update($item, $request->validated(), auth()->user()->tenant_id);

        return response()->json($item);
    }

    public function destroy(SalesItem $item)
    {
        $this->itemService->delete($item, auth()->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }
}
