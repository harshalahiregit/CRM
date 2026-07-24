<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\PerformanceService;
use Illuminate\Http\Request;

/**
 * Performance Management — dashboard, KPI master, Goals, assignments, and the
 * read-only employee timeline. Thin: validate, delegate, return JSON. Writes
 * require HR-queue management; all tenant-scoped and audited via the service.
 */
class PerformanceController extends Controller
{
    public function __construct(private PerformanceService $service)
    {
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->service->dashboard($this->tenant($request)));
    }

    public function timeline(Request $request, int $employeeId)
    {
        return response()->json($this->service->timeline($employeeId, $this->tenant($request)));
    }

    /* ── KPIs ── */
    public function kpis(Request $request)
    {
        return response()->json($this->service->listKpis($this->tenant($request), $request->only(['status', 'search'])));
    }

    public function storeKpi(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'category' => 'nullable|string|max:80',
            'description' => 'nullable|string', 'weightage' => 'nullable|numeric|min:0|max:100',
            'rating_scale' => 'nullable|integer|min:1|max:10', 'is_active' => 'boolean',
        ]);

        return response()->json($this->service->createKpi($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateKpi(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120', 'category' => 'nullable|string|max:80',
            'description' => 'nullable|string', 'weightage' => 'nullable|numeric|min:0|max:100',
            'rating_scale' => 'nullable|integer|min:1|max:10', 'is_active' => 'boolean',
        ]);

        return response()->json($this->service->updateKpi($id, $data, $this->tenant($request), $request->user()));
    }

    public function kpiStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setKpiStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    /* ── Goals ── */
    public function goals(Request $request)
    {
        return response()->json($this->service->listGoals($this->tenant($request), $request->only(['department', 'status', 'search'])));
    }

    public function storeGoal(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'description' => 'nullable|string',
            'department' => 'nullable|string|max:120', 'designation' => 'nullable|string|max:120',
            'weightage' => 'nullable|numeric|min:0|max:100', 'target' => 'nullable|string|max:255',
            'due_date' => 'nullable|date', 'status' => 'nullable|string|max:40',
        ]);

        return response()->json($this->service->createGoal($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateGoal(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:150', 'description' => 'nullable|string',
            'department' => 'nullable|string|max:120', 'designation' => 'nullable|string|max:120',
            'weightage' => 'nullable|numeric|min:0|max:100', 'target' => 'nullable|string|max:255',
            'due_date' => 'nullable|date', 'status' => 'nullable|string|max:40',
        ]);

        return response()->json($this->service->updateGoal($id, $data, $this->tenant($request), $request->user()));
    }

    /* ── Assignments ── */
    public function assignments(Request $request)
    {
        return response()->json($this->service->listEmployeeGoals($this->tenant($request), $request->only(['employee_id', 'status'])));
    }

    public function assignGoal(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'goal_id' => 'required|integer',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
        ]);

        return response()->json($this->service->assignGoal((int) $data['goal_id'], $data['employee_ids'], $this->tenant($request), $request->user()), 201);
    }

    public function updateAssignment(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'progress' => 'nullable|integer|min:0|max:100',
            'status' => 'nullable|in:Active,Completed',
        ]);

        return response()->json($this->service->updateAssignment($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage performance');
    }
}
