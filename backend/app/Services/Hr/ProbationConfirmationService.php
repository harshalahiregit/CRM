<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationConfirmation;
use App\Models\User;
use App\Repositories\Hr\ProbationConfirmationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Probation Confirmation Workflow (Probation Phase 5). One confirmation per
 * Active/Extended probation. Lifecycle: Pending → Approved → Confirmed, or
 * Rejected. Approval requires mandatory reviews completed (when the type demands
 * them); confirming closes the probation (marks it Confirmed) and stamps the
 * confirmation/effective dates. Reuses Review + Extension snapshots — no
 * duplicated data. Tenant-scoped, audited.
 */
class ProbationConfirmationService
{
    private const ELIGIBLE = [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED];

    public function __construct(private ProbationConfirmationRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->list($tenantId, $f)->map(fn ($c) => $this->present($c))->all(),
            'stats' => $this->repo->stats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $conf = $this->find($id, $tenantId);
        $conf->recordAudit('Probation Confirmation Viewed', $actor);

        return $this->present($conf, true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($c) => $this->present($c, true))->all();
    }

    public function history(int $tenantId, array $f): array
    {
        return $this->repo->history($tenantId, $f)->map(fn ($c) => $this->present($c))->all();
    }

    /* ── Create / update ──────────────────────────────────── */

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->probation((int) ($data['probation_id'] ?? 0), $tenantId);
        if (! in_array($probation->current_status, self::ELIGIBLE, true)) {
            throw new BusinessException('Only an active or extended probation can enter confirmation.');
        }
        if ($this->repo->findByProbation($probation->id, $tenantId)) {
            throw new BusinessException('A confirmation already exists for this probation.');
        }

        // Snapshot the latest review recommendation + latest extension (reused, not duplicated).
        $review = $this->repo->latestReview($probation->id, $tenantId);
        $extension = $this->repo->latestExtension($probation->id, $tenantId);

