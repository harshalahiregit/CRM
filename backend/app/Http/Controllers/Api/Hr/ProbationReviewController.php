<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ProbationReviewService;
use Illuminate\Http\Request;

/**
 * Probation Management → Probation Reviews (Phase 3). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class ProbationReviewController extends Controller
{
    public function __construct(private ProbationReviewService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'employee_probation_id', 'reviewer_id', 'department', 'status', 'recommendation', 'from', 'to', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->create($this->validated($request), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user()));
    }

    public function submit(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function complete(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->complete($id, $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $rate = 'nullable|integer|min:1|max:5';

        return $request->validate([
            'employee_probation_id' => "$req|integer",
            'reviewer_id'           => "$req|integer",
            'recommendation'        => "$req|in:Continue,Extend,Confirm,Fail",
            'review_no'             => 'nullable|integer|min:1',
            'review_date'           => 'nullable|date',
            'overall_rating'        => $rate,
            'technical_rating'      => $rate,
            'behaviour_rating'      => $rate,
            'attendance_rating'     => $rate,
            'communication_rating'  => $rate,
            'strengths'             => 'nullable|string',
            'improvements'          => 'nullable|string',
            'manager_comments'      => 'nullable|string',
            'hr_comments'           => 'nullable|string',
            'status'                => 'nullable|in:Draft,Submitted',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage probation reviews');
    }
}
