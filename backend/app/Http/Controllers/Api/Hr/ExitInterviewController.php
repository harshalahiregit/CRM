<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\ExitInterviewService;
use Illuminate\Http\Request;

class ExitInterviewController extends Controller
{
    public function __construct(private ExitInterviewService $service)
    {
    }

    /** Row-level tenancy: guard route-model binding the same way the HR module does. */
    private function assertTenant(Request $request, HrEmployee $employee): void
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'Not allowed to manage HR records');
    }

    /* GET /api/hr/exit-interviews */
    public function index(Request $request)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->list($request->user()->tenant_id));
    }

    /* GET /api/hr/employees/{employee}/exit-interview — prefill + any saved answers */
    public function show(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        return response()->json([
            'prefill' => $this->service->prefill($employee, $request->user()->tenant?->name),
            'record'  => $employee->exitInterview()->first(),
        ]);
    }

    /* POST /api/hr/employees/{employee}/exit-interview */
    public function store(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $data = $request->validate([
            'organization_or_project' => 'nullable|string|max:255',
            'personal_mobile'         => 'nullable|string|max:20',
            'personal_email'          => 'nullable|email|max:255',
            'exit_date'               => 'nullable|date',
            'reason_for_leaving'      => 'nullable|string|max:2000',
            'return_circumstances'    => 'nullable|string|max:2000',
            'recognition_feedback'    => 'nullable|string|max:2000',
            'policies_feedback'       => 'nullable|string|max:2000',
            'jd_changed_feedback'     => 'nullable|string|max:2000',
            'tools_resources_feedback' => 'nullable|string|max:2000',
            'training_feedback'       => 'nullable|string|max:2000',
            'best_part'               => 'nullable|string|max:2000',
            'improvements'            => 'nullable|string|max:2000',
            'morale_suggestions'      => 'nullable|string|max:2000',
            'looking_forward_to'      => 'nullable|string|max:2000',
            'ideal_replacement'       => 'nullable|string|max:2000',
            'would_recommend'         => 'nullable|string|max:2000',
            'rating'                  => 'nullable|integer|min:1|max:5',
            'submit'                  => 'nullable|boolean',
        ]);

        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit']);

        return response()->json(
            $this->service->save($employee, $data, $request->user(), $submit),
            201
        );
    }
}
