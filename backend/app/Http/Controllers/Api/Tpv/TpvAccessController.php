<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\CreateTemporaryVendorRequest;
use App\Http\Requests\Tpv\ExtendAccessRequest;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvAccessService;
use Illuminate\Http\Request;

/**
 * Temporary TPV access lifecycle endpoints (Phase 2). Admin/Staff manage the
 * window; the vendor reads their own server-authoritative countdown.
 */
class TpvAccessController extends Controller
{
    public function __construct(private TpvAccessService $accessService)
    {
    }

    /** GET /tpv/vendors/temporary — list this tenant's Temporary TPVs + countdowns. */
    public function index(Request $request)
    {
        return response()->json($this->accessService->listTemporary($request->user()->tenant_id));
    }

    /** POST /tpv/vendors/temporary — Admin/Staff creates a Temporary TPV. */
    public function createTemporary(CreateTemporaryVendorRequest $request)
    {
        $result = $this->accessService->createTemporary($request->validated(), $request->user());

        return response()->json($result, 201);
    }

    /** POST /tpv/vendors/{vendor}/access/extend */
    public function extend(ExtendAccessRequest $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->accessService->extend($vendor, $request->user(), $request->validated()));
    }

    /** POST /tpv/vendors/{vendor}/access/expire (admin) */
    public function expire(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json([
            'vendor'           => $this->accessService->expire($vendor, $request->user()),
            'sessions_revoked' => true,
        ]);
    }

    /** POST /tpv/vendors/{vendor}/access/convert (admin) */
    public function convert(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->accessService->convert($vendor, $request->user()));
    }

    /** GET /tpv/vendors/{vendor}/access/status (admin) */
    public function status(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->accessService->status($vendor));
    }

    /** GET /tpv/access/countdown — the caller's own countdown (works when expired). */
    public function countdown(Request $request)
    {
        $vendor = Vendor::forTenant($request->user()->tenant_id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($vendor, 404, 'No vendor profile linked to this account.');

        return response()->json($this->accessService->project($vendor));
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
