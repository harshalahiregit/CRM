<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationReview;
use App\Models\User;
use App\Repositories\Hr\ProbationReviewRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Probation Reviews (Probation Phase 3). Periodic reviews on an Active/Extended
 * employee probation. Ratings are 1-5; recommendation is advisory only (no
 * automatic extension/confirmation yet). Lifecycle: Draft → Submitted → Completed;
 * Submitted never returns to Draft, Completed is read-only. One review number per
 * probation. Tenant-scoped, audited.
 */
class ProbationReviewService
{
    public function __construct(private ProbationReviewRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->list($tenantId, $f)->map(fn ($r) => $this->present($r))->all(),
            'stats' => $this->repo->stats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        $review->recordAudit('Probation Review Viewed', $actor);

        return $this->present($review, true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($r) => $this->present($r, true))->all();
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->probation((int) ($data['employee_probation_id'] ?? 0), $tenantId);
        if (! in_array($probation->current_status, [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED], true)) {
            throw new BusinessException('Reviews can only be recorded for an active or extended probation.');
        }
        $reviewer = $this->reviewer($data['reviewer_id'] ?? null, $tenantId);
        $ratings = $this->ratings($data);
        $recommendation = $this->recommendation($data['recommendation'] ?? null);

        $reviewNo = ! empty($data['review_no']) ? (int) $data['review_no'] : $this->repo->nextReviewNo($probation->id, $tenantId);
        if ($this->repo->reviewNoExists($probation->id, $reviewNo, $tenantId)) {
            throw new BusinessException("Review number {$reviewNo} already exists for this probation.");
        }

        $status = ($data['status'] ?? HrProbationReview::DRAFT) === HrProbationReview::SUBMITTED
            ? HrProbationReview::SUBMITTED : HrProbationReview::DRAFT;

        $review = HrProbationReview::create([
            'tenant_id' => $tenantId,
            'employee_probation_id' => $probation->id,
            'employee_id' => $probation->employee_id,
            'review_no' => $reviewNo,
            'review_date' => Carbon::parse($data['review_date'] ?? now()->toDateString())->toDateString(),
            'reviewer_id' => $reviewer?->id,
            ...$ratings,
            'strengths' => $data['strengths'] ?? null,
            'improvements' => $data['improvements'] ?? null,
            'manager_comments' => $data['manager_comments'] ?? null,
            'hr_comments' => $data['hr_comments'] ?? null,
            'recommendation' => $recommendation,
            'status' => $status,
            'submitted_at' => $status === HrProbationReview::SUBMITTED ? now() : null,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $review->recordAudit($status === HrProbationReview::SUBMITTED ? 'Probation Review Submitted' : 'Probation Review Created', $actor, null, ['review_no' => $reviewNo, 'recommendation' => $recommendation]);
        $this->log('Probation review created', $tenantId, $review->id);

        return $this->present($this->find($review->id, $tenantId), true);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        if ($review->status === HrProbationReview::COMPLETED) {
            throw new BusinessException('A completed review is read-only and cannot be edited.');
        }

        $attrs = ['updated_by' => $actor?->id];
        if (array_key_exists('review_date', $data) && $data['review_date']) {
            $attrs['review_date'] = Carbon::parse($data['review_date'])->toDateString();
        }
        if (array_key_exists('reviewer_id', $data)) {
            $attrs['reviewer_id'] = $this->reviewer($data['reviewer_id'], $tenantId)?->id;
        }
        foreach (HrProbationReview::RATING_FIELDS as $rf) {
            if (array_key_exists($rf, $data)) {
                $attrs[$rf] = $this->rating($data[$rf], $rf);
            }
        }
        foreach (['strengths', 'improvements', 'manager_comments', 'hr_comments'] as $c) {
            if (array_key_exists($c, $data)) {
                $attrs[$c] = $data[$c];
            }
        }
        if (array_key_exists('recommendation', $data)) {
            $attrs['recommendation'] = $this->recommendation($data['recommendation']);
        }
        if (! empty($data['review_no']) && (int) $data['review_no'] !== $review->review_no) {
            if ($this->repo->reviewNoExists($review->employee_probation_id, (int) $data['review_no'], $tenantId, $review->id)) {
                throw new BusinessException("Review number {$data['review_no']} already exists for this probation.");
            }
            $attrs['review_no'] = (int) $data['review_no'];
        }

        $review->update($attrs);
        $review->recordAudit('Probation Review Updated', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function submit(int $id, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        if ($review->status !== HrProbationReview::DRAFT) {
            throw new BusinessException('Only a draft review can be submitted.');
        }
        $review->update(['status' => HrProbationReview::SUBMITTED, 'submitted_at' => now(), 'updated_by' => $actor?->id]);
        $review->recordAudit('Probation Review Submitted', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function complete(int $id, int $tenantId, ?User $actor = null): array
    {
        $review = $this->find($id, $tenantId);
        if ($review->status === HrProbationReview::COMPLETED) {
            throw new BusinessException('This review is already completed.');
        }
        if ($review->status !== HrProbationReview::SUBMITTED) {
            throw new BusinessException('Only a submitted review can be completed.');
        }
        $review->update(['status' => HrProbationReview::COMPLETED, 'completed_at' => now(), 'updated_by' => $actor?->id]);
        $review->recordAudit('Probation Review Completed', $actor, null, ['recommendation' => $review->recommendation]);
        $this->log('Probation review completed', $tenantId, $review->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Validation helpers ───────────────────────────────── */

    private function ratings(array $d): array
    {
        $out = [];
        foreach (HrProbationReview::RATING_FIELDS as $rf) {
            $out[$rf] = $this->rating($d[$rf] ?? null, $rf);
        }
        // Overall defaults to the rounded average of the four sub-ratings when not given.
        if (empty($d['overall_rating'])) {
            $subs = array_filter([$out['technical_rating'], $out['behaviour_rating'], $out['attendance_rating'], $out['communication_rating']]);
            $out['overall_rating'] = $subs ? (int) round(array_sum($subs) / count($subs)) : 0;
        }

        return $out;
    }

    private function rating($v, string $field): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        $n = (int) $v;
        if ($n < 1 || $n > 5) {
            throw new BusinessException(ucwords(str_replace('_', ' ', $field)).' must be between 1 and 5.');
        }

        return $n;
    }

    private function recommendation(?string $r): string
    {
        if (! in_array($r, HrProbationReview::RECOMMENDATIONS, true)) {
            throw new BusinessException('A valid recommendation is required (Continue, Extend, Confirm or Fail).');
        }

        return $r;
    }

    private function probation(int $id, int $tenantId): HrEmployeeProbation
    {
        $probation = HrEmployeeProbation::where('tenant_id', $tenantId)->find($id);
        if (! $probation) {
            throw new BusinessException('Employee probation is required and must be valid.');
        }

        return $probation;
    }

    private function reviewer($id, int $tenantId): ?HrEmployee
    {
        if (empty($id)) {
            throw new BusinessException('A reviewer is required.');
        }
        $reviewer = HrEmployee::where('tenant_id', $tenantId)->find($id);
        if (! $reviewer) {
            throw new BusinessException('Selected reviewer is invalid.');
        }

        return $reviewer;
    }

    private function present(HrProbationReview $r, bool $full = false): array
    {
        $out = [
            'id' => $r->id,
            'employee_probation_id' => $r->employee_probation_id,
            'employee_id' => $r->employee_id, 'employee_name' => $r->employee?->name, 'employee_code' => $r->employee?->employee_code,
            'department' => $r->employee?->department, 'designation' => $r->employee?->designation,
            'policy' => $r->probation?->policy?->name, 'probation_status' => $r->probation?->current_status,
            'review_no' => $r->review_no, 'review_date' => optional($r->review_date)->toDateString(),
            'reviewer_id' => $r->reviewer_id, 'reviewer_name' => $r->reviewer?->name,
            'overall_rating' => $r->overall_rating, 'technical_rating' => $r->technical_rating,
            'behaviour_rating' => $r->behaviour_rating, 'attendance_rating' => $r->attendance_rating,
            'communication_rating' => $r->communication_rating,
            'recommendation' => $r->recommendation, 'status' => $r->status,
        ];
        if ($full) {
            $out += [
                'strengths' => $r->strengths, 'improvements' => $r->improvements,
                'manager_comments' => $r->manager_comments, 'hr_comments' => $r->hr_comments,
                'submitted_at' => optional($r->submitted_at)->toIso8601String(),
                'completed_at' => optional($r->completed_at)->toIso8601String(),
                'timeline' => $r->relationLoaded('auditLogs')
                    ? $r->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                        'action' => $l->action, 'actor_name' => $l->actor_name,
                        'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                    ])->all()
                    : [],
            ];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrProbationReview
    {
        $review = $this->repo->find($id, $tenantId);
        if (! $review) {
            throw new BusinessException('Probation review not found', 404);
        }

        return $review;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
