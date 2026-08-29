<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseHsseIncident;
use App\Services\Purchase\PurchaseIncidentService;
use App\Support\Purchase\PurchaseCapaSource as CapaSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase HSSE incidents → RCA → CAPA — mirror of the TPV IncidentController
 * (parity). Tenant-scoped; every sub-resource is guarded so nothing from another
 * tenant is reachable. Governance rules (auto-suspend on grave events, close
 * only when investigated) live in the service, not here.
 */
class PurchaseIncidentController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseIncidentService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data'       => $this->service->list($request->user()->tenant_id, $request->only(['status', 'severity', 'vendor_id'])),
            'types'      => PurchaseHsseIncident::TYPES,
            'severities' => PurchaseHsseIncident::SEVERITIES,
            'statuses'   => PurchaseHsseIncident::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'              => 'required|string|max:200',
            'type'               => ['required', Rule::in(PurchaseHsseIncident::TYPES)],
            'severity'           => ['required', Rule::in(PurchaseHsseIncident::SEVERITIES)],
            'purchase_vendor_id' => 'required|integer|exists:purchase_vendors,id',
            'occurred_at'        => 'nullable|date',
            'location'           => 'nullable|string|max:200',
            'description'        => 'nullable|string',
            'immediate_action'   => 'nullable|string',
            'stop_work'          => 'sometimes|boolean',
        ]);

        return response()->json($this->service->create($request->user()->tenant_id, $data, $request->user()), 201);
    }

    public function show(Request $request, PurchaseHsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        return response()->json($incident->load([
            'vendor:id,company_name,purchase_vendor_code', 'reporter:id,name',
            'capas.assignee:id,name',
        ]));
    }

    public function recordRca(Request $request, PurchaseHsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        $data = $request->validate([
            'rca_method'           => 'nullable|string|max:60',
            'root_cause'           => 'required|string',
            'contributing_factors' => 'nullable|string',
        ]);

        return response()->json($this->service->recordRca($incident, $data, $request->user()));
    }

    public function close(Request $request, PurchaseHsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        return response()->json($this->service->close($incident, $request->user()));
    }

    public function addCapa(Request $request, PurchaseHsseIncident $incident)
    {
        $this->assertTenant($request, $incident);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(CapaSource::TYPES)],
            'description' => 'required|string',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
        ]);

        return response()->json($this->service->addCapa($incident, $data, $request->user()), 201);
    }

    public function updateCapa(Request $request, PurchaseHsseIncident $incident, PurchaseCapa $capa)
    {
        $this->assertTenant($request, $incident);
        abort_unless(
            $capa->source_kind === 'incident'
                && (int) $capa->source_id === (int) $incident->id
                && (int) $capa->tenant_id === (int) $incident->tenant_id,
            404
        );

        $data = $request->validate([
            'type'          => ['sometimes', Rule::in(CapaSource::TYPES)],
            'description'   => 'sometimes|string',
            'assigned_to'   => 'nullable|integer|exists:users,id',
            'due_date'      => 'nullable|date',
            'status'        => ['sometimes', Rule::in(CapaSource::STATUSES)],
            'evidence_path' => 'nullable|string|max:2048',
            'notes'         => 'nullable|string',
        ]);

        return response()->json($this->service->updateCapa($capa, $data, $request->user()));
    }
}
