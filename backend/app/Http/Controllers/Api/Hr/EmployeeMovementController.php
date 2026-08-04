<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployeeMovement;
use App\Services\Hr\EmployeeMovementService;
use App\Services\Hr\EmployeeSkillService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review comments #41 (department transfer), #42 (promotion/demotion) and #43
 * (skill fit). Thin: validate, delegate, return JSON.
 *
 * Every write requires HR-queue management — a movement changes someone's
 * department, title and reporting line.
 */
class EmployeeMovementController extends Controller
{
    public function __construct(
        private EmployeeMovementService $movements,
        private EmployeeSkillService $skills,
    ) {
    }

    public function meta()
    {
        return response()->json(['movement_types' => HrEmployeeMovement::TYPES]);
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->movements->list($this->tenant($request),
                $request->only(['movement_type', 'employee_id', 'from', 'to'])),
        ]);
    }

    public function history(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->movements->history($employeeId, $this->tenant($request))]);
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id'       => 'required|integer',
            'movement_type'     => ['nullable', Rule::in(HrEmployeeMovement::TYPES)],
            'effective_date'    => 'required|date',
            'to_department_id'  => 'nullable|integer',
            'to_department'     => 'nullable|string|max:150',
            'to_designation_id' => 'nullable|integer',
            'to_designation'    => 'nullable|string|max:150',
            'to_grade_id'       => 'nullable|integer',
            'to_manager_id'     => 'nullable|integer',
            'reason'            => 'nullable|string|max:1000',
            'remarks'           => 'nullable|string|max:1000',
        ]);

        return response()->json($this->movements->move($data, $this->tenant($request), $request->user()), 201);
    }

    /** Turn an existing promotion recommendation into an actual movement. */
    public function actionRecommendation(Request $request, int $recommendationId)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'movement_type'  => ['nullable', Rule::in(HrEmployeeMovement::TYPES)],
            'effective_date' => 'nullable|date',
            'reason'         => 'nullable|string|max:1000',
        ]);

        return response()->json(
            $this->movements->actionRecommendation($recommendationId, $data, $this->tenant($request), $request->user()),
            201
        );
    }

    /* ── #43 skill fit ────────────────────────────────────────────────── */

    public function skills(Request $request, int $employeeId)
    {
        $employee = \App\Models\Hr\HrEmployee::where('tenant_id', $this->tenant($request))->findOrFail($employeeId);

        return response()->json($this->skills->analyse($employee, $this->tenant($request)));
    }

    public function updateSkills(Request $request, int $employeeId)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'skills'   => 'present|array',
            'skills.*' => 'string|max:60',
        ]);

        return response()->json($this->skills->setSkills($employeeId, $data['skills'], $this->tenant($request), $request->user()));
    }

    /** Fit against a position the employee is not in yet — checked before moving. */
    public function previewSkills(Request $request, int $employeeId)
    {
        $data = $request->validate([
            'department_id'  => 'nullable|integer',
            'designation_id' => 'nullable|integer',
            'grade_id'       => 'nullable|integer',
            'job_role_id'    => 'nullable|integer',
        ]);

        return response()->json($this->skills->preview($employeeId, $this->tenant($request), $data));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to move employees');
    }
}
