<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeGoal;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrIncrementRecommendation;
use App\Models\Hr\HrPerformanceReview;
use App\Models\Hr\HrPromotionRecommendation;
use App\Models\User;
use App\Repositories\Hr\PerformanceRepository;
use Illuminate\Support\Facades\Log;

/**
 * Promotion (Phase 5) & Increment (Phase 6) recommendations.
 *
 * Both are RECOMMENDATIONS ONLY. The increment reads the current salary from
 * hr_employee_salaries read-only and never modifies Payroll — any real salary
 * revision happens later in the Payroll module.
 */
class RecommendationService
{
    public function __construct(
        private PerformanceRepository $repo,
        private EmployeeSalaryService $employeeSalaries,
    ) {
    }

    /* ── Promotion ────────────────────────────────────────── */
    public function listPromotions(int $tenantId, array $f): array
    {
        return $this->repo->promotions($tenantId, $f)->map(fn ($p) => $this->presentPromotion($p))->all();
    }

    /** Derive an eligibility recommendation from the latest review + completed goals. */
    public function generatePromotion(int $employeeId, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee($employeeId, $tenantId);
        [$rating, $review] = $this->latestRating($employeeId, $tenantId);
        $completedGoals = HrEmployeeGoal::where('tenant_id', $tenantId)->where('employee_id', $employeeId)->where('status', 'Completed')->count();

        $eligible = $rating >= 4.0 && $completedGoals >= 1;
        $reason = $eligible
            ? "Overall rating {$rating} with {$completedGoals} completed goal(s) meets the promotion threshold."
            : "Overall rating {$rating} / completed goals {$completedGoals} below the promotion threshold (rating ≥ 4.0 and ≥ 1 completed goal).";

        $rec = HrPromotionRecommendation::create([
            'tenant_id'           => $tenantId,
            'employee_id'         => $employeeId,
            'review_id'           => $review?->id,
            'eligible'            => $eligible,
            'overall_rating'      => $rating,
            'completed_goals'     => $completedGoals,
            'current_designation' => $employee->designation,
            'recommended_designation' => null,
            'reason'              => $reason,
            'status'              => 'Pending',
            'created_by'          => $actor?->id,
        ]);
        $rec->recordAudit('Promotion Recommendation Generated', $actor, null, ['eligible' => $eligible, 'rating' => $rating]);
        $this->log('Promotion recommendation generated', $tenantId, $rec->id);

        return $this->presentPromotion($rec->fresh('employee'));
    }

    public function updatePromotionStatus(int $id, string $status, int $tenantId, ?User $actor = null, ?string $recommendedDesignation = null, ?int $salaryStructureId = null): array
    {
        if (! in_array($status, HrPromotionRecommendation::STATUSES, true)) {
            throw new BusinessException('Invalid status.');
        }
        $rec = $this->repo->findPromotion($id, $tenantId);
        if (! $rec) {
            throw new BusinessException('Promotion recommendation not found', 404);
        }
        $wasApproved = $rec->status === 'Approved';
        $rec->update(array_filter([
            'status' => $status,
            'recommended_designation' => $recommendedDesignation,
        ], fn ($v) => $v !== null));
        $rec->recordAudit('Promotion '.$status, $actor);

        // Salary Engine: a promotion may optionally assign a new Salary Structure, which
        // creates a salary revision via the standard assignment path. Only on the first
        // transition into Approved, so re-approving never re-assigns.
        if ($status === 'Approved' && ! $wasApproved && $salaryStructureId) {
            $this->employeeSalaries->assign($rec->employee_id, [
                'salary_structure_id' => $salaryStructureId,
                'effective_from'      => now()->toDateString(),
                'reason'              => 'Promotion'.($recommendedDesignation ? ' to '.$recommendedDesignation : ''),
            ], $tenantId, $actor);
            $rec->recordAudit('Promotion Salary Structure Assigned', $actor, null, ['salary_structure_id' => $salaryStructureId]);
        }

        return $this->presentPromotion($rec->fresh('employee'));
    }

    /* ── Increment ────────────────────────────────────────── */
    public function listIncrements(int $tenantId, array $f): array
    {
        return $this->repo->increments($tenantId, $f)->map(fn ($i) => $this->presentIncrement($i))->all();
    }

