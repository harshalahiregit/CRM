<?php

namespace App\Jobs\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\Scoring\CandidateScoringEngine;
use App\Services\Hr\Scoring\ScoreRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-score one candidate.
 *
 * Deliberately thin: it loads, delegates to CandidateScoringEngine and persists via
 * ScoreRecorder. There is NO scoring logic here — a second place that computes a
 * score is exactly what this whole effort exists to remove.
 *
 * Queued (database connection) because a job-posting edit fans this out across every
 * applicant; running it inline would put ten dimension evaluations on the request
 * thread of an unrelated save.
 *
 * Ids are passed rather than models: SerializesModels would re-resolve a stale row,
 * and the payload must survive a candidate being edited again before the worker
 * picks it up. The tenant travels with the payload so the job can verify the
 * candidate still belongs to the tenant that queued it.
 */
class RecalculateCandidateScore implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Recognised triggers, recorded on the score row and the history trail. */
    public const TRIGGER_CANDIDATE_CREATED   = 'candidate_created';
    public const TRIGGER_CANDIDATE_UPDATED   = 'candidate_updated';
    public const TRIGGER_APPLICATION         = 'application_submitted';
    public const TRIGGER_RESUME_UPLOADED     = 'resume_uploaded';
    public const TRIGGER_RESUME_DELETED      = 'resume_deleted';
    public const TRIGGER_INTERVIEW_COMPLETED = 'interview_completed';
    public const TRIGGER_JOB_UPDATED         = 'job_updated';
    public const TRIGGER_MANUAL              = 'manual_recalculate';

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $candidateId,
        public readonly ?int $tenantId = null,
        public readonly string $trigger = self::TRIGGER_MANUAL,
        public readonly ?int $jobPostingId = null,
    ) {
    }

    public function handle(CandidateScoringEngine $engine, ScoreRecorder $recorder): void
    {
        // A batch cancelled mid-flight (e.g. the job posting was deleted) must stop
        // rather than score against a requisition that no longer exists.
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Tenant is applied explicitly — HR models carry no BelongsToTenant trait and
        // no global scope, so isolation is the caller's responsibility everywhere.
        $query = HrCandidate::query()->with(['jobPosting.manpowerRequest', 'interviewRounds']);
        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $candidate = $query->find($this->candidateId);
        if (! $candidate) {
            // Deleted, or queued under a different tenant. Not an error worth retrying.
            Log::channel('hr')->info('Score recalculation skipped: candidate not found in tenant', [
                'candidate_id' => $this->candidateId, 'tenant_id' => $this->tenantId, 'trigger' => $this->trigger,
            ]);

            return;
        }

        $job = $this->resolveJob($candidate);

        $result = $engine->score($candidate, $job);
        $recorder->record($candidate, $result, $this->trigger);
    }

    /**
     * An explicit posting wins (the job-update fan-out names it), but only when it
     * belongs to the same tenant. Otherwise fall back to the candidate's own posting,
     * which the engine resolves itself.
     */
    private function resolveJob(HrCandidate $candidate): ?HrJobPosting
    {
        if ($this->jobPostingId === null) {
            return $candidate->jobPosting;
        }

        return HrJobPosting::query()
            ->where('id', $this->jobPostingId)
            ->where('tenant_id', $candidate->tenant_id)
            ->with('manpowerRequest')
            ->first() ?? $candidate->jobPosting;
    }

    /** Deduplicate identical recalculations queued in the same burst. */
    public function uniqueId(): string
    {
        return 'air-score:'.$this->candidateId.':'.($this->jobPostingId ?? 'own');
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('hr')->error('Score recalculation failed', [
            'candidate_id' => $this->candidateId, 'trigger' => $this->trigger, 'error' => $e->getMessage(),
        ]);
    }
}
