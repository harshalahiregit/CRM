<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Rules\Hr\ValidWorkState;
use App\Services\Hr\WorkplaceService;
use App\Support\Hr\WorkStates;
use Illuminate\Http\Request;

/**
 * Workplace Management — Branch / Office / Floor and seating.
 *
 * `work_state` on a branch is validated with the SAME rule Professional Tax uses,
 * so a city cannot be entered where a jurisdiction is expected.
 */
class WorkplaceController extends Controller
{
    public function __construct(private WorkplaceService $service)
    {
    }

    public function meta()
    {
        return response()->json(['work_states' => WorkStates::options()]);
    }

    /** Branch → office → floor, for cascading selects. */
    public function tree(Request $request)
    {
        return response()->json(['data' => $this->service->tree($this->tenant($request))]);
    }

    /* ── Branch ───────────────────────────────────────────────────────── */

    public function branches(Request $request)
    {
        return response()->json([
            'data' => $this->service->branches($this->tenant($request), $request->only(['is_active', 'work_state'])),
        ]);
    }

    public function saveBranch(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'           => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'           => 'nullable|string|max:40',
            'address'        => 'nullable|string|max:500',
            'city'           => 'nullable|string|max:100',
            'work_state'     => ['nullable', 'string', 'max:80', new ValidWorkState],
            'pincode'        => 'nullable|string|max:20',
            'phone'          => 'nullable|string|max:40',
            'email'          => 'nullable|email|max:191',
            'is_head_office' => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveBranch($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyBranch(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteBranch($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Office ───────────────────────────────────────────────────────── */

    public function offices(Request $request)
    {
        return response()->json([
            'data' => $this->service->offices($this->tenant($request), $request->query('branch_id') ? (int) $request->query('branch_id') : null),
        ]);
    }

    public function saveOffice(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'branch_id' => ($id ? 'sometimes|' : '').'required|integer',
            'name'      => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'      => 'nullable|string|max:40',
            'address'   => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveOffice($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyOffice(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteOffice($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Floor ────────────────────────────────────────────────────────── */

    public function floors(Request $request)
    {
        return response()->json([
            'data' => $this->service->floors($this->tenant($request), $request->query('office_id') ? (int) $request->query('office_id') : null),
        ]);
    }

    public function saveFloor(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'office_id'     => ($id ? 'sometimes|' : '').'required|integer',
            'name'          => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'          => 'nullable|string|max:40',
            'seat_capacity' => 'nullable|integer|min:0|max:100000',
            'is_active'     => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveFloor($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyFloor(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteFloor($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Assignment ───────────────────────────────────────────────────── */

    public function seating(Request $request)
    {
        return response()->json([
            'data' => $this->service->seating($this->tenant($request), $request->only(['branch_id', 'office_id', 'floor_id'])),
        ]);
    }

    public function assign(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id'     => 'required|integer',
            'branch_id'       => 'required|integer',
            'office_id'       => 'nullable|integer',
            'floor_id'        => 'nullable|integer',
            'seat_no'         => 'nullable|string|max:40',
            'effective_from'  => 'required|date',
            'reason'          => 'nullable|string|max:500',
            // Opt-in: copies the branch's state onto the employee, which changes
            // what Professional Tax is computed against.
            'sync_work_state' => 'nullable|boolean',
        ]);

        return response()->json($this->service->assignLocation($data, $this->tenant($request), $request->user()), 201);
    }

    public function history(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->service->locationHistory($employeeId, $this->tenant($request))]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage workplaces');
    }
}
