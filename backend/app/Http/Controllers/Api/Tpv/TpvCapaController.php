<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvCapa;
use App\Services\Tpv\TpvCapaService;
use App\Support\Tpv\CapaSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Generalised TPV CAPA register (Sangoe TPV §25). Tenant-scoped. */
class TpvCapaController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvCapaService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data' => $this->service->list($tenantId, $request->only(['status', 'priority', 'type', 'source_kind', 'vendor_id', 'overdue'])),
            'stats' => $this->service->stats($tenantId),
            'kinds' => CapaSource::KINDS,
            'types' => CapaSource::TYPES,
            'priorities' => CapaSource::PRIORITIES,
            'statuses' => CapaSource::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(
            $this->service->create($this->validateCapa($request), $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function update(Request $request, TpvCapa $capa)
    {
        $this->assertTenant($request, $capa);

        return response()->json($this->service->update($capa, $this->validateCapa($request, true)));
    }

    public function transition(Request $request, TpvCapa $capa)
    {
        $this->assertTenant($request, $capa);
        $data = $request->validate([
            'status' => ['required', Rule::in(CapaSource::STATUSES)],
            'remarks' => 'nullable|string',
        ]);

        return response()->json($this->service->transition($capa, $data['status'], $request->user(), $data['remarks'] ?? null));
    }

    public function destroy(Request $request, TpvCapa $capa)
    {
        $this->assertTenant($request, $capa);
        $this->service->delete($capa);

        return response()->json(['deleted' => true]);
    }

    private function validateCapa(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "$req|string|max:200",
            'problem_statement' => 'nullable|string',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'source_kind' => ['nullable', Rule::in(CapaSource::KINDS)],
            'source_id' => 'nullable|integer',
            'type' => ['nullable', Rule::in(CapaSource::TYPES)],
            'root_cause' => 'nullable|string',
            'immediate_correction' => 'nullable|string',
            'action' => 'nullable|string',
            'preventive_action' => 'nullable|string',
            'priority' => ['nullable', Rule::in(CapaSource::PRIORITIES)],
            'status' => ['nullable', Rule::in(CapaSource::STATUSES)],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
            'evidence_path' => 'nullable|string|max:2048',
            'notes' => 'nullable|string',
        ]);
    }
}
