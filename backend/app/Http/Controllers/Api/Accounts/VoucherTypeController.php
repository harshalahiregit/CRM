<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\VoucherType;
use App\Services\Accounts\VoucherTypeService;
use Illuminate\Http\Request;

/** Voucher-type master management (Settings + inline manager on the Vouchers page). */
class VoucherTypeController extends Controller
{
    public function __construct(private VoucherTypeService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($request->user()->tenant_id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'prefix'      => 'nullable|string|max:10',
            'affects_gst' => 'nullable|boolean',
        ]);

        return response()->json($this->service->create($data, $request->user()->tenant_id), 201);
    }

    public function update(Request $request, VoucherType $voucherType)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'prefix'      => 'sometimes|string|max:10',
            'active'      => 'sometimes|boolean',
            'affects_gst' => 'sometimes|boolean',
        ]);

        return response()->json($this->service->update($voucherType, $data, $request->user()->tenant_id));
    }

    public function destroy(Request $request, VoucherType $voucherType)
    {
        $this->service->delete($voucherType, $request->user()->tenant_id);

        return response()->json(['message' => 'Voucher type deleted']);
    }
}
