<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvGateEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §20 — unified gate events (Equipment / Material / Vehicle / Visitor / Person
 * entry & exit). The live view filters by kind, direction, vendor, project, work
 * package and location — all applied server-side.
 */
class TpvGateEventController extends Controller
{
    public function index(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $rows = TpvGateEvent::where('tenant_id', $tid)
            ->with(['vendor:id,company_name', 'workPackage:id,name'])
            ->when($request->query('event_kind'), fn ($q, $v) => $q->where('event_kind', $v))
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($request->query('project'), fn ($q, $v) => $q->where('project', $v))
            ->when($request->query('work_package_id'), fn ($q, $v) => $q->where('work_package_id', $v))
            ->when($request->query('location'), fn ($q, $v) => $q->where('location', $v))
            ->latest('occurred_at')
            ->limit(2000)
            ->get();

        return response()->json([
            'data'       => $rows,
            'kinds'      => TpvGateEvent::KINDS,
            'directions' => TpvGateEvent::DIRECTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_kind'      => ['required', Rule::in(TpvGateEvent::KINDS)],
            'direction'       => ['required', Rule::in(TpvGateEvent::DIRECTIONS)],
            'label'           => 'required|string|max:200',
            'reference'       => 'nullable|string|max:120',
            'vendor_id'       => 'nullable|integer',
            'quantity'        => 'nullable|numeric|min:0',
            'unit'            => 'nullable|string|max:40',
            'project'         => 'nullable|string|max:160',
            'work_package_id' => 'nullable|integer',
            'location'        => 'nullable|string|max:160',
            'gate'            => 'nullable|string|max:80',
            'occurred_at'     => 'nullable|date',
            'details'         => 'nullable|array',
        ]);

        $event = TpvGateEvent::create([
            ...$data,
            'tenant_id'   => $request->user()->tenant_id,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json($event->fresh(['vendor', 'workPackage']), 201);
    }
}
