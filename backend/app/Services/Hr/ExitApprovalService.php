<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExitRequest;
use App\Models\User;
use App\Repositories\Hr\ExitRepository;
use Illuminate\Support\Facades\Log;

/**
 * Exit Approval Workflow (Exit Phase 3). Drives an already-Submitted exit request
 * through Under Review → Approved / Rejected. Transitions are strictly guarded:
 * only Submitted can start review, only Under Review can be approved/rejected, and
 * Approved / Rejected / Withdrawn are immutable. Presentation is reused from
 * ExitRequestService so the approval and request views stay identical.
 * Tenant-scoped, audited.
 */
class ExitApprovalService
{
    public function __construct(
        private ExitRepository $repo,
        private ExitRequestService $requests,
    ) {
    }

    /** Approval queue: everything in the review pipeline, plus KPI counters. */
    public function queue(int $tenantId, array $f): array
    {
        // Default view is the actionable pipeline; an explicit status filter narrows it.
        if (empty($f['status']) || $f['status'] === 'All') {
            $f['statuses'] = [HrExitRequest::SUBMITTED, HrExitRequest::UNDER_REVIEW, HrExitRequest::APPROVED, HrExitRequest::REJECTED];
        }

        return [
            'stats' => $this->repo->approvalStats($tenantId),
            'rows'  => $this->repo->requests($tenantId, $f)->map(fn ($r) => $this->requests->present($r))->all(),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        $request->recordAudit('Exit Approval Viewed', $actor);

        return $this->requests->present($request, true);
    }

    public function history(int $tenantId, array $f): array
    {
        return $this->repo->approvalHistory($tenantId, $f)->map(fn ($r) => $this->requests->present($r))->all();
    }

    public function startReview(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        if ($request->status !== HrExitRequest::SUBMITTED) {
            throw new BusinessException('Only a submitted exit request can be moved to review.');
        }
        $request->update([
            'status'            => HrExitRequest::UNDER_REVIEW,
            'review_started_at' => now(),
            'reviewed_by'       => $actor?->id,
            'review_remarks'    => $data['review_remarks'] ?? $request->review_remarks,
            'updated_by'        => $actor?->id,
        ]);
        $request->recordAudit('Exit Review Started', $actor, $data['review_remarks'] ?? null);
        $this->log('Exit review started', $tenantId, $request->id);

        return $this->requests->present($this->find($id, $tenantId), true);
    }

    public function updateReviewRemarks(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        if ($request->status !== HrExitRequest::UNDER_REVIEW) {
            throw new BusinessException('Review remarks can only be updated while the request is under review.');
        }
        $request->update(['review_remarks' => $data['review_remarks'] ?? null, 'updated_by' => $actor?->id]);
        $request->recordAudit('Exit Review Updated', $actor, $data['review_remarks'] ?? null);

        return $this->requests->present($this->find($id, $tenantId), true);
    }

    public function approve(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        $this->assertDecidable($request);
        $request->update([
            'status'           => HrExitRequest::APPROVED,
            'decided_at'       => now(),
            'decided_by'       => $actor?->id,
            'decision_remarks' => $data['remarks'] ?? null,
            'updated_by'       => $actor?->id,
        ]);
        $request->recordAudit('Exit Approved', $actor, $data['remarks'] ?? null, ['employee' => $request->employee?->name]);
        $this->log('Exit approved', $tenantId, $request->id);

        return $this->requests->present($this->find($id, $tenantId), true);
    }

    public function reject(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        $this->assertDecidable($request);
        $request->update([
            'status'           => HrExitRequest::REJECTED,
            'decided_at'       => now(),
            'decided_by'       => $actor?->id,
            'decision_remarks' => $data['remarks'] ?? null,
            'updated_by'       => $actor?->id,
        ]);
        $request->recordAudit('Exit Rejected', $actor, $data['remarks'] ?? null, ['employee' => $request->employee?->name]);
        $this->log('Exit rejected', $tenantId, $request->id);

        return $this->requests->present($this->find($id, $tenantId), true);
    }

    /* ── Guards + helpers ─────────────────────────────────── */

    private function assertDecidable(HrExitRequest $request): void
    {
        if ($request->status === HrExitRequest::APPROVED) {
            throw new BusinessException('This exit request has already been approved.');
        }
        if ($request->status === HrExitRequest::REJECTED) {
            throw new BusinessException('This exit request has already been rejected.');
        }
        if ($request->status === HrExitRequest::WITHDRAWN) {
            throw new BusinessException('A withdrawn exit request cannot enter approval.');
        }
        if ($request->status !== HrExitRequest::UNDER_REVIEW) {
            throw new BusinessException('The request must be under review before a decision can be made.');
        }
    }

    private function find(int $id, int $tenantId): HrExitRequest
    {
        $request = $this->repo->findRequest($id, $tenantId);
        if (! $request) {
            throw new BusinessException('Exit request not found', 404);
        }

        return $request;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
