<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvCommunicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Communications Centre (Sangoe TPV §31). Tenant-scoped. */
class TpvCommunicationController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvCommunicationService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'alerts'   => $this->service->alerts($tenantId),
            'log'      => $this->service->log($tenantId, $request->only(['vendor_id', 'channel', 'limit'])),
            'channels' => TpvCommunicationService::CHANNELS,
            'triggers' => TpvCommunicationService::TRIGGERS,
        ]);
    }

    public function send(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can send vendor communications.');

        $data = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'channel'   => ['required', Rule::in(TpvCommunicationService::CHANNELS)],
            'subject'   => 'required|string|max:200',
            'body'      => 'required|string|max:5000',
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->service->send($vendor, $data['channel'], $data['subject'], $data['body'], $request->user()),
            201
        );
    }
}
