<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationExtension;
use App\Models\User;
use App\Repositories\Hr\ProbationExtensionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Probation Extensions (Probation Phase 4). Extension requests on an Active/
 * Extended employee probation, within the policy's extension limit. Lifecycle:
 * Pending → Approved / Rejected (both terminal). Approval pushes the probation's
 * end date and marks it Extended (incrementing its extension count). Reuses
 * Employee / Probation — no duplicated data. Tenant-scoped, audited.
 */
class ProbationExtensionService
{
    /** Probation statuses that may receive an extension. */
    private const EXTENDABLE = [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED];

    public function __construct(private ProbationExtensionRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->list($tenantId, $f)->map(fn ($e) => $this->present($e))->all(),
            'stats' => $this->repo->stats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $extension = $this->find($id, $tenantId);
        $extension->recordAudit('Probation Extension Viewed', $actor);

        return $this->present($extension, true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($e) => $this->present($e, true))->all();
    }

    public function history(int $tenantId, array $f): array
    {
        return $this->repo->history($tenantId, $f)->map(fn ($e) => $this->present($e))->all();
    }

    /* ── Request ──────────────────────────────────────────── */

    public function request(array $data, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->probation((int) ($data['probation_id'] ?? 0), $tenantId);
        $this->assertExtendable($probation);

        if ($this->repo->pendingExists($probation->id, $tenantId)) {
            throw new BusinessException('There is already a pending extension for this probation.');
        }
        $this->assertWithinLimit($probation);

        $currentEnd = Carbon::parse($probation->probation_end_date);
        [$days, $extendedEnd] = $this->resolveDates($data, $currentEnd);

        $extension = HrProbationExtension::create([
            'tenant_id' => $tenantId,
            'probation_id' => $probation->id,
            'employee_id' => $probation->employee_id,
            'requested_by' => $data['requested_by'] ?? $actor?->id,
            'extension_number' => $this->repo->nextExtensionNumber($probation->id, $tenantId),
            'current_end_date' => $currentEnd->toDateString(),
            'extended_end_date' => $extendedEnd->toDateString(),
            'extension_days' => $days,
            'reason' => $data['reason'] ?? null,
            'manager_comments' => $data['manager_comments'] ?? null,
            'hr_comments' => $data['hr_comments'] ?? null,
            'status' => HrProbationExtension::PENDING,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $extension->recordAudit('Probation Extension Requested', $actor, $data['reason'] ?? null, ['days' => $days, 'employee' => $probation->employee?->name]);
        $this->log('Probation extension requested', $tenantId, $extension->id);

        return $this->present($this->find($extension->id, $tenantId), true);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $extension = $this->find($id, $tenantId);
        if (in_array($extension->status, HrProbationExtension::TERMINAL, true)) {
            throw new BusinessException("A {$extension->status} extension can no longer be edited.");
        }

        $attrs = ['updated_by' => $actor?->id];
        foreach (['reason', 'manager_comments', 'hr_comments'] as $c) {
            if (array_key_exists($c, $data)) {
                $attrs[$c] = $data[$c];
            }
        }
        if (array_key_exists('extension_days', $data) || array_key_exists('extended_end_date', $data)) {
            [$days, $extendedEnd] = $this->resolveDates($data, Carbon::parse($extension->current_end_date));
            $attrs['extension_days'] = $days;
            $attrs['extended_end_date'] = $extendedEnd->toDateString();
        }

        $extension->update($attrs);
        $extension->recordAudit('Probation Extension Updated', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function approve(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $extension = $this->find($id, $tenantId);
        $this->assertPending($extension);
        $probation = $extension->probation;
        $this->assertExtendable($probation);
        $this->assertWithinLimit($probation);

        // Apply the extension: push the probation end date and mark it Extended.
        $probation->update([
            'probation_end_date'   => $extension->extended_end_date,
            'confirmation_due_date'=> $extension->extended_end_date,
            'current_status'       => HrEmployeeProbation::EXTENDED,
            'extension_count'      => (int) $probation->extension_count + 1,
            'updated_by'           => $actor?->id,
        ]);
        $probation->recordAudit('Probation Extended', $actor, null, ['extension_no' => $extension->extension_number, 'new_end' => $extension->extended_end_date]);

        $extension->update([
            'status' => HrProbationExtension::APPROVED,
            'approved_by' => $actor?->id,
            'approved_at' => now(),
            'hr_comments' => $data['hr_comments'] ?? $extension->hr_comments,
            'updated_by' => $actor?->id,
        ]);
        $extension->recordAudit('Probation Extension Approved', $actor, $data['hr_comments'] ?? null, ['new_end' => $extension->extended_end_date]);
        $this->log('Probation extension approved', $tenantId, $extension->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function reject(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $extension = $this->find($id, $tenantId);
        $this->assertPending($extension);
        $extension->update([
            'status' => HrProbationExtension::REJECTED,
            'approved_by' => $actor?->id,
            'rejected_at' => now(),
            'hr_comments' => $data['hr_comments'] ?? $extension->hr_comments,
            'updated_by' => $actor?->id,
        ]);
        $extension->recordAudit('Probation Extension Rejected', $actor, $data['hr_comments'] ?? null);
        $this->log('Probation extension rejected', $tenantId, $extension->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Guards + helpers ─────────────────────────────────── */

    private function assertExtendable(HrEmployeeProbation $probation): void
    {
        if (! in_array($probation->current_status, self::EXTENDABLE, true)) {
            throw new BusinessException("A {$probation->current_status} probation cannot receive an extension.");
        }
    }

    private function assertWithinLimit(HrEmployeeProbation $probation): void
    {
        $probation->loadMissing('policy', 'probationType');
        $limit = (int) ($probation->policy?->extension_limit ?? 0);
        if ($limit <= 0) {
            $limit = (int) ($probation->probationType?->max_extensions ?? 0);
        }
        if ($limit > 0 && (int) $probation->extension_count >= $limit) {
            throw new BusinessException("The maximum number of extensions ({$limit}) for this probation has been reached.");
        }
    }

    private function assertPending(HrProbationExtension $extension): void
    {
        if ($extension->status === HrProbationExtension::APPROVED) {
            throw new BusinessException('This extension has already been approved.');
        }
        if ($extension->status === HrProbationExtension::REJECTED) {
            throw new BusinessException('This extension has already been rejected.');
        }
    }

    /** Resolve extension days + extended end date from the request (either can drive). */
    private function resolveDates(array $data, Carbon $currentEnd): array
    {
        if (! empty($data['extended_end_date'])) {
            $extendedEnd = Carbon::parse($data['extended_end_date']);
            if ($extendedEnd->lte($currentEnd)) {
                throw new BusinessException('The extended end date must be after the current end date.');
            }

            return [$currentEnd->diffInDays($extendedEnd), $extendedEnd];
        }

        $days = (int) ($data['extension_days'] ?? 0);
        if ($days <= 0) {
            throw new BusinessException('Extension days must be greater than zero.');
        }

        return [$days, $currentEnd->copy()->addDays($days)];
    }

    private function present(HrProbationExtension $e, bool $full = false): array
    {
        $prob = $e->probation;
        $out = [
            'id' => $e->id,
            'probation_id' => $e->probation_id,
            'employee_id' => $e->employee_id, 'employee_name' => $e->employee?->name, 'employee_code' => $e->employee?->employee_code,
            'department' => $e->employee?->department, 'designation' => $e->employee?->designation,
            'policy' => $prob?->policy?->name, 'probation_status' => $prob?->current_status,
            'extension_number' => $e->extension_number,
            'current_end_date' => optional($e->current_end_date)->toDateString(),
            'extended_end_date' => optional($e->extended_end_date)->toDateString(),
            'extension_days' => $e->extension_days,
            'status' => $e->status,
            'requested_by_name' => $e->requestedBy?->name,
            'requested_date' => optional($e->created_at)->toIso8601String(),
            'approved_at' => optional($e->approved_at)->toIso8601String(),
            'rejected_at' => optional($e->rejected_at)->toIso8601String(),
        ];
        if ($full) {
            $out += [
                'reason' => $e->reason, 'manager_comments' => $e->manager_comments, 'hr_comments' => $e->hr_comments,
                'timeline' => $e->relationLoaded('auditLogs')
                    ? $e->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                        'action' => $l->action, 'actor_name' => $l->actor_name,
                        'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                    ])->all()
                    : [],
            ];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrProbationExtension
    {
        $extension = $this->repo->find($id, $tenantId);
        if (! $extension) {
            throw new BusinessException('Probation extension not found', 404);
        }

        return $extension;
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
