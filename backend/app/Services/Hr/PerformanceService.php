<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeGoal;
use App\Models\Hr\HrGoal;
use App\Models\Hr\HrIncrementRecommendation;
use App\Models\Hr\HrKpi;
use App\Models\Hr\HrPerformanceReview;
use App\Models\Hr\HrPromotionRecommendation;
use App\Models\User;
use App\Repositories\Hr\PerformanceRepository;
use Illuminate\Support\Facades\Log;

/**
 * Performance Management — catalog (KPIs, Goals, assignments), dashboard and the
 * read-only employee timeline (PMS Phases 1–3, 7). Tenant-scoped and audited.
 */
class PerformanceService
{
    public function __construct(private PerformanceRepository $repo)
    {
    }

    public function dashboard(int $tenantId): array
    {
        return $this->repo->dashboard($tenantId);
    }

    /* ── KPI master ───────────────────────────────────────── */
    public function listKpis(int $tenantId, array $f): array
    {
        return $this->repo->kpis($tenantId, $f)->all();
    }

    public function createKpi(array $data, int $tenantId, ?User $actor = null): HrKpi
    {
        $this->assertUniqueKpi($tenantId, $data['name']);
        $kpi = HrKpi::create([...$this->kpiAttrs($data), 'tenant_id' => $tenantId]);
        $kpi->recordAudit('KPI Created', $actor, null, ['name' => $kpi->name]);
        $this->log('KPI created', $tenantId, $kpi->id);

        return $kpi;
    }

    public function updateKpi(int $id, array $data, int $tenantId, ?User $actor = null): HrKpi
    {
        $kpi = $this->findKpi($id, $tenantId);
        if (isset($data['name'])) {
            $this->assertUniqueKpi($tenantId, $data['name'], $kpi->id);
        }
        $kpi->update($this->kpiAttrs($data));
        $kpi->recordAudit('KPI Updated', $actor, null, ['name' => $kpi->name]);

        return $kpi->fresh();
    }

    public function setKpiStatus(int $id, bool $active, int $tenantId, ?User $actor = null): HrKpi
    {
        $kpi = $this->findKpi($id, $tenantId);
        $kpi->update(['is_active' => $active]);
        $kpi->recordAudit($active ? 'KPI Activated' : 'KPI Deactivated', $actor);

        return $kpi->fresh();
    }

