<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrPerformanceReview;
use App\Models\User;
use App\Repositories\Hr\PerformanceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Performance Reviews (PMS Phase 4). Reviews carry per-KPI ratings; the overall
 * rating is a weighted average of those ratings. Lifecycle: Draft → Submitted →
 * Reviewed → Approved. An Approved review is finalized and immutable.
 */
class PerformanceReviewService
{
    public function __construct(private PerformanceRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return $this->repo->reviews($tenantId, $f)->map(fn ($r) => $this->presentRow($r))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->presentFull($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($data['employee_id']);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }
        $kpis = $this->cleanKpis($data['kpis'] ?? []);
        $overall = $this->overallRating($kpis, $data['overall_rating'] ?? null);

        $review = DB::transaction(function () use ($data, $tenantId, $employee, $kpis, $overall, $actor) {
            $review = HrPerformanceReview::create([
                'tenant_id'      => $tenantId,
                'employee_id'    => $employee->id,
                'reviewer_name'  => $data['reviewer_name'] ?? $actor?->name,
                'reviewer_id'    => $actor?->id,
                'department'     => $employee->department,
                'designation'    => $employee->designation,
                'review_type'    => $data['review_type'],
                'period_month'   => $data['period_month'] ?? null,
                'period_year'    => $data['period_year'] ?? null,
                'period_label'   => $data['period_label'] ?? null,
                'overall_rating' => $overall,
                'comments'       => $data['comments'] ?? null,
                'strengths'      => $data['strengths'] ?? null,
                'improvements'   => $data['improvements'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
                'status'         => HrPerformanceReview::STATUSES[0],
                'created_by'     => $actor?->id,
            ]);
            $this->syncKpis($review, $kpis, $tenantId);

            return $review;
        });

        $review->recordAudit('Review Created', $actor, null, ['employee_id' => $employee->id, 'type' => $review->review_type]);
        $this->log('Review created', $tenantId, $review->id);

        return $this->show($review->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        if ($review->status === 'Approved') {
            throw new BusinessException('An approved review is finalized and cannot be modified.');
        }

        DB::transaction(function () use ($review, $data, $tenantId) {
            $attrs = array_filter([
                'review_type'    => $data['review_type'] ?? null,
                'period_month'   => $data['period_month'] ?? null,
                'period_year'    => $data['period_year'] ?? null,
                'period_label'   => $data['period_label'] ?? null,
                'reviewer_name'  => $data['reviewer_name'] ?? null,
                'comments'       => $data['comments'] ?? null,
                'strengths'      => $data['strengths'] ?? null,
                'improvements'   => $data['improvements'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
            ], fn ($v) => $v !== null);

            if (array_key_exists('kpis', $data)) {
                $kpis = $this->cleanKpis($data['kpis']);
                $review->kpiRatings()->delete();
                $this->syncKpis($review, $kpis, $tenantId);
                $attrs['overall_rating'] = $this->overallRating($kpis, $data['overall_rating'] ?? null);
            } elseif (array_key_exists('overall_rating', $data)) {
                $attrs['overall_rating'] = $data['overall_rating'];
            }

            $review->update($attrs);
        });

        $review->recordAudit('Review Updated', $actor);

        return $this->show($id, $tenantId);
    }

    /** Advance the review lifecycle. Approved is terminal. */
    public function setStatus(int $id, string $status, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        if (! in_array($status, HrPerformanceReview::STATUSES, true)) {
            throw new BusinessException('Invalid review status.');
        }
        if ($review->status === 'Approved') {
            throw new BusinessException('An approved review is finalized and cannot be changed.');
        }

        $attrs = ['status' => $status];
        if ($status === 'Submitted') {
            $attrs['submitted_at'] = now();
        }
        if ($status === 'Approved') {
            $attrs['approved_at'] = now();
        }
        $review->update($attrs);
        $review->recordAudit('Review '.$status, $actor);
        $this->log('Review status changed', $tenantId, $review->id);

        return $this->show($id, $tenantId);
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function cleanKpis(array $kpis): array
    {
        $clean = [];
        foreach ($kpis as $k) {
            if (empty($k['kpi_name']) && empty($k['kpi_id'])) {
                continue;
            }
            $clean[] = [
                'kpi_id'    => $k['kpi_id'] ?? null,
                'kpi_name'  => $k['kpi_name'] ?? 'KPI',
                'weightage' => (float) ($k['weightage'] ?? 0),
                'rating'    => (float) ($k['rating'] ?? 0),
                'comment'   => $k['comment'] ?? null,
            ];
        }

        return $clean;
    }

    private function overallRating(array $kpis, $provided): float
    {
        if (empty($kpis)) {
            return round((float) ($provided ?? 0), 2);
        }
        $wsum = array_sum(array_column($kpis, 'weightage'));
        if ($wsum > 0) {
            $weighted = array_sum(array_map(fn ($k) => $k['rating'] * $k['weightage'], $kpis));

            return round($weighted / $wsum, 2);
        }

        return round(array_sum(array_column($kpis, 'rating')) / count($kpis), 2);
    }

    private function syncKpis(HrPerformanceReview $review, array $kpis, int $tenantId): void
    {
        foreach ($kpis as $k) {
            $review->kpiRatings()->create([...$k, 'tenant_id' => $tenantId]);
        }
    }

    private function presentRow(HrPerformanceReview $r): array
    {
        return [
            'id' => $r->id, 'employee_id' => $r->employee_id,
            'employee_name' => $r->employee?->name, 'employee_code' => $r->employee?->employee_code,
            'department' => $r->department, 'designation' => $r->designation,
            'review_type' => $r->review_type, 'period_label' => $r->period_label,
            'overall_rating' => (float) $r->overall_rating, 'status' => $r->status,
        ];
    }

    private function presentFull(HrPerformanceReview $r): array
    {
        return [
            ...$this->presentRow($r),
            'reviewer_name'  => $r->reviewer_name,
            'period_month'   => $r->period_month, 'period_year' => $r->period_year,
            'comments'       => $r->comments, 'strengths' => $r->strengths,
            'improvements'   => $r->improvements, 'recommendation' => $r->recommendation,
            'submitted_at'   => optional($r->submitted_at)->toIso8601String(),
            'approved_at'    => optional($r->approved_at)->toIso8601String(),
            'kpis'           => $r->kpiRatings->map(fn ($k) => [
                'kpi_id' => $k->kpi_id, 'kpi_name' => $k->kpi_name,
                'weightage' => (float) $k->weightage, 'rating' => (float) $k->rating, 'comment' => $k->comment,
            ])->all(),
        ];
    }

    private function find(int $id, int $tenantId): HrPerformanceReview
    {
        $review = $this->repo->findReview($id, $tenantId);
        if (! $review) {
            throw new BusinessException('Review not found', 404);
        }

        return $review;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
