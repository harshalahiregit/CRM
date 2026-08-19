<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\EmergencyDrill;
use App\Models\Tpv\SiteVehicle;
use App\Models\Tpv\SiteVisitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Site safety registers (Doc_4 Phase 5/6) — emergency drills + the visitor and
 * vehicle gate registers. Simple tenant-scoped logs; visitors and vehicles can
 * be checked out (a second timestamp), closing the entry.
 */
class SiteRegisterController extends Controller
{
    /* ── Emergency drills ───────────────────────────────────────────────── */

    public function drills(Request $request)
    {
        return response()->json(['data' => EmergencyDrill::where('tenant_id', $request->user()->tenant_id)
            ->with('conductor:id,name')->latest('conducted_at')->latest('id')->get()]);
    }

    public function storeDrill(Request $request)
    {
        $data = $request->validate([
            'drill_type'         => ['required', Rule::in(EmergencyDrill::TYPES)],
            'conducted_at'       => 'nullable|date',
            'location'           => 'nullable|string|max:200',
            'participants'       => 'nullable|integer|min:0|max:100000',
            'evacuation_seconds' => 'nullable|integer|min:0|max:100000',
            'findings'           => 'nullable|string',
        ]);

        $drill = EmergencyDrill::create([
            'tenant_id'    => $request->user()->tenant_id,
            'conducted_by' => $request->user()->id,
            'drill_type'   => $data['drill_type'],
            'conducted_at' => $data['conducted_at'] ?? now(),
            'location'     => $data['location'] ?? null,
            'participants' => $data['participants'] ?? 0,
            'evacuation_seconds' => $data['evacuation_seconds'] ?? null,
            'findings'     => $data['findings'] ?? null,
        ]);

        return response()->json($drill->fresh('conductor:id,name'), 201);
    }

    /* ── Visitor register ───────────────────────────────────────────────── */

    public function visitors(Request $request)
    {
        return response()->json(['data' => SiteVisitor::where('tenant_id', $request->user()->tenant_id)
            ->latest('check_in_at')->latest('id')->get()]);
    }

    public function storeVisitor(Request $request)
    {
        $data = $request->validate([
            'visitor_name' => 'required|string|max:160',
            'company'      => 'nullable|string|max:160',
            'purpose'      => 'nullable|string|max:200',
            'host'         => 'nullable|string|max:160',
            'contact'      => 'nullable|string|max:60',
            'badge_number' => 'nullable|string|max:60',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['check_in_at'] = now();

        return response()->json(SiteVisitor::create($data), 201);
    }

    public function checkoutVisitor(Request $request, SiteVisitor $visitor)
    {
        abort_unless((int) $visitor->tenant_id === (int) $request->user()->tenant_id, 404);
        $visitor->update(['check_out_at' => now()]);

        return response()->json($visitor);
    }

    /* ── Vehicle register ───────────────────────────────────────────────── */

    public function vehicles(Request $request)
    {
        return response()->json(['data' => SiteVehicle::where('tenant_id', $request->user()->tenant_id)
            ->with('vendor:id,company_name')->latest('check_in_at')->latest('id')->get()]);
    }

    public function storeVehicle(Request $request)
    {
        $data = $request->validate([
            'vehicle_number' => 'required|string|max:40',
            'vehicle_type'   => 'nullable|string|max:60',
            'driver_name'    => 'nullable|string|max:160',
            'vendor_id'      => 'nullable|integer',
            'purpose'        => 'nullable|string|max:200',
            'fitness_valid'  => 'sometimes|boolean',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['check_in_at'] = now();

        return response()->json(SiteVehicle::create($data)->fresh('vendor:id,company_name'), 201);
    }

    public function checkoutVehicle(Request $request, SiteVehicle $vehicle)
    {
        abort_unless((int) $vehicle->tenant_id === (int) $request->user()->tenant_id, 404);
        $vehicle->update(['check_out_at' => now()]);

        return response()->json($vehicle);
    }
}
