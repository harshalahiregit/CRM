<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\SalaryStructureService;
use Illuminate\Http\Request;

/**
 * Payroll → Salary Structures master. Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. No hard delete —
 * status toggle only. CTC breakdowns are computed by the service, not stored.
 */
class SalaryStructureController extends Controller
{
    public function __construct(private SalaryStructureService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data'  => $this->service->list($this->tenant($request), $request->only(['grade_id', 'status', 'search'])),
            'stats' => $this->service->stats($this->tenant($request)),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    /** Live salary preview for the Salary Builder — resolve unsaved lines, no persist. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'lines'                    => 'required|array|min:1',
            'lines.*.component_id'     => 'required|integer',
            'lines.*.calculation_type' => 'nullable|string',
            'lines.*.amount'           => 'nullable|numeric',
            'lines.*.percentage'       => 'nullable|numeric',
            'lines.*.based_on'         => 'nullable|string|max:150',
            'lines.*.formula'          => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->preview($data['lines'], $this->tenant($request)));
    }

    public function duplicate(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->duplicate($id, $this->tenant($request), $request->user()), 201);
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->create($this->validated($request), $this->tenant($request), $request->user()),
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user())
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user())
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'                 => "$req|string|max:150",
            'code'                 => 'nullable|string|max:40',
            'grade_id'             => 'nullable|integer',
            'designation_id'       => 'nullable|integer',
            'description'          => 'nullable|string',
            'is_active'            => 'boolean',
            'lines'                    => "$req|array|min:1",
            'lines.*.component_id'     => 'required|integer',
            'lines.*.calculation_type' => 'nullable|string',
            'lines.*.amount'           => 'nullable|numeric|min:0',
            'lines.*.percentage'       => 'nullable|numeric|min:0|max:100',
            'lines.*.based_on'         => 'nullable|string|max:150',
            'lines.*.formula'          => 'nullable|string|max:500',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage payroll settings');
    }
}
