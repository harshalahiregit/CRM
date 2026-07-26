<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrPerformanceReview;
use App\Services\Hr\PerformanceReviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Performance Reviews (PMS Phase 4). Thin controller; tenant-scoped, HR-gated
 * writes, audited via the service.
 */
class PerformanceReviewController extends Controller
{
    public function __construct(private PerformanceReviewService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'review_type', 'status', 'year'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $this->validated($request);

        return response()->json($this->service->create($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $this->validated($request, true);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['status' => ['required', Rule::in(HrPerformanceReview::STATUSES)]]);

        return response()->json($this->service->setStatus($id, $data['status'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'employee_id'    => "$req|integer",
            'review_type'    => [$partial ? 'sometimes' : 'required', Rule::in(HrPerformanceReview::TYPES)],
            'period_month'   => 'nullable|integer|min:1|max:12',
            'period_year'    => 'nullable|integer|min:2000|max:2100',
            'period_label'   => 'nullable|string|max:60',
            'reviewer_name'  => 'nullable|string|max:120',
            'overall_rating' => 'nullable|numeric|min:0|max:10',
            'comments'       => 'nullable|string',
            'strengths'      => 'nullable|string',
            'improvements'   => 'nullable|string',
            'recommendation' => 'nullable|string',
            'kpis'                 => 'nullable|array',
            'kpis.*.kpi_id'        => 'nullable|integer',
            'kpis.*.kpi_name'      => 'nullable|string|max:120',
            'kpis.*.weightage'     => 'nullable|numeric|min:0|max:100',
            'kpis.*.rating'        => 'nullable|numeric|min:0|max:10',
            'kpis.*.comment'       => 'nullable|string',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage reviews');
    }
}
