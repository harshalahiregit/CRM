<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\RecommendationService;
use Illuminate\Http\Request;

/**
 * Promotion (Phase 5) & Increment (Phase 6) recommendations. Recommendation-only —
 * never modifies Organization Setup or Payroll. Tenant-scoped, HR-gated writes.
 */
class RecommendationController extends Controller
{
    public function __construct(private RecommendationService $service)
    {
    }

    /* ── Promotion ── */
    public function promotions(Request $request)
    {
        return response()->json($this->service->listPromotions($this->tenant($request), $request->only(['employee_id', 'status'])));
    }

    public function generatePromotion(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['employee_id' => 'required|integer']);

        return response()->json($this->service->generatePromotion((int) $data['employee_id'], $this->tenant($request), $request->user()), 201);
    }

    public function promotionStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'status' => 'required|string',
            'recommended_designation' => 'nullable|string|max:120',
            'salary_structure_id' => 'nullable|integer|exists:hr_salary_structures,id',
        ]);

        return response()->json($this->service->updatePromotionStatus($id, $data['status'], $this->tenant($request), $request->user(), $data['recommended_designation'] ?? null, $data['salary_structure_id'] ?? null));
    }

    /* ── Increment ── */
    public function increments(Request $request)
    {
        return response()->json($this->service->listIncrements($this->tenant($request), $request->only(['employee_id', 'status'])));
    }

    public function generateIncrement(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['employee_id' => 'required|integer']);

        return response()->json($this->service->generateIncrement((int) $data['employee_id'], $this->tenant($request), $request->user()), 201);
    }

    public function incrementStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['status' => 'required|string']);

        return response()->json($this->service->updateIncrementStatus($id, $data['status'], $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage recommendations');
    }
}
