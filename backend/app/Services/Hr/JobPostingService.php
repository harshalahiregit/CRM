<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Support\Hr\JobPostingStatus as Status;
use App\Support\Hr\ManpowerRequestStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobPostingService
{
    public function list(User $user, array $filters): Collection
    {
        $query = HrJobPosting::where('tenant_id', $user->tenant_id)
            ->with(['manpowerRequest.requester', 'manpowerRequest.l1Approver', 'manpowerRequest.l2Approver', 'manpowerRequest.assignedManager'])
            ->withCount($this->progressCounts())
            ->withMax('candidates', 'updated_at')
            ->withMax('interviews', 'scheduled_at')
            ->withMax('offers', 'updated_at');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get()->each(function ($job) {
            $job->setAttribute('progress', $this->progress($job));
            $this->attachLastActivity($job);
        });
    }

    public function find(HrJobPosting $jobPosting, User $user): HrJobPosting
    {
        $this->assertTenant($jobPosting, $user);

        $jobPosting->loadCount($this->progressCounts())
            ->loadMax('candidates', 'updated_at')
            ->loadMax('interviews', 'scheduled_at')
            ->loadMax('offers', 'updated_at')
            ->load([
                'manpowerRequest.requester', 'manpowerRequest.l1Approver', 'manpowerRequest.l2Approver',
                'auditLogs.actor', 'publications',
                // Full candidate pipeline for the Recruitment Workspace tabs.
                'candidates' => fn ($q) => $q->latest(),
                'candidates.interviewRounds', 'candidates.offer',
            ]);
        $jobPosting->setAttribute('progress', $this->progress($jobPosting));
        $this->attachLastActivity($jobPosting);

        return $jobPosting;
    }

    public function create(array $data, User $user): HrJobPosting
    {
        return DB::transaction(function () use ($data, $user) {
            $job = HrJobPosting::create([
                ...$data,
                'tenant_id' => $user->tenant_id,                 // fixes the tenant_id=null gap
                'status'    => $data['status'] ?? Status::DRAFT,
            ]);
            if (($job->status ?? null) && Status::isLive($job->status) && ! $job->published_at) {
                $job->update(['published_at' => now()]);
            }
            $job->recordAudit('Created', $user, 'Job posting created', ['to' => $job->status]);

            Log::channel('hr')->info('Job posting created', ['job_posting_id' => $job->id, 'tenant_id' => $user->tenant_id]);

            return $job;
        });
    }

    public function update(HrJobPosting $jobPosting, array $data, User $user): HrJobPosting
    {
        $this->assertTenant($jobPosting, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to edit this job');

        unset($data['tenant_id'], $data['status']); // status changes go through updateStatus/lifecycle
        $jobPosting->update($data);
        $jobPosting->recordAudit('Edited', $user, 'Job details updated');

        Log::channel('hr')->info('Job posting updated', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id]);

        return $jobPosting->fresh();
    }

    /* ── Lifecycle actions (role-gated + audited) ───────────────────────── */

    public function publish(HrJobPosting $jobPosting, User $user): HrJobPosting
    {
        return $this->transition($jobPosting, $user, Status::PUBLISHED, 'Published', function ($job) {
            if (! $job->published_at) {
                $job->published_at = now();
            }
        });
    }

    public function unpublish(HrJobPosting $jobPosting, User $user): HrJobPosting
    {
        return $this->transition($jobPosting, $user, Status::DRAFT, 'Unpublished');
    }

    public function pause(HrJobPosting $jobPosting, User $user): HrJobPosting
    {
        return $this->transition($jobPosting, $user, Status::ON_HOLD, 'Paused');
    }

    public function close(HrJobPosting $jobPosting, User $user, ?string $remarks = null): HrJobPosting
    {
        return $this->transition($jobPosting, $user, Status::CLOSED, 'Closed', null, $remarks);
    }

    public function cancel(HrJobPosting $jobPosting, User $user, ?string $remarks = null): HrJobPosting
    {
        return $this->transition($jobPosting, $user, Status::CANCELLED, 'Cancelled', null, $remarks);
    }

    public function updateStatus(HrJobPosting $jobPosting, string $status, User $user): HrJobPosting
    {
        if (! Status::isValid($status)) {
            throw new BusinessException('Invalid job status', 422);
        }

        return $this->transition($jobPosting, $user, $status, 'Status Changed', function ($job) use ($status) {
            if (Status::isLive($status) && ! $job->published_at) {
                $job->published_at = now();
            }
        });
    }

    /** Duplicate a posting as a fresh Draft (unlinked, counts reset). */
    public function duplicate(HrJobPosting $jobPosting, User $user): HrJobPosting
    {
        $this->assertTenant($jobPosting, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to duplicate this job');

        return DB::transaction(function () use ($jobPosting, $user) {
            $copy = $jobPosting->replicate([
                'manpower_request_id', 'published_at', 'applicant_count', 'external_job_ids',
            ]);
            $copy->title = 'Copy of '.$jobPosting->title;
            $copy->status = Status::DRAFT;
            $copy->tenant_id = $user->tenant_id;
            $copy->applicant_count = 0;
            $copy->save();

            $copy->recordAudit('Duplicated', $user, 'Duplicated from job #'.$jobPosting->id, ['source_id' => $jobPosting->id]);

            Log::channel('hr')->info('Job posting duplicated', ['from' => $jobPosting->id, 'to' => $copy->id, 'tenant_id' => $user->tenant_id]);

            return $copy;
        });
    }

    public function updateExternalId(HrJobPosting $jobPosting, string $platform, string $externalId, User $user): array
    {
        $this->assertTenant($jobPosting, $user);

        $externalIds = $jobPosting->external_job_ids ?? [];
        $externalIds[$platform] = $externalId;
        $jobPosting->update(['external_job_ids' => $externalIds]);
        $jobPosting->recordAudit('Posted to '.ucfirst($platform), $user, 'External ID '.$externalId);

        return $externalIds;
    }

    public function destroy(HrJobPosting $jobPosting, User $user): void
    {
        $this->assertTenant($jobPosting, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to delete this job');

        $jobPosting->delete();

        Log::channel('hr')->info('Job posting deleted', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id]);
    }

    /**
     * Apply a lifecycle action to many postings at once (List-view bulk action).
     * Reuses the per-job methods, so tenant + role guards and audit logging still
     * apply to each. Jobs where the action isn't valid are skipped, not aborted.
     */
    public function bulk(string $action, array $ids, User $user): array
    {
        // Tenant-scoped fetch — cross-tenant ids are silently excluded here, and
        // each action re-guards tenant + role below.
        $jobs = HrJobPosting::where('tenant_id', $user->tenant_id)->whereIn('id', $ids)->get();

        $success = 0;
        $skipped = 0;
        foreach ($jobs as $job) {
            try {
                match ($action) {
                    'publish' => $this->publish($job, $user),
                    'pause'   => $this->pause($job, $user),
                    'close'   => $this->close($job, $user),
                    'delete'  => $this->destroy($job, $user),
                };
                $success++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        Log::channel('hr')->info('Job posting bulk action', ['action' => $action, 'tenant_id' => $user->tenant_id, 'success' => $success, 'skipped' => $skipped]);

        return ['action' => $action, 'success' => $success, 'skipped' => $skipped, 'total' => count($ids)];
    }

    /* ── Recruitment progress + dashboard ───────────────────────────────── */

    /**
     * Per-job recruitment funnel (uses eager-loaded counts when present).
     *
     * Raw stage counts are clamped so the pipeline is always logically
     * consistent — each stage can never exceed its parent:
     *   Applications ≥ Shortlisted(Selected) ≥ {Interviews, Offers}
     *   Offers ≥ Offers Accepted ≥ Joined ; Filled ≤ Required
     */
    public function progress(HrJobPosting $job): array
    {
        $required     = (int) $job->number_of_openings;
        $applications = (int) ($job->applications_count    ?? $job->candidates()->count());
        $selected     = (int) ($job->shortlisted_count     ?? $job->candidates()->where('final_decision', 'Selected')->count());
        $interviewed  = (int) ($job->interviews_count      ?? $job->candidates()->has('interviewRounds')->count());
        $offered      = (int) ($job->offers_count          ?? $job->candidates()->has('offer')->count());
        $accepted     = (int) ($job->offers_accepted_count ?? $job->candidates()->whereHas('offer', fn ($o) => $o->where('status', 'Accepted'))->count());
        $hired        = (int) ($job->joined_count          ?? $job->candidates()->where('stage', 'Hired')->count());

        $shortlisted    = min($selected, $applications);
        $interviews     = min($interviewed, $shortlisted);   // Interviews ≤ Shortlisted
        $offers         = min($offered, $shortlisted);       // Offers ≤ Selected
        $offersAccepted = min($accepted, $offers);           // Accepted ≤ Offers
        $joined         = min($hired, $offersAccepted);      // Joined ≤ Offers Accepted
        $filled         = min($joined, $required);

        return [
            'required'        => $required,
            'filled'          => $filled,
            'remaining'       => max(0, $required - $filled),
            'hiring_pct'      => $required > 0 ? (int) round($filled / $required * 100) : 0,
            'applications'    => $applications,
            'shortlisted'     => $shortlisted,
            'interviews'      => $interviews,
            'offers'          => $offers,
            'offers_accepted' => $offersAccepted,
            'joined'          => $joined,
        ];
    }

    /** The 6 Job-Posting dashboard metrics. */
    public function stats(int $tenantId): array
    {
        $jobs = fn () => HrJobPosting::where('tenant_id', $tenantId);

        $filled        = HrCandidate::where('tenant_id', $tenantId)->whereNotNull('job_posting_id')->where('stage', 'Hired')->count();
        $liveOpenings  = (int) $jobs()->whereIn('status', Status::LIVE)->sum('number_of_openings');

        return [
            'approved_requests'   => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', ManpowerRequestStatus::READY_FOR_HR)->count(),
            'pending_publication' => $jobs()->whereIn('status', [Status::DRAFT, Status::READY_FOR_HR])->count(),
            'published_jobs'      => $jobs()->where('status', Status::PUBLISHED)->count(),
            'active_jobs'         => $jobs()->whereIn('status', Status::LIVE)->count(),
            'filled_positions'    => $filled,
            'remaining_positions' => max(0, $liveOpenings - $filled),
        ];
    }

    /* ── Internal helpers ───────────────────────────────────────────────── */

    private function transition(HrJobPosting $job, User $user, string $status, string $action, ?callable $mutate = null, ?string $comment = null): HrJobPosting
    {
        $this->assertTenant($job, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to change this job');

        return DB::transaction(function () use ($job, $user, $status, $action, $mutate, $comment) {
            $from = $job->status;
            $job->status = $status;
            if ($mutate) {
                $mutate($job);
            }
            $job->save();

            $job->recordAudit($action, $user, $comment, ['from' => $from, 'to' => $status]);

            Log::channel('hr')->info('Job posting '.strtolower($action), ['job_posting_id' => $job->id, 'tenant_id' => $job->tenant_id, 'from' => $from, 'to' => $status]);

            return $job->fresh();
        });
    }

    /**
     * Derive "last activity" from the most recent of: candidate change,
     * interview scheduled, offer change, or the job itself. Sets a
     * `last_activity` attribute ['at' => ISO8601, 'label' => string].
     */
    private function attachLastActivity(HrJobPosting $job): void
    {
        $sources = [
            'Candidate activity'  => $job->candidates_max_updated_at ?? null,
            'Interview scheduled' => $job->interviews_max_scheduled_at ?? null,
            'Offer updated'       => $job->offers_max_updated_at ?? null,
            'Job updated'         => $job->updated_at,
        ];

        $latestAt = null;
        $latestLabel = null;
        foreach ($sources as $label => $value) {
            if (! $value) {
                continue;
            }
            $ts = $value instanceof \Carbon\CarbonInterface ? $value : \Illuminate\Support\Carbon::parse($value);
            if (! $latestAt || $ts->greaterThan($latestAt)) {
                $latestAt = $ts;
                $latestLabel = $label;
            }
        }

        $job->setAttribute('last_activity', $latestAt ? ['at' => $latestAt->toIso8601String(), 'label' => $latestLabel] : null);
    }

    private function progressCounts(): array
    {
        return [
            'candidates as applications_count',
            'candidates as shortlisted_count'     => fn ($q) => $q->where('final_decision', 'Selected'),
            'candidates as interviews_count'      => fn ($q) => $q->has('interviewRounds'),
            'candidates as offers_count'          => fn ($q) => $q->has('offer'),
            'candidates as offers_accepted_count' => fn ($q) => $q->whereHas('offer', fn ($o) => $o->where('status', 'Accepted')),
            'candidates as joined_count'          => fn ($q) => $q->where('stage', 'Hired'),
        ];
    }

    /**
     * Guard route-model-bound postings against cross-tenant access (implicit
     * binding resolves by global id; isolation is row-level tenant_id).
     */
    private function assertTenant(HrJobPosting $job, User $user): void
    {
        if ((int) $job->tenant_id !== (int) $user->tenant_id) {
            Log::channel('hr')->warning('Cross-tenant job access blocked', [
                'job_posting_id' => $job->id, 'record_tenant' => $job->tenant_id, 'user_tenant' => $user->tenant_id, 'user_id' => $user->id,
            ]);
            throw new UnauthorizedTenantException('Job not found');
        }
    }

    private function authorize(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw new BusinessException($message, 403);
        }
    }
}