    private function kpiAttrs(array $d): array
    {
        return array_filter([
            'name'         => $d['name'] ?? null,
            'category'     => $d['category'] ?? null,
            'description'  => $d['description'] ?? null,
            'weightage'    => $d['weightage'] ?? null,
            'rating_scale' => $d['rating_scale'] ?? null,
            'is_active'    => $d['is_active'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /* ── Goals ────────────────────────────────────────────── */
    public function listGoals(int $tenantId, array $f): array
    {
        return $this->repo->goals($tenantId, $f)->map(fn ($g) => [
            'id' => $g->id, 'title' => $g->title, 'description' => $g->description,
            'department' => $g->department, 'designation' => $g->designation,
            'weightage' => (float) $g->weightage, 'target' => $g->target,
            'due_date' => optional($g->due_date)->toDateString(), 'status' => $g->status,
            'assignments_count' => $g->assignments_count,
        ])->all();
    }

    public function createGoal(array $data, int $tenantId, ?User $actor = null): HrGoal
    {
        $goal = HrGoal::create([...$this->goalAttrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id]);
        $goal->recordAudit('Goal Created', $actor, null, ['title' => $goal->title]);
        $this->log('Goal created', $tenantId, $goal->id);

        return $goal;
    }

    public function updateGoal(int $id, array $data, int $tenantId, ?User $actor = null): HrGoal
    {
        $goal = $this->findGoal($id, $tenantId);
        $goal->update($this->goalAttrs($data));
        $goal->recordAudit('Goal Updated', $actor, null, ['title' => $goal->title]);

        return $goal->fresh();
    }

    private function goalAttrs(array $d): array
    {
        return array_filter([
            'title'       => $d['title'] ?? null,
            'description' => $d['description'] ?? null,
            'department'  => $d['department'] ?? null,
            'designation' => $d['designation'] ?? null,
            'weightage'   => $d['weightage'] ?? null,
            'target'      => $d['target'] ?? null,
            'due_date'    => $d['due_date'] ?? null,
            'status'      => $d['status'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /* ── Employee goal assignments ────────────────────────── */
    public function listEmployeeGoals(int $tenantId, array $f): array
    {
        return $this->repo->employeeGoals($tenantId, $f)->map(fn ($a) => $this->presentAssignment($a))->all();
    }

    /** Assign a goal to one or more employees (idempotent per employee). */
    public function assignGoal(int $goalId, array $employeeIds, int $tenantId, ?User $actor = null): array
    {
        $goal = $this->findGoal($goalId, $tenantId);
        $assigned = 0;
        foreach ($employeeIds as $eid) {
            $eid = (int) $eid;
            if (! HrEmployee::where('tenant_id', $tenantId)->where('id', $eid)->exists()) {
                continue;
            }
            $exists = HrEmployeeGoal::where('tenant_id', $tenantId)->where('goal_id', $goalId)->where('employee_id', $eid)->exists();
            if ($exists) {
                continue;
            }
            $a = HrEmployeeGoal::create([
                'tenant_id' => $tenantId, 'goal_id' => $goalId, 'employee_id' => $eid,
                'status' => 'Active', 'progress' => 0, 'assigned_by' => $actor?->id, 'assigned_at' => now(),
            ]);
            $a->recordAudit('Goal Assigned', $actor, null, ['goal' => $goal->title, 'employee_id' => $eid]);
            $assigned++;
        }
        $this->log('Goals assigned', $tenantId, $goalId);

        return ['assigned' => $assigned];
    }

    public function updateAssignment(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $a = $this->repo->findEmployeeGoal($id, $tenantId);
        if (! $a) {
            throw new BusinessException('Assignment not found', 404);
        }
        $attrs = [];
        if (array_key_exists('progress', $data)) {
            $attrs['progress'] = max(0, min(100, (int) $data['progress']));
        }
        if (array_key_exists('status', $data)) {
            $attrs['status'] = $data['status'];
            $attrs['completed_at'] = $data['status'] === 'Completed' ? now() : null;
        }
        // Auto-complete at 100%.
        if (($attrs['progress'] ?? $a->progress) >= 100 && ! isset($attrs['status'])) {
            $attrs['status'] = 'Completed';
            $attrs['completed_at'] = now();
        }
        $a->update($attrs);
        $a->recordAudit('Goal Progress Updated', $actor, null, ['progress' => $a->progress, 'status' => $a->status]);

        return $this->presentAssignment($a->fresh(['goal', 'employee']));
    }

    private function presentAssignment(HrEmployeeGoal $a): array
    {
        return [
            'id' => $a->id, 'goal_id' => $a->goal_id, 'employee_id' => $a->employee_id,
            'goal_title' => $a->goal?->title, 'weightage' => (float) ($a->goal?->weightage ?? 0),
            'target' => $a->goal?->target, 'due_date' => optional($a->goal?->due_date)->toDateString(),
            'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
            'department' => $a->employee?->department,
            'status' => $a->status, 'progress' => $a->progress,
        ];
    }

    /* ── Employee performance timeline (Phase 7, read-only) ── */
    public function timeline(int $employeeId, int $tenantId): array
    {
        $this->assertEmployee($employeeId, $tenantId);

        $goals = $this->repo->employeeGoals($tenantId, ['employee_id' => $employeeId]);
        $reviews = $this->repo->reviews($tenantId, ['employee_id' => $employeeId]);
        $promotions = $this->repo->promotions($tenantId, ['employee_id' => $employeeId]);
        $increments = $this->repo->increments($tenantId, ['employee_id' => $employeeId]);

        return [
            'goals' => $goals->map(fn ($a) => $this->presentAssignment($a))->all(),
            'reviews' => $reviews->map(fn ($r) => [
                'id' => $r->id, 'review_type' => $r->review_type, 'period_label' => $r->period_label,
                'overall_rating' => (float) $r->overall_rating, 'status' => $r->status,
                'recommendation' => $r->recommendation,
            ])->all(),
            'promotions' => $promotions->map(fn ($p) => [
                'id' => $p->id, 'eligible' => $p->eligible, 'status' => $p->status,
                'recommended_designation' => $p->recommended_designation, 'reason' => $p->reason,
                'overall_rating' => (float) $p->overall_rating, 'created_at' => optional($p->created_at)->toDateString(),
            ])->all(),
            'increments' => $increments->map(fn ($i) => [
                'id' => $i->id, 'current_salary' => (float) $i->current_salary,
                'suggested_percentage' => (float) $i->suggested_percentage, 'suggested_amount' => (float) $i->suggested_amount,
                'approval_status' => $i->approval_status, 'reason' => $i->reason,
                'created_at' => optional($i->created_at)->toDateString(),
            ])->all(),
        ];
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function findKpi(int $id, int $tenantId): HrKpi
    {
        $kpi = $this->repo->findKpi($id, $tenantId);
        if (! $kpi) {
            throw new BusinessException('KPI not found', 404);
        }

        return $kpi;
    }

    private function findGoal(int $id, int $tenantId): HrGoal
    {
        $goal = $this->repo->findGoal($id, $tenantId);
        if (! $goal) {
            throw new BusinessException('Goal not found', 404);
        }

        return $goal;
    }

    private function assertUniqueKpi(int $tenantId, ?string $name, ?int $ignoreId = null): void
    {
        $exists = HrKpi::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A KPI named “{$name}” already exists.");
        }
    }

    private function assertEmployee(int $employeeId, int $tenantId): void
    {
        if (! HrEmployee::where('tenant_id', $tenantId)->where('id', $employeeId)->exists()) {
            throw new BusinessException('Employee not found', 404);
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