    /** Suggest an increment from the current active salary + latest rating. Read-only on Payroll. */
    public function generateIncrement(int $employeeId, int $tenantId, ?User $actor = null): array
    {
        $this->employee($employeeId, $tenantId);
        [$rating, $review] = $this->latestRating($employeeId, $tenantId);

        // Current salary is READ from Payroll — never modified here.
        $salary = HrEmployeeSalary::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->where('status', 'active')->latest('id')->first();
        $current = (float) ($salary->annual_ctc ?? 0);

        $pct = match (true) {
            $rating >= 4.5 => 15.0,
            $rating >= 4.0 => 10.0,
            $rating >= 3.0 => 5.0,
            default        => 0.0,
        };
        $amount = round($current * $pct / 100, 2);
        $reason = $current > 0
            ? "Based on overall rating {$rating}, a {$pct}% increment is suggested on the current annual CTC."
            : "No active salary on record — assign a salary before recommending an increment.";

        $rec = HrIncrementRecommendation::create([
            'tenant_id'            => $tenantId,
            'employee_id'          => $employeeId,
            'review_id'            => $review?->id,
            'current_salary'       => $current,
            'suggested_percentage' => $pct,
            'suggested_amount'     => $amount,
            'reason'               => $reason,
            'approval_status'      => 'Pending',
            'created_by'           => $actor?->id,
        ]);
        $rec->recordAudit('Increment Recommendation Generated', $actor, null, ['percentage' => $pct, 'amount' => $amount]);
        $this->log('Increment recommendation generated', $tenantId, $rec->id);

        return $this->presentIncrement($rec->fresh('employee'));
    }

    public function updateIncrementStatus(int $id, string $status, int $tenantId, ?User $actor = null): array
    {
        if (! in_array($status, HrIncrementRecommendation::STATUSES, true)) {
            throw new BusinessException('Invalid status.');
        }
        $rec = $this->repo->findIncrement($id, $tenantId);
        if (! $rec) {
            throw new BusinessException('Increment recommendation not found', 404);
        }
        $wasApproved = $rec->approval_status === 'Approved';
        $rec->update(['approval_status' => $status]);
        $rec->recordAudit('Increment '.$status, $actor);

        // Salary Engine: approving an increment creates a salary revision automatically
        // (scales the current CTC — same structure). Idempotent: only on the first
        // transition into Approved, so re-approving never stacks revisions.
        if ($status === 'Approved' && ! $wasApproved) {
            $applied = $this->employeeSalaries->applyIncrement($rec->employee_id, [
                'percentage' => (float) $rec->suggested_percentage,
                'amount'     => (float) $rec->suggested_amount,
                'reason'     => 'Performance increment ('.rtrim(rtrim((string) $rec->suggested_percentage, '0'), '.').'%)',
            ], $tenantId, $actor);
            if ($applied) {
                $rec->recordAudit('Increment Applied to Salary', $actor, null, ['percentage' => (float) $rec->suggested_percentage]);
            }
        }

        return $this->presentIncrement($rec->fresh('employee'));
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function latestRating(int $employeeId, int $tenantId): array
    {
        $review = HrPerformanceReview::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->whereIn('status', ['Reviewed', 'Approved'])->latest('id')->first();

        return [(float) ($review->overall_rating ?? 0), $review];
    }

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function presentPromotion(HrPromotionRecommendation $p): array
    {
        return [
            'id' => $p->id, 'employee_id' => $p->employee_id,
            'employee_name' => $p->employee?->name, 'employee_code' => $p->employee?->employee_code,
            'department' => $p->employee?->department,
            'eligible' => $p->eligible, 'overall_rating' => (float) $p->overall_rating,
            'completed_goals' => $p->completed_goals,
            'current_designation' => $p->current_designation, 'recommended_designation' => $p->recommended_designation,
            'reason' => $p->reason, 'status' => $p->status,
            'created_at' => optional($p->created_at)->toDateString(),
        ];
    }

    private function presentIncrement(HrIncrementRecommendation $i): array
    {
        return [
            'id' => $i->id, 'employee_id' => $i->employee_id,
            'employee_name' => $i->employee?->name, 'employee_code' => $i->employee?->employee_code,
            'department' => $i->employee?->department,
            'current_salary' => (float) $i->current_salary,
            'suggested_percentage' => (float) $i->suggested_percentage,
            'suggested_amount' => (float) $i->suggested_amount,
            'reason' => $i->reason, 'approval_status' => $i->approval_status,
            'created_at' => optional($i->created_at)->toDateString(),
        ];
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
