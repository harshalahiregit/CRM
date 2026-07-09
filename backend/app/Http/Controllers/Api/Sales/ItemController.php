<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesItem;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    private function tid(): int
    {
        return auth()->user()->tenant_id;
    }

    public function index(Request $request)
    {
        $query = SalesItem::forTenant($this->tid());

        if ($request->filled('category') && $request->category !== 'All') {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'long_description' => 'nullable|string',
            'rate'             => 'required|numeric|min:0',
            'unit'             => 'nullable|string|max:50',
            'tax_rate'         => 'nullable|numeric|min:0|max:100',
            'tax_rate_2'       => 'nullable|numeric|min:0|max:100',
            'category'         => 'nullable|string|max:100',
        ]);

        $item = SalesItem::create([...$validated, 'tenant_id' => $this->tid()]);
        return response()->json($item, 201);
    }

    public function show(SalesItem $item)
    {
        $this->authorizeItem($item);
        return response()->json($item);
    }

    public function update(Request $request, SalesItem $item)
    {
        $this->authorizeItem($item);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'long_description' => 'nullable|string',
            'rate'             => 'sometimes|numeric|min:0',
            'unit'             => 'nullable|string|max:50',
            'tax_rate'         => 'nullable|numeric|min:0|max:100',
            'tax_rate_2'       => 'nullable|numeric|min:0|max:100',
            'category'         => 'nullable|string|max:100',
        ]);

        $item->update($validated);
        return response()->json($item);
    }

    public function destroy(SalesItem $item)
    {
        $this->authorizeItem($item);
        $item->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function authorizeItem(SalesItem $item): void
    {
        if ($item->tenant_id !== $this->tid()) {
            abort(403, 'Unauthorized');
        }
    }
}
