<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\IncidentCapa;
use App\Services\Tpv\IncidentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HSSE incidents → RCA → CAPA (Doc_4 Phase 5). Tenant-scoped; every sub-resource
 * is guarded so nothing from another tenant is reachable. Governance rules
 * (auto-suspend on grave events, close only when investigated) live in the
 * service, not here.
 */
class IncidentController extends Controller
{
    public function __construct(private IncidentService $service)
    {
    }

    public function index(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $rows = HsseIncident::where('tenant_id', $tid)
            ->with(['vendor:id,company_name,vendor_code', 'worker:id,name', 'capas'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('occurred_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'type'            => ['required', Rule::in(HsseIncident::TYPES)],
            'severity'        => ['required', Rule::in(HsseIncident::SEVERITIES)],
            'vendor_id'       => 'nullable|integer',
            'tpv_worker_id'   => 'nullable|integer',
            'occurred_at'     => 'nullable|date',
            'location'        => 'nullable|string|max:200',
            'project'         => 'nullable|string|max:160',
            'site'            => 'nullable|string|max:160',
            'department'      => 'nullable|string|max:160',
            'activity'        => 'nullable|string|max:160',
            'work_package_id' => 'nullable|integer',
            'description'     => 'nullable|string',
            'immediate_action'=> 'nullable|string',
            'stop_work'       => 'sometimes|boolean',
        ]);

        return response()->json($this->service->create($request->user()->tenant_id, $data, $request->user()), 201);
    }

    public function show(Request $request, HsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        return response()->json($incident->load([
            'vendor:id,company_name,vendor_code', 'worker:id,name', 'reporter:id,name',
            'capas.assignee:id,name',
        ]));
    }

    public function recordRca(Request $request, HsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        $data = $request->validate([
            'rca_method'           => 'nullable|string|max:60',
            'root_cause'           => 'required|string',
            'contributing_factors' => 'nullable|string',
        ]);

        return response()->json($this->service->recordRca($incident, $data, $request->user()));
    }

    public function close(Request $request, HsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        return response()->json($this->service->close($incident, $request->user()));
    }

    public function addCapa(Request $request, HsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(IncidentCapa::TYPES)],
            'description' => 'required|string',
            'assigned_to' => 'nullable|integer',
            'due_date'    => 'nullable|date',
        ]);

        return response()->json($this->service->addCapa($incident, $data, $request->user()), 201);
    }

    public function updateCapa(Request $request, HsseIncident $incident, IncidentCapa $capa)
    {
        $this->assertTenant($request, $incident);
        abort_unless($capa->incident_id === $incident->id && (int) $capa->tenant_id === (int) $incident->tenant_id, 404);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(IncidentCapa::TYPES)],
            'description' => 'sometimes|string',
            'assigned_to' => 'nullable|integer',
            'due_date'    => 'nullable|date',
            'status'      => ['sometimes', Rule::in(IncidentCapa::STATUSES)],
            'notes'       => 'nullable|string',
        ]);

        return response()->json($this->service->updateCapa($capa, $data, $request->user()));
    }

    private function assertTenant(Request $request, HsseIncident $incident): void
    {
        abort_unless((int) $incident->tenant_id === (int) $request->user()->tenant_id, 404);
    }
}