        $conf = HrProbationConfirmation::create([
            'tenant_id' => $tenantId,
            'probation_id' => $probation->id,
            'employee_id' => $probation->employee_id,
            'latest_review_id' => $review?->id,
            'latest_extension_id' => $extension?->id,
            'recommendation' => $review?->recommendation,
            'decision' => $this->decision($data['decision'] ?? null, false),
            'effective_date' => ! empty($data['effective_date'])
                ? Carbon::parse($data['effective_date'])->toDateString()
                : optional($probation->probation_end_date)->toDateString(),
            'manager_comments' => $data['manager_comments'] ?? null,
            'hr_comments' => $data['hr_comments'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => HrProbationConfirmation::PENDING,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $conf->recordAudit('Probation Confirmation Created', $actor, null, ['employee' => $probation->employee?->name, 'recommendation' => $review?->recommendation]);
        $this->log('Probation confirmation created', $tenantId, $conf->id);

        return $this->present($this->find($conf->id, $tenantId), true);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $conf = $this->find($id, $tenantId);
        $this->assertEditable($conf);

        $attrs = ['updated_by' => $actor?->id];
        foreach (['manager_comments', 'hr_comments', 'remarks'] as $c) {
            if (array_key_exists($c, $data)) {
                $attrs[$c] = $data[$c];
            }
        }
        if (array_key_exists('decision', $data)) {
            $attrs['decision'] = $this->decision($data['decision'], false);
        }
        if (! empty($data['effective_date'])) {
            $attrs['effective_date'] = Carbon::parse($data['effective_date'])->toDateString();
        }

        $conf->update($attrs);
        $conf->recordAudit('Probation Confirmation Updated', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Workflow ─────────────────────────────────────────── */

    public function approve(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $conf = $this->find($id, $tenantId);
        if ($conf->status !== HrProbationConfirmation::PENDING) {
            throw new BusinessException('Only a pending confirmation can be approved.');
        }
        $probation = $conf->probation;
        if (! in_array($probation?->current_status, self::ELIGIBLE, true)) {
            throw new BusinessException('The probation is no longer active or extended.');
        }
        // Mandatory reviews must be completed before approval (when the type requires reviews).
        $probation->loadMissing('probationType');
        if (($probation->probationType?->review_required ?? false) && ! $this->repo->hasCompletedReview($probation->id, $tenantId)) {
            throw new BusinessException('A completed probation review is required before this confirmation can be approved.');
        }

        $conf->update([
            'status' => HrProbationConfirmation::APPROVED,
            'approved_by' => $actor?->id,
            'approved_at' => now(),
            'hr_comments' => $data['hr_comments'] ?? $conf->hr_comments,
            'updated_by' => $actor?->id,
        ]);
        $conf->recordAudit('Probation Confirmation Approved', $actor, $data['hr_comments'] ?? null);
        $this->log('Probation confirmation approved', $tenantId, $conf->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function reject(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $conf = $this->find($id, $tenantId);
        if (in_array($conf->status, [HrProbationConfirmation::CONFIRMED, HrProbationConfirmation::REJECTED], true)) {
            throw new BusinessException("A {$conf->status} confirmation cannot be rejected.");
        }
        $conf->update([
            'status' => HrProbationConfirmation::REJECTED,
            'approved_by' => $actor?->id,
            'hr_comments' => $data['hr_comments'] ?? $conf->hr_comments,
            'updated_by' => $actor?->id,
        ]);
        $conf->recordAudit('Probation Confirmation Rejected', $actor, $data['hr_comments'] ?? null);
        $this->log('Probation confirmation rejected', $tenantId, $conf->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function confirm(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $conf = $this->find($id, $tenantId);
        if ($conf->status === HrProbationConfirmation::CONFIRMED) {
            throw new BusinessException('This employee is already confirmed.');
        }
        if ($conf->status === HrProbationConfirmation::REJECTED) {
            throw new BusinessException('A rejected confirmation cannot be confirmed.');
        }
        if ($conf->status !== HrProbationConfirmation::APPROVED) {
            throw new BusinessException('The confirmation must be approved before the employee can be confirmed.');
        }

        $today = now()->toDateString();
        $effective = ! empty($data['effective_date'])
            ? Carbon::parse($data['effective_date'])->toDateString()
            : (optional($conf->effective_date)->toDateString() ?? $today);

        // Close the probation: mark it Confirmed (a confirmed employee cannot return to probation).
        $probation = $conf->probation;
        if ($probation) {
            $probation->update(['current_status' => HrEmployeeProbation::CONFIRMED, 'updated_by' => $actor?->id]);
            $probation->recordAudit('Probation Confirmed', $actor, null, ['effective_date' => $effective]);
        }

        $conf->update([
            'status' => HrProbationConfirmation::CONFIRMED,
            'decision' => $conf->decision ?: 'Confirm',
            'confirmation_date' => $today,
            'effective_date' => $effective,
            'confirmed_by' => $actor?->id,
            'confirmed_at' => now(),
            'remarks' => $data['remarks'] ?? $conf->remarks,
            'updated_by' => $actor?->id,
        ]);
        $conf->recordAudit('Employee Confirmed', $actor, $data['remarks'] ?? null, ['effective_date' => $effective]);
        $this->log('Employee confirmed', $tenantId, $conf->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Guards + helpers ─────────────────────────────────── */

    private function assertEditable(HrProbationConfirmation $conf): void
    {
        if (in_array($conf->status, [HrProbationConfirmation::CONFIRMED, HrProbationConfirmation::REJECTED], true)) {
            throw new BusinessException("A {$conf->status} confirmation is read-only and cannot be edited.");
        }
    }

    private function decision(?string $d, bool $required): ?string
    {
        if (empty($d)) {
            if ($required) {
                throw new BusinessException('A decision is required.');
            }

            return null;
        }
        if (! in_array($d, HrProbationConfirmation::DECISIONS, true)) {
            throw new BusinessException('Invalid decision (Confirm, Extend, Terminate or Continue).');
        }

        return $d;
    }

    private function present(HrProbationConfirmation $c, bool $full = false): array
    {
        $prob = $c->probation;
        $review = $c->latestReview;
        $ext = $c->latestExtension;
        $out = [
            'id' => $c->id,
            'probation_id' => $c->probation_id,
            'employee_id' => $c->employee_id, 'employee_name' => $c->employee?->name, 'employee_code' => $c->employee?->employee_code,
            'department' => $c->employee?->department, 'designation' => $c->employee?->designation,
            'grade' => $c->employee?->grade?->name,
            'policy' => $prob?->policy?->name, 'probation_type' => $prob?->probationType?->name,
            'probation_status' => $prob?->current_status,
            'probation_start_date' => optional($prob?->probation_start_date)->toDateString(),
            'current_end_date' => optional($prob?->probation_end_date)->toDateString(),
            'recommendation' => $c->recommendation,
            'decision' => $c->decision,
            'confirmation_date' => optional($c->confirmation_date)->toDateString(),
            'effective_date' => optional($c->effective_date)->toDateString(),
            'status' => $c->status,
            'review_summary' => $review ? [
                'review_no' => $review->review_no, 'rating' => $review->overall_rating,
                'recommendation' => $review->recommendation, 'status' => $review->status,
            ] : null,
            'extension_summary' => $ext ? [
                'extension_number' => $ext->extension_number, 'extension_days' => $ext->extension_days,
                'extended_end_date' => optional($ext->extended_end_date)->toDateString(), 'status' => $ext->status,
            ] : null,
        ];
        if ($full) {
            $out += [
                'manager_comments' => $c->manager_comments, 'hr_comments' => $c->hr_comments, 'remarks' => $c->remarks,
                'timeline' => $c->relationLoaded('auditLogs')
                    ? $c->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                        'action' => $l->action, 'actor_name' => $l->actor_name,
                        'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                    ])->all()
                    : [],
            ];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrProbationConfirmation
    {
        $conf = $this->repo->find($id, $tenantId);
        if (! $conf) {
            throw new BusinessException('Probation confirmation not found', 404);
        }

        return $conf;
    }

    private function probation(int $id, int $tenantId): HrEmployeeProbation
    {
        $probation = HrEmployeeProbation::where('tenant_id', $tenantId)->with(['employee', 'policy', 'probationType'])->find($id);
        if (! $probation) {
            throw new BusinessException('Employee probation is required and must be valid.');
        }

        return $probation;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
