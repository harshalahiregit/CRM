<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use Illuminate\Http\Request;

/**
 * Tenant GST slab management (Settings → Tax Rates). Slabs are lazily
 * seeded with the standard Indian set on first read so every tenant has a
 * working picklist without a setup step.
 */
class TaxRateController extends Controller
{
    private const DEFAULT_SLABS = [0, 5, 12, 18, 28];

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (! TaxRate::forTenant($tenantId)->exists()) {
            foreach (self::DEFAULT_SLABS as $rate) {
                TaxRate::firstOrCreate(
                    ['tenant_id' => $tenantId, 'rate' => $rate],
                    ['name' => "GST {$rate}%", 'active' => true],
                );
            }
        }

        return response()->json(TaxRate::forTenant($tenantId)->orderBy('rate')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $tenantId = $request->user()->tenant_id;

        // Named taxes may share a percentage (CGST 9% + SGST 9%) — the NAME is what must be unique.
        abort_if(TaxRate::forTenant($tenantId)->where('name', $data['name'])->exists(), 422, 'A tax with this name already exists.');

        return response()->json(TaxRate::create([...$data, 'tenant_id' => $tenantId]), 201);
    }

    public function update(Request $request, TaxRate $taxRate)
    {
        abort_if($taxRate->tenant_id !== $request->user()->tenant_id, 404);
        $data = $this->validated($request);

        abort_if(
            TaxRate::forTenant($taxRate->tenant_id)->where('name', $data['name'])->where('id', '!=', $taxRate->id)->exists(),
            422, 'A tax with this name already exists.',
        );

        $taxRate->update($data);

        return response()->json($taxRate->fresh());
    }

    public function destroy(Request $request, TaxRate $taxRate)
    {
        abort_if($taxRate->tenant_id !== $request->user()->tenant_id, 404);
        $taxRate->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'   => 'required|string|max:100',
            'rate'   => 'required|numeric|min:0|max:100',
            'active' => 'nullable|boolean',
        ]);
    }
}
