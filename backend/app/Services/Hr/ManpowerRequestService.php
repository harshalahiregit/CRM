<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Repositories\Hr\ManpowerRequestRepository;
use App\Support\Hr\JdTemplate;
use App\Support\Hr\JobPostingStatus;
use App\Support\Hr\ManpowerRequestStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManpowerRequestService
{
    public function __construct(
        private ManpowerRequestRepository $manpowerRequestRepository,
        private \App\Services\Notifications\NotificationService $notifications,
    ) {
    }

    public function list(User $user, array $filters): Collection
    {
        return $this->manpowerRequestRepository->filtered($user, $filters);
    }

    public function pendingApprovals(User $user): array
    {
        $l1Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', Status::L1_PENDING)
            ->with(['requester'])
            ->latest()->get();

        $l2Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', Status::L2_PENDING)
            ->with(['requester', 'l1Approver'])
            ->latest()->get();

        return [
            'l1_pending' => $user->canApproveL1() ? $l1Pending : [],
            'l2_pending' => $user->canApproveL2() ? $l2Pending : [],
            'l1_count'   => $l1Pending->count(),
            'l2_count'   => $l2Pending->count(),
        ];
    }

    public function create(array $data, User $user): HrManpowerRequest
    {
        return DB::transaction(function () use ($data, $user) {
            $mr = HrManpowerRequest::create([
                ...$data,
                'status'       => Status::DRAFT,
                'l1_status'    => 'pending',
                'l2_status'    => 'pending',
                'requested_by' => $user->id,
                'tenant_id'    => $user->tenant_id,
            ]);

            $this->logHistory($mr, 'General', 'Created', $user, 'Manpower request created', null, ['status' => Status::DRAFT]);

            Log::channel('hr')->info('Manpower request created', ['request_id' => $mr->id, 'tenant_id' => $user->tenant_id]);

            return $mr->load(['requester', 'assignedManager']);
        });
    }

    public function submit(HrManpowerRequest $manpowerRequest, User $user): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);

        if ($manpowerRequest->status !== Status::DRAFT) {
            throw new BusinessException('Only Draft requests can be submitted', 422);
        }

        if ($manpowerRequest->requested_by !== $user->id && ! $user->isAdmin()) {
            throw new BusinessException('Only the requester can submit this request', 403);
        }

        return DB::transaction(function () use ($manpowerRequest, $user) {
            // Record the submission (Draft → L1 Pending) exactly as before …
            $manpowerRequest->update([
                'status'       => Status::L1_PENDING,
                'l1_status'    => 'pending',
                'submitted_at' => now(),
            ]);

            $this->logHistory($manpowerRequest, 'L1', 'Submitted', $user,
                'Submitted for approval',
                ['status' => Status::DRAFT], ['status' => Status::L1_PENDING]);

            // … then auto-approve L1 (SPK-1): the creator no longer approves L1
            // manually. Reuses the same approval transition, so the L1-Approved
            // history entry is written identically to a manual approval. The
            // request lands on L2 (Management) Pending.
            $this->applyL1Approval($manpowerRequest, $user, null, true);

            Log::channel('hr')->info('Manpower request submitted + L1 auto-approved', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

            return $manpowerRequest->fresh()->load(['requester', 'l1Approver', 'auditLogs.actor']);
        });
    }

    public function approveL1(HrManpowerRequest $manpowerRequest, User $user, ?string $remarks): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canApproveL1(), 'You are not authorised to give L1 (Department Head) approval');

        if ($manpowerRequest->status !== Status::L1_PENDING) {
            throw new BusinessException('Request is not pending L1 approval', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks) {
            $this->applyL1Approval($manpowerRequest, $user, $remarks);

            return $manpowerRequest->fresh()->load(['requester', 'l1Approver', 'auditLogs.actor']);
        });
    }

    public function rejectL1(HrManpowerRequest $manpowerRequest, User $user, string $remarks): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canApproveL1(), 'You are not authorised to reject at L1');

        if ($manpowerRequest->status !== Status::L1_PENDING) {
            throw new BusinessException('Request is not pending L1 approval', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks) {
            $manpowerRequest->update([
                'status'           => Status::REJECTED,
                'l1_status'        => 'rejected',
                'l1_approver_id'   => $user->id,
                'l1_approved_at'   => now(),
                'l1_remarks'       => $remarks,
                'rejection_reason' => '[L1] '.$remarks,
            ]);

            $this->logHistory($manpowerRequest, 'L1', 'Rejected', $user, $remarks,
                ['status' => Status::L1_PENDING], ['status' => Status::REJECTED, 'l1_status' => 'rejected']);

            Log::channel('hr')->info('Manpower request rejected at L1', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'auditLogs.actor']);
        });
    }

    public function approveL2(HrManpowerRequest $manpowerRequest, User $user, ?string $remarks): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canApproveL2(), 'You are not authorised to give L2 (Management) approval');

        if ($manpowerRequest->status !== Status::L2_PENDING) {
            throw new BusinessException('Request is not pending L2 approval', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks) {
            $manpowerRequest->update([
                'status'         => Status::READY_FOR_HR,
                'l2_status'      => 'approved',
                'l2_approver_id' => $user->id,
                'l2_approved_at' => now(),
                'l2_remarks'     => $remarks,
                'approved_by'    => $user->id,
                'approved_at'    => now(),
            ]);

            $this->logHistory($manpowerRequest, 'L2', 'Approved', $user,
                $remarks ?? 'L2 Approved by Management — request is now in the HR queue',
                ['status' => Status::L2_PENDING], ['status' => Status::READY_FOR_HR, 'l2_status' => 'approved']);

            Log::channel('hr')->info('Manpower request L2 approved (ready for HR)', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'l1Approver', 'l2Approver', 'auditLogs.actor']);
        });
    }

    public function rejectL2(HrManpowerRequest $manpowerRequest, User $user, string $remarks): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canApproveL2(), 'You are not authorised to reject at L2');

        if ($manpowerRequest->status !== Status::L2_PENDING) {
            throw new BusinessException('Request is not pending L2 approval', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks) {
            $manpowerRequest->update([
                'status'           => Status::REJECTED,
                'l2_status'        => 'rejected',
                'l2_approver_id'   => $user->id,
                'l2_approved_at'   => now(),
                'l2_remarks'       => $remarks,
                'rejection_reason' => '[L2] '.$remarks,
            ]);

            $this->logHistory($manpowerRequest, 'L2', 'Rejected', $user, $remarks,
                ['status' => Status::L2_PENDING], ['status' => Status::REJECTED, 'l2_status' => 'rejected']);

            Log::channel('hr')->info('Manpower request rejected at L2', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'auditLogs.actor']);
        });
    }

    /**
     * Send a pending request back to the requester for revision (returns it to
     * Draft) instead of rejecting it outright. The approver must supply a reason.
     */
    public function sendBack(HrManpowerRequest $manpowerRequest, User $user, string $remarks): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);

        $level = match ($manpowerRequest->status) {
            Status::L1_PENDING => 'L1',
            Status::L2_PENDING => 'L2',
            default            => null,
        };
        if ($level === null) {
            throw new BusinessException('Only requests pending approval can be sent back', 422);
        }
        $this->authorize(
            $level === 'L1' ? $user->canApproveL1() : $user->canApproveL2(),
            'You are not authorised to send back this request'
        );

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks, $level) {
            $from = $manpowerRequest->status;
            $manpowerRequest->update([
                'status'           => Status::DRAFT,
                'l1_status'        => 'pending',
                'l2_status'        => 'pending',
                'l1_approver_id'   => null, 'l1_approved_at' => null, 'l1_remarks' => null,
                'l2_approver_id'   => null, 'l2_approved_at' => null, 'l2_remarks' => null,
                'rejection_reason' => null,
                'submitted_at'     => null,
            ]);

            $this->logHistory($manpowerRequest, $level, 'Sent Back', $user, $remarks,
                ['status' => $from], ['status' => Status::DRAFT]);

            Log::channel('hr')->info('Manpower request sent back for revision', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'from' => $from]);

            return $manpowerRequest->fresh()->load(['requester', 'auditLogs.actor']);
        });
    }

    /**
     * HR queue — convert an approved request into a Job Description (draft
     * HrJobPosting). Pre-fills the JD from the request so HR only edits the
     * gaps, avoiding duplicate data entry.
     */
    public function convertToJd(HrManpowerRequest $manpowerRequest, User $user, array $overrides = []): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canManageHrQueue(), 'Only HR can convert a request to a Job Description');

        if (! $manpowerRequest->canConvertToJd()) {
            throw new BusinessException('Only fully-approved (Ready for HR) requests can be converted to a JD', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $overrides) {
            $requirements = $manpowerRequest->required_skills
                ? 'Skills: '.implode(', ', (array) $manpowerRequest->required_skills)
                : null;
            if ($manpowerRequest->experience_required) {
                $requirements = trim(($requirements ? $requirements."\n" : '').'Experience: '.$manpowerRequest->experience_required);
            }

            // JD provenance: explicit jd_source wins; else 'manual' if HR supplied a
            // body, otherwise the deterministic template. AI metadata comes from the
            // temporarily-cached AI draft.
            $jdSource = $overrides['jd_source']
                ?? ((array_key_exists('description', $overrides) && $overrides['description'] !== null) ? 'manual' : 'template');
            $aiMeta = null;
            if ($jdSource === 'ai') {
                $cached = app(\App\Services\Hr\JobDescriptionAIService::class)->cachedDrafts($manpowerRequest)['current'] ?? null;
                $aiMeta = $cached ? [
                    'provider' => $cached['provider'] ?? null,
                    'model'    => $cached['model'] ?? null,
                    'generated_at' => $cached['generated_at'] ?? null,
                ] : null;
            }

            $jd = HrJobPosting::create([
                'tenant_id'           => $manpowerRequest->tenant_id,
                'manpower_request_id' => $manpowerRequest->id,
                'project_id'          => $manpowerRequest->project_id,   // carry the Project forward (no duplicate mapping)
                'jd_source'           => $jdSource,
                'ai_jd_meta'          => $aiMeta,
                'title'               => $overrides['title']        ?? $manpowerRequest->position_title,
                'department'          => $manpowerRequest->department,
                'location'            => $overrides['location'] ?? $manpowerRequest->location ?? 'To be specified',
                'job_type'            => $manpowerRequest->job_type,
                'posting_type'        => $overrides['posting_type'] ?? 'Both',
                // Standard JD format (SPK-1), auto-filled from this requisition.
                'description'         => $overrides['description']  ?? JdTemplate::build($manpowerRequest, $user->tenant?->name),
                'requirements'        => $overrides['requirements'] ?? $requirements,
                'salary_from'         => $manpowerRequest->salary_min,
                'salary_to'           => $manpowerRequest->salary_max,
                'number_of_openings'  => $manpowerRequest->number_of_posts,
                'closing_date'        => $overrides['closing_date'] ?? $manpowerRequest->target_joining_date,
                'status'              => JobPostingStatus::READY_FOR_HR, // converted, awaiting publish
            ]);

            $jd->recordAudit('Created from Manpower Request', $user, 'Auto-created from MR-'.$manpowerRequest->id, ['to' => JobPostingStatus::READY_FOR_HR]);

            $manpowerRequest->update([
                'status'         => Status::CONVERTED_TO_JD,
                'job_posting_id' => $jd->id,
                'converted_at'   => now(),
            ]);

            $this->logHistory($manpowerRequest, 'HR', 'Converted to JD', $user,
                'Converted to Job Description (draft) #'.$jd->id,
                ['status' => Status::READY_FOR_HR], ['status' => Status::CONVERTED_TO_JD, 'job_posting_id' => $jd->id]);

            // Provenance audit — distinguishes an accepted AI JD from a manual edit.
            if ($jdSource === 'ai') {
                $manpowerRequest->recordAudit('AI JD Accepted', $user, 'AI-generated Job Description accepted into JD #'.$jd->id, array_filter([
                    'provider' => $aiMeta['provider'] ?? null, 'model' => $aiMeta['model'] ?? null,
                ]));
                app(\App\Services\Hr\JobDescriptionAIService::class)->clearCache($manpowerRequest);
            } elseif ($jdSource === 'manual' && ! empty($overrides['description'])) {
                $manpowerRequest->recordAudit('Manual JD Edited', $user, 'Job Description entered/edited manually during conversion');
            }

            Log::channel('hr')->info('Manpower request converted to JD', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'job_posting_id' => $jd->id]);

            return $manpowerRequest->fresh()->load(['requester', 'jobPosting', 'auditLogs.actor']);
        });
    }

    /** HR queue — publish the JD, opening the position to candidates. */
    public function publishJob(HrManpowerRequest $manpowerRequest, User $user): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canManageHrQueue(), 'Only HR can publish a job');

        if (! $manpowerRequest->canPublish()) {
            throw new BusinessException('Convert the request to a JD before publishing', 422);
        }
        if (! $manpowerRequest->job_posting_id) {
            throw new BusinessException('No Job Description is linked to this request', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user) {
            $job = HrJobPosting::where('id', $manpowerRequest->job_posting_id)
                ->where('tenant_id', $manpowerRequest->tenant_id)->first();
            if ($job) {
                // Publishing activates recruitment immediately: the JD moves straight
                // to the live "Hiring" state (in JobPostingStatus::LIVE), so the career
                // portal, company portal and recruiter workspace pick it up at once.
                $job->update(['status' => JobPostingStatus::HIRING, 'published_at' => $job->published_at ?? now()]);
                $job->recordAudit('Published', $user, 'Published via manpower request', ['to' => JobPostingStatus::HIRING]);
            }

            // Single transition — publish auto-starts hiring (no manual "Start Hiring").
            $updates = ['status' => Status::HIRING_IN_PROGRESS, 'posted_at' => now()];
            // Populate hiring_started_at only if that column exists (future-proof; it
            // doesn't today — recruitment-active state is carried by the JD status).
            if (\Illuminate\Support\Facades\Schema::hasColumn('hr_manpower_requests', 'hiring_started_at')) {
                $updates['hiring_started_at'] = now();
            }
            $manpowerRequest->update($updates);

            // Keep the "Job Published" milestone, then log the automatic hiring start
            // as a System action (no actor) — both land on the audit timeline.
            $this->logHistory($manpowerRequest, 'HR', 'Job Published', $user, 'Job published and open to candidates',
                ['status' => Status::CONVERTED_TO_JD], ['status' => Status::JOB_POSTED]);
            $manpowerRequest->recordAudit('Hiring Started (System)', null, 'Recruitment started automatically on publish', [
                'from' => Status::JOB_POSTED, 'to' => Status::HIRING_IN_PROGRESS, 'system' => true,
            ]);

            $this->notifyPublished($manpowerRequest, $job);

            Log::channel('hr')->info('Manpower request published — hiring started automatically', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

            return $manpowerRequest->fresh()->load(['requester', 'jobPosting', 'auditLogs.actor']);
        });
    }

    /**
     * Job Published + Hiring Started notification (combined) — reuses the shared
     * NotificationService. Never throws (delivery failure won't break publish).
     */
    private function notifyPublished(HrManpowerRequest $mr, ?HrJobPosting $job): void
    {
        $requester = $mr->requester;
        $title = $job?->title ?: $mr->position_title;
        $name = $requester?->name ?: 'there';
        $body = "Hi {$name},\n\nThe Job Description for \"{$title}\" (MR-{$mr->id}) has been published and recruitment has automatically started — the position is now Hiring in Progress.\n\nThe opening is live on the career portal, visible in the company portal, and recruiters are now tracking applicants.\n\nRegards,\nHR Team";

        $this->notifications->email($requester?->email, "Job Published & Hiring Started — {$title}", $body, [
            'event' => 'job_published_hiring_started', 'request_id' => $mr->id,
        ]);
    }

    /** Move a posted job into active hiring. */

    /** Close the position (and its linked posting). */
    public function close(HrManpowerRequest $manpowerRequest, User $user, ?string $remarks = null): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);
        $this->authorize($user->canManageHrQueue(), 'Only HR can close a position');

        if (! in_array($manpowerRequest->status, Status::HR_QUEUE, true)) {
            throw new BusinessException('Only requests in the HR queue can be closed', 422);
        }

        return DB::transaction(function () use ($manpowerRequest, $user, $remarks) {
            if ($manpowerRequest->job_posting_id) {
                $job = HrJobPosting::where('id', $manpowerRequest->job_posting_id)
                    ->where('tenant_id', $manpowerRequest->tenant_id)->first();
                if ($job) {
                    $job->update(['status' => JobPostingStatus::CLOSED]);
                    $job->recordAudit('Closed', $user, $remarks ?? 'Closed via manpower request', ['to' => JobPostingStatus::CLOSED]);
                }
            }

            $manpowerRequest->update(['status' => Status::CLOSED, 'closed_at' => now()]);
            $this->logHistory($manpowerRequest, 'HR', 'Closed', $user, $remarks ?? 'Position closed',
                null, ['status' => Status::CLOSED]);

            Log::channel('hr')->info('Manpower request closed', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

            return $manpowerRequest->fresh()->load(['requester', 'jobPosting']);
        });
    }

    public function update(HrManpowerRequest $manpowerRequest, array $data, User $user): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);

        if (! in_array($manpowerRequest->status, [Status::DRAFT, Status::REJECTED], true)) {
            throw new BusinessException('Only Draft or Rejected requests can be edited', 422);
        }

        $manpowerRequest->update($data);

        Log::channel('hr')->info('Manpower request updated', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

        return $manpowerRequest->fresh()->load(['requester']);
    }

    public function destroy(HrManpowerRequest $manpowerRequest, User $user): void
    {
        $this->assertTenant($manpowerRequest, $user);

        if ($manpowerRequest->requested_by !== $user->id && ! $user->isAdmin()) {
            throw new UnauthorizedTenantException('Unauthorized');
        }

        $manpowerRequest->delete();

        Log::channel('hr')->info('Manpower request deleted', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => HrManpowerRequest::where('tenant_id', $tenantId);

        return [
            'total'            => $base()->count(),
            'draft'            => $base()->where('status', Status::DRAFT)->count(),
            'l1_pending'       => $base()->where('status', Status::L1_PENDING)->count(),
            'l2_pending'       => $base()->where('status', Status::L2_PENDING)->count(),
            'ready_for_hr'     => $base()->where('status', Status::READY_FOR_HR)->count(),
            'converted_to_jd'  => $base()->where('status', Status::CONVERTED_TO_JD)->count(),
            'job_posted'       => $base()->where('status', Status::JOB_POSTED)->count(),
            'hiring'           => $base()->where('status', Status::HIRING_IN_PROGRESS)->count(),
            'closed'           => $base()->where('status', Status::CLOSED)->count(),
            'rejected'         => $base()->where('status', Status::REJECTED)->count(),
            // Back-compat aliases used by older UI code
            'pending_l1'       => $base()->where('status', Status::L1_PENDING)->count(),
            'pending_l2'       => $base()->where('status', Status::L2_PENDING)->count(),
            'approved'         => $base()->whereIn('status', Status::HR_QUEUE)->count(),
        ];
    }

    public function pendingCount(int $tenantId): int
    {
        return HrManpowerRequest::where('tenant_id', $tenantId)
            ->whereIn('status', [Status::L1_PENDING, Status::L2_PENDING])
            ->count();
    }

    public function assignManager(HrManpowerRequest $manpowerRequest, int $managerId, User $user): HrManpowerRequest
    {
        $this->assertTenant($manpowerRequest, $user);

        $manager = User::where('tenant_id', $manpowerRequest->tenant_id)->findOrFail($managerId);
        $manpowerRequest->update(['assigned_manager_id' => $manager->id]);

        Log::channel('hr')->info('Manpower request manager assigned', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'manager_id' => $manager->id]);

        return $manpowerRequest->fresh()->load('assignedManager');
    }

    /* ── Internal helpers ───────────────────────────────────────────────── */

    /**
     * Guard route-model-bound requests against cross-tenant access. Isolation
     * here is row-level (tenant_id), so this is the safety net that implicit
     * binding (resolve-by-id) does not provide.
     */
    private function assertTenant(HrManpowerRequest $manpowerRequest, User $user): void
    {
        if ((int) $manpowerRequest->tenant_id !== (int) $user->tenant_id) {
            Log::channel('hr')->warning('Cross-tenant manpower access blocked', [
                'request_id'      => $manpowerRequest->id,
                'record_tenant'   => $manpowerRequest->tenant_id,
                'user_tenant'     => $user->tenant_id,
                'user_id'         => $user->id,
            ]);
            throw new UnauthorizedTenantException('Request not found');
        }
    }

    private function authorize(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw new BusinessException($message, 403);
        }
    }

    /**
     * Record a workflow action on the reusable audit trail. Actor name/role,
     * tenant and timestamp are snapshotted by AuditLogService.
     */
    /**
     * The L1 (Department Head) approval state transition and its history entry.
     *
     * Single source of truth shared by manual approval (approveL1) and the
     * automatic L1 approval performed on submission (SPK-1). Callers own the
     * permission/status checks and the surrounding transaction; this only writes
     * the L1 → L2 transition exactly the same way for both paths, so no approval
     * logic is duplicated and the audit history is identical.
     */
    private function applyL1Approval(HrManpowerRequest $mr, User $actor, ?string $remarks, bool $auto = false): void
    {
        $mr->update([
            'status'         => Status::L2_PENDING,
            'l1_status'      => 'approved',
            'l1_approver_id' => $actor->id,
            'l1_approved_at' => now(),
            'l1_remarks'     => $remarks,
        ]);

        $this->logHistory($mr, 'L1', 'Approved', $actor,
            $remarks ?? ($auto ? 'L1 auto-approved on submission' : 'L1 Approved by Department Head'),
            ['status' => Status::L1_PENDING], ['status' => Status::L2_PENDING, 'l1_status' => 'approved']);

        Log::channel('hr')->info('Manpower request L1 approved', [
            'request_id' => $mr->id, 'tenant_id' => $mr->tenant_id, 'approver_id' => $actor->id, 'auto' => $auto,
        ]);
    }

    private function logHistory(HrManpowerRequest $mr, string $level, string $action, User $user, ?string $remarks, ?array $oldValues, ?array $newValues): void
    {
        $mr->recordAudit($action, $user, $remarks, [
            'level' => $level !== 'General' ? $level : null,
            'from'  => $oldValues['status'] ?? null,
            'to'    => $newValues['status'] ?? null,
        ]);
    }
}
