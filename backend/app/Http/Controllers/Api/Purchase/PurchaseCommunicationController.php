<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseCommunicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Communications Centre — mirror of the TPV centre (parity). Tenant-scoped. */
class PurchaseCommunicationController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseCommunicationService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'alerts'   => $this->service->alerts($tenantId),
            'log'      => $this->service->log($tenantId, $request->only(['vendor_id', 'channel', 'limit'])),
            'channels' => PurchaseCommunicationService::CHANNELS,
        ]);
    }

    public function send(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can send vendor communications.');

        $data = $request->validate([
            'vendor_id' => 'required|integer|exists:purchase_vendors,id',
            'channel'   => ['required', Rule::in(PurchaseCommunicationService::CHANNELS)],
            'subject'   => 'required|string|max:200',
            'body'      => 'required|string|max:5000',
        ]);

        $vendor = PurchaseVendor::findOrFail($data['vendor_id']);
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->service->send($vendor, $data['channel'], $data['subject'], $data['body'], $request->user()),
            201
        );
    }
}
