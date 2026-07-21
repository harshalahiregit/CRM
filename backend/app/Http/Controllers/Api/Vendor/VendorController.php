<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(private VendorService $vendorService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->vendorService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'vendor_type', 'category', 'engagement', 'search'])
            )
        );
    }

    public function store(StoreVendorRequest $request)
    {
        $vendor = $this->vendorService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($vendor, 201);
    }

    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($vendor->load(['contacts', 'documents', 'accountManager:id,name', 'user:id,name,email,status']));
    }

    public function update(Request $request, Vendor $vendor, UpdateVendorRequest $updateRequest)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->vendorService->update($vendor, $updateRequest->validated(), $request->user())
        );
    }

    /** Admin gate — makes the vendor transactable by Purchase and TPV. */
    public function approve(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->vendorService->approve($vendor, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function updateStatus(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'status'  => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        return response()->json(
            $this->vendorService->updateStatus($vendor, $data['status'], $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $this->vendorService->destroy($vendor);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->vendorService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
