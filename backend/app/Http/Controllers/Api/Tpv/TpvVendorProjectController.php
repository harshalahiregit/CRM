<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvVendorProject;
use App\Models\Vendor\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §35 — explicit vendor↔project engagements. List and attach a vendor to a
 * project directly, rather than inferring the link through work packages.
 */
class TpvVendorProjectController extends Controller
{
    public function index(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json([
            'data'     => $vendor->tpvProjects()->latest('id')->get(),
            'statuses' => TpvVendorProject::STATUSES,
        ]);
    }

    public function store(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'project'    => 'required|string|max:160',
            'site'       => 'nullable|string|max:160',
            'role'       => 'nullable|string|max:120',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'status'     => ['sometimes', Rule::in(TpvVendorProject::STATUSES)],
            'notes'      => 'nullable|string|max:500',
        ]);

        $row = $vendor->tpvProjects()->create([
            ...$data,
            'tenant_id' => $vendor->tenant_id,
            'status'    => $data['status'] ?? 'Active',
        ]);

        return response()->json($row, 201);
    }

    public function destroy(Request $request, Vendor $vendor, TpvVendorProject $project)
    {
        $this->assertTenant($request, $vendor);
        abort_unless((int) $project->vendor_id === (int) $vendor->id, 404);

        $project->delete();

        return response()->json(['deleted' => true]);
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
