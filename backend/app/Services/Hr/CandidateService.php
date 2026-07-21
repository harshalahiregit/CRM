<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrJobPosting;
use App\Models\User;
use App\Support\Hr\ManpowerRequestStatus;
use App\Notifications\WhatsApp\ApplicationReceivedNotification;
use App\Notifications\WhatsApp\StatusUpdateNotification;
use App\Repositories\Hr\CandidateRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CandidateService
{
    /** Stages driven by the recruitment workflow — never set by a manual move. */
    public const SYSTEM_CONTROLLED_STAGES = ['Offer', 'Hired'];

    public function __construct(private CandidateRepository $candidateRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->candidateRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, int $tenantId): HrCandidate
    {
        $data['tenant_id'] = $tenantId;

        if (! empty($data['email']) && ! empty($data['job_posting_id'])) {
            $exists = $this->candidateRepository->emailExistsForJobPosting($data['email'], $data['job_posting_id'], $tenantId);
            if ($exists) {
                throw new BusinessException('A candidate with this email already applied for this job.', 422);
            }
        }

        $aiData = $this->computeAiScore($data);
        $data['ai_score']     = $aiData['score'];
        $data['ai_breakdown'] = $aiData['breakdown'];

        $candidate = HrCandidate::create($data);

        if ($candidate->job_posting_id) {
            HrJobPosting::where('id', $candidate->job_posting_id)->increment('applicant_count');
        }

        if ($candidate->email) {
            Mail::to($candidate->email)->send(
                new \App\Mail\ApplicationReceivedMail($candidate->load('jobPosting'))
            );
        }

        ApplicationReceivedNotification::send($candidate);

        // Timeline: opens the candidate's audit trail. Actor auto-resolves to the
        // authenticated HR user, or "System" for public Career-Portal applies.
        $candidate->recordAudit('Applied', null, null, array_filter([
            'source' => $candidate->source,
            'stage'  => $candidate->stage,
            'job'    => $candidate->jobPosting?->title,
        ]));

        Log::channel('hr')->info('Candidate created', ['candidate_id' => $candidate->id, 'tenant_id' => $tenantId]);

        return $candidate;
    }

    public function update(HrCandidate $candidate, array $data): HrCandidate
    {
        // Guard the general update endpoint too: Offer/Hired can't be set manually.
        if (isset($data['stage']) && in_array($data['stage'], self::SYSTEM_CONTROLLED_STAGES, true) && $data['stage'] !== $candidate->stage) {
            throw new BusinessException('Offer and Hired stages are managed automatically by the recruitment workflow.', 422);
        }

        $candidate->update($data);

        Log::channel('hr')->info('Candidate updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        return $candidate->fresh()->load(['jobPosting', 'interviewRounds', 'offer']);
    }

    /**
     * SPK-1 pipeline gates. Rejected is reachable from anywhere; every other move
     * must be one step forward and satisfy that stage's entry condition, so a
     * candidate cannot be dragged past a step that has not actually happened.
     *
     * Offer/Hired are handled by the SYSTEM_CONTROLLED_STAGES guard above — they
     * are reached by onboarding approval and offer acceptance, never by a drag.
     */
    private function assertTransitionAllowed(HrCandidate $candidate, string $stage, array $order): void
    {
        if ($stage === 'Rejected' || $stage === $candidate->stage) {
            return;   // rejection is always allowed; a no-op move is not a transition
        }

        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$stage] ?? 0;

        // No skipping — "Applied → Interview" hides the screening that never ran.
        if ($newIdx > $currentIdx + 1) {
            $next = array_search($currentIdx + 1, $order, true);
            throw new BusinessException(
                "Move to {$next} first — stages cannot be skipped.", 422
            );
        }

        // Entry conditions for the stage being moved INTO.
        if ($stage === 'Assessment' && ! ((float) ($candidate->ai_score ?? 0) > 0)) {
            throw new BusinessException(
                'AI screening has not completed for this candidate yet — no AI score on record.', 422
            );
        }
    }

    public function updateStage(HrCandidate $candidate, string $stage): HrCandidate
    {
        // Offer & Hired are system-controlled: onboarding approval moves a
        // candidate to Offer, and offer acceptance moves them to Hired (creating
        // the Employee). A manual move to either is never allowed.
        if (in_array($stage, self::SYSTEM_CONTROLLED_STAGES, true)) {
            throw new BusinessException('Offer and Hired stages are managed automatically by the recruitment workflow.', 422);
        }

        $order = ['Applied' => 0, 'Screening' => 1, 'Assessment' => 2, 'Interview' => 3, 'Offer' => 4, 'Hired' => 5, 'Rejected' => 6];
        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$stage] ?? 0;
        if ($newIdx < $currentIdx && $stage !== 'Rejected') {
            Log::channel('hr')->warning('Candidate stage change rejected: backward move', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $candidate->stage, 'to' => $stage]);
            throw new BusinessException('Stage can only move forward in the pipeline.', 422);
        }

        $this->assertTransitionAllowed($candidate, $stage, $order);

        $oldStage = $candidate->stage;
        $candidate->update(['stage' => $stage]);

        if ($oldStage !== $stage) {
            // Audit + log first — the stage change and its timeline entry must
            // persist regardless of whether any downstream notification fails.
            $candidate->recordAudit('Moved to '.$stage, null, null, ['from' => $oldStage, 'to' => $stage]);
            Log::channel('hr')->info('Candidate stage changed', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $oldStage, 'to' => $stage]);

            StatusUpdateNotification::send($candidate, $stage);

            // Email is best-effort: a mail failure must never roll back the stage
            // change or break the request — log it and carry on.
            if ($candidate->email) {
                try {
                    $statusMessage = match ($stage) {
                        'Rejected' => 'We appreciate your interest and wish you the best in your career endeavors.',
                        'Hired'    => 'Congratulations on successfully completing all rounds!',
                        default    => '',
                    };

                    Mail::to($candidate->email)->send(
                        new \App\Mail\ApplicationStatusMail($candidate->load('jobPosting'), $stage, $statusMessage)
                    );
                } catch (\Throwable $e) {
                    Log::channel('hr')->error('Candidate stage email failed', [
                        'candidate_id' => $candidate->id,
                        'tenant_id'    => $candidate->tenant_id,
                        'stage'        => $stage,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        }

        return $candidate;
    }

    public function updateDecision(HrCandidate $candidate, string $decision): HrCandidate
    {
        $oldStage = $candidate->stage;
        $candidate->update(['final_decision' => $decision]);

        if ($decision === 'Selected') {
            $candidate->update(['stage' => 'Hired']);
        } elseif ($decision === 'Rejected') {
            $candidate->update(['stage' => 'Rejected']);
        }

        // One audit line captures the decision and any implied stage change, so
        // the timeline reads cleanly (no duplicate "Moved to …" entry).
        $candidate->recordAudit('Decision: '.$decision, null, null, array_filter([
            'decision'   => $decision,
            'from_stage' => $oldStage !== $candidate->stage ? $oldStage : null,
            'to_stage'   => $oldStage !== $candidate->stage ? $candidate->stage : null,
        ]));

        Log::channel('hr')->info('Candidate decision updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'decision' => $decision]);

        return $candidate;
    }

    public function destroy(HrCandidate $candidate): void
    {
        $candidate->delete();

        Log::channel('hr')->info('Candidate deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);
    }

    /**
     * Assign (or clear, when $recruiterId is null) the owning recruiter. The
     * recruiter must be an assignable user in the candidate's own tenant.
     */
    public function assignRecruiter(HrCandidate $candidate, ?int $recruiterId): HrCandidate
    {
        $recruiter = null;

        if ($recruiterId) {
            $recruiter = $this->assignableRecruiters($candidate->tenant_id)
                ->firstWhere('id', $recruiterId);

            if (! $recruiter) {
                throw new BusinessException('Selected recruiter is not available in this workspace.', 422);
            }
        }

        $candidate->update(['assigned_recruiter_id' => $recruiter?->id]);

        $candidate->recordAudit(
            $recruiter ? 'Assigned to '.$recruiter->name : 'Recruiter unassigned',
            null,
            null,
            array_filter(['recruiter_id' => $recruiter?->id, 'recruiter' => $recruiter?->name])
        );

        Log::channel('hr')->info('Candidate recruiter assigned', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'recruiter_id' => $recruiter?->id]);

        return $candidate->fresh()->load('assignedRecruiter');
    }

    /** Users in a tenant who can own candidates (internal team, active). */
    public function assignableRecruiters(int $tenantId)
    {
        return User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'internal_role']);
    }

    // ── AI Score Calculator ─────────────────────────────────────────────
    private function computeAiScore(array $data): array
    {
        $skills    = $data['skills'] ?? [];
        $expYears  = (float) ($data['experience_years'] ?? 0);
        $location  = $data['location'] ?? '';
        $job       = isset($data['job_posting_id'])
            ? HrJobPosting::find($data['job_posting_id'])
            : null;

        $skillScore = 50; // base
        if ($job && $job->requirements && ! empty($skills)) {
            $reqs    = strtolower($job->requirements);
            $matched = 0;
            foreach ($skills as $skill) {
                if (str_contains($reqs, strtolower($skill))) {
                    $matched++;
                }
            }
            $skillScore = min(100, ($matched / max(count($skills), 1)) * 100);
        } elseif (! empty($skills)) {
            $skillScore = min(100, count($skills) * 12);
        }

        $expScore = match (true) {
            $expYears >= 6 => 100,
            $expYears >= 4 => 85,
            $expYears >= 2 => 65,
            $expYears >= 1 => 45,
            default        => 25,
        };

        $locationScore = 60;
        if ($job && $job->location && $location) {
            $locationScore = str_contains(
                strtolower($job->location),
                strtolower(explode(',', $location)[0])
            ) ? 100 : 40;
            if (str_contains(strtolower($job->location), 'remote') ||
                str_contains(strtolower($job->job_type ?? ''), 'remote')) {
                $locationScore = 90;
            }
        }

        $educationScore = 70;
        $overallFit     = ($skillScore + $expScore) / 2;

        $total = ($skillScore * 0.40) + ($expScore * 0.30) + ($locationScore * 0.10)
               + ($educationScore * 0.10) + ($overallFit * 0.10);

        $total = min(100, max(0, round($total)));

        return [
            'score'     => $total,
            'breakdown' => [
                'skills_match'   => round($skillScore),
                'exp_match'      => round($expScore),
                'location_match' => round($locationScore),
                'education'      => $educationScore,
                'overall_fit'    => round($overallFit),
            ],
        ];
    }

    /**
     * SPK-1: full application AI evaluation (reuses computeAiScore for skills/exp).
     * Adds Resume, JD and Question match with explained strengths / weaknesses and
     * a Proceed / Hold / Reject recommendation. Persisted into ai_score +
     * ai_breakdown (existing columns) — no new scoring engine, no duplicate data.
     */
    public function evaluateApplication(HrCandidate $candidate, array $answers = []): HrCandidate
    {
        $candidate->loadMissing('jobPosting');
        $job = $candidate->jobPosting;

        $base = $this->computeAiScore([
            'skills'           => $candidate->skills ?? [],
            'experience_years' => $candidate->experience_years,
            'location'         => $candidate->location,
            'job_posting_id'   => $candidate->job_posting_id,
        ])['breakdown'];

        $skillMatch = (int) $base['skills_match'];

        // Resume match — a present resume + relevant skills/experience signal.
        $resumeMatch = (int) round(
            ($candidate->resume_path ? 55 : 20)
            + min(30, ($skillMatch / 100) * 30)
            + min(15, (float) ($candidate->experience_years ?? 0) * 3)
        );

        // JD match — how well the candidate's skills overlap the JD text.
        $jdText = strtolower(trim(($job->description ?? '').' '.($job->requirements ?? '')));
        $skills = $candidate->skills ?? [];
        $jdMatch = 60;
        $jdHits = 0;
        if ($jdText && $skills) {
            foreach ($skills as $s) {
                if ($s && str_contains($jdText, strtolower($s))) {
                    $jdHits++;
                }
            }
            $jdMatch = (int) round(min(100, 45 + ($jdHits / max(count($skills), 1)) * 55));
        }

        // Question match — answered mandatory questions + Yes/No positivity.
        $questions = $job?->screening_questions ?? [];
        $questionMatch = $this->scoreScreeningAnswers($questions, $answers);

        // Education match — candidate education vs the requisition's education
        // requirement (reuses hr_manpower_requests.education).
        $educationMatch = $this->scoreEducation($candidate, $job);

        $overall = (int) round(
            $resumeMatch * 0.30 + $jdMatch * 0.25 + $questionMatch * 0.20 + $skillMatch * 0.25
        );
        $overall = max(0, min(100, $overall));

        // ── Explained strengths / weaknesses ─────────────────────────────────
        $strengths = [];
        $weaknesses = [];
        if ($resumeMatch >= 70) { $strengths[] = 'Resume is a strong fit for the role'; } else { $weaknesses[] = 'Resume shows limited alignment with the role'; }
        if ($jdMatch >= 70) { $strengths[] = 'Skills overlap well with the job description'; } else { $weaknesses[] = 'Few skills match the job description keywords'; }
        if ($skillMatch >= 70) { $strengths[] = 'Skill set matches the requirements'; } elseif ($skillMatch < 50) { $weaknesses[] = 'Skill set is below the requirement bar'; }
        if (($candidate->experience_years ?? 0) >= 4) { $strengths[] = ((float) $candidate->experience_years).' years of relevant experience'; } elseif (($candidate->experience_years ?? 0) < 1) { $weaknesses[] = 'Limited professional experience'; }
        if ($questions && $questionMatch >= 80) { $strengths[] = 'Answered screening questions strongly'; } elseif ($questions && $questionMatch < 50) { $weaknesses[] = 'Weak / incomplete screening answers'; }
        if ($educationMatch >= 80) { $strengths[] = 'Education meets the requisition requirement'; } elseif ($educationMatch < 50) { $weaknesses[] = 'Education does not clearly match the requirement'; }

        // A candidate with no skills AND no experience on file must never surface as
        // recommended. The heuristic gives baseline credit for unscored dimensions
        // (skills default to 50, and a job with no screening questions scores 100),
        // which can add up to a passing overall on an empty profile. That is absence
        // of evidence, not evidence of fit — so it is held for a human to look at.
        $noEvidence = empty($skills) && (float) ($candidate->experience_years ?? 0) <= 0;

        // SPK-1 recommendation bands. "Recommended with Training" is the case that
        // matters: a candidate strong enough overall but carrying a skill gap that
        // training can close — previously indistinguishable from a plain Proceed.
        [$recommendation, $recommendationReason] = match (true) {
            $noEvidence => [
                'Hold',
                'No skills or experience recorded — too little information to score this candidate. Review the resume before deciding.',
            ],
            $overall >= 60 && $skillMatch < 60 => [
                'Recommended with Training',
                sprintf('Overall match is %d%%, but skills match only %d%% — a capable hire once the skill gap is closed.', $overall, $skillMatch),
            ],
            $overall >= 60 => [
                'Recommended',
                sprintf('Overall match is %d%% with a %d%% skills match — meets the bar on both.', $overall, $skillMatch),
            ],
            $overall >= 50 => [
                'Hold',
                sprintf('Overall match is %d%% — borderline; compare against other applicants before deciding.', $overall),
            ],
            default => [
                'Reject',
                sprintf('Overall match is %d%%, below the 50%% bar for this role.', $overall),
            ],
        };

        // SPK-1: explain WHY each score came out the way it did. Built from the same
        // inputs the scores use, so the wording can never drift from the numbers.
        $expYears    = (float) ($candidate->experience_years ?? 0);
        $skillCount  = count($skills);
        $answered    = count(array_filter($answers, fn ($a) => $a !== null && $a !== ''));
        $reasons = [
            'resume_match' => $candidate->resume_path
                ? sprintf('Resume on file, %d%% skill match and %s year(s) experience.', $skillMatch, rtrim(rtrim(number_format($expYears, 1), '0'), '.'))
                : 'No resume uploaded — scored on skills and experience only.',
            'jd_match' => ! $jdText
                ? 'The job description has no text to compare against — neutral score.'
                : (! $skillCount
                    ? 'No skills listed on the candidate — neutral score.'
                    : sprintf('%d of %d listed skill(s) appear in the job description.', $jdHits, $skillCount)),
            'skill_match'     => $this->skillReason($candidate, $job, $skills),
            'education_match' => $this->educationReason($candidate, $job, $educationMatch),
            'question_match'  => $questions
                ? sprintf('Answered %d of %d screening question(s).', $answered, count($questions))
                : 'No screening questions configured on this job — full credit by default.',
            'exp_match' => $expYears > 0
                ? sprintf('%s year(s) of experience against the role requirement.', rtrim(rtrim(number_format($expYears, 1), '0'), '.'))
                : 'No experience recorded on the candidate.',
            'overall' => sprintf(
                'Weighted: Resume 30%% (%d) + JD 25%% (%d) + Questions 20%% (%d) + Skills 25%% (%d).',
                $resumeMatch, $jdMatch, $questionMatch, $skillMatch
            ),
        ];

        $breakdown = array_merge($base, [
            'resume_match'   => $resumeMatch,
            'jd_match'       => $jdMatch,
            'question_match' => $questionMatch,
            'skill_match'    => $skillMatch,
            'education_match' => $educationMatch,
            'overall'        => $overall,
            'strengths'      => array_values($strengths),
            'weaknesses'     => array_values($weaknesses),
            'recommendation'        => $recommendation,
            'recommendation_reason' => $recommendationReason,
            'reasons'               => $reasons,
        ]);

        $candidate->update([
            'ai_score'          => $overall,
            'ai_breakdown'      => $breakdown,
            'screening_answers' => $answers ?: $candidate->screening_answers,
        ]);

        return $candidate;
    }

    /**
     * Education match — compares the candidate's education entries against the
     * requisition's education requirement (hr_manpower_requests.education), with
     * a neutral score when there is nothing to compare against.
     */
    /**
     * Plain-English reason for the skills score, mirroring the branches in
     * computeAiScore() — which of the three paths produced the number.
     */
    private function skillReason(HrCandidate $candidate, $job, array $skills): string
    {
        if (empty($skills)) {
            return 'No skills listed on the candidate — scored at the 50% baseline.';
        }
        if (! $job || ! $job->requirements) {
            return sprintf('This job lists no requirements to match against — scored on %d listed skill(s).', count($skills));
        }

        $reqs = strtolower($job->requirements);
        $matched = [];
        foreach ($skills as $s) {
            if ($s && str_contains($reqs, strtolower($s))) {
                $matched[] = $s;
            }
        }

        return $matched
            ? sprintf('%d of %d skill(s) matched the job requirements: %s.', count($matched), count($skills), implode(', ', $matched))
            : sprintf('None of the %d listed skill(s) appear in the job requirements.', count($skills));
    }

    /**
     * Plain-English reason for the education score. Reads the same two fields
     * scoreEducation() reads, so the explanation always matches the number.
     */
    private function educationReason(HrCandidate $candidate, $job, int $score): string
    {
        $required = trim((string) ($job?->manpowerRequest?->education ?? ''));
        $hasEdu = ! empty(array_filter($candidate->education ?? []));

        if ($required === '') {
            return $hasEdu
                ? 'The requisition states no education requirement — credited for education on file.'
                : 'The requisition states no education requirement — neutral score.';
        }
        if (! $hasEdu) {
            return sprintf('The requisition requires "%s" but the candidate listed no education.', $required);
        }

        return sprintf('Matched the candidate\'s education against the requirement "%s" (%d%%).', $required, $score);
    }

    private function scoreEducation(HrCandidate $candidate, $job): int
    {
        $required = strtolower(trim((string) ($job?->manpowerRequest?->education ?? '')));

        $candidateEdu = collect($candidate->education ?? [])
            ->map(fn ($e) => is_array($e)
                ? implode(' ', array_filter([$e['degree'] ?? null, $e['institution'] ?? null, $e['field'] ?? null]))
                : (string) $e)
            ->implode(' ');
        $candidateEdu = strtolower(trim($candidateEdu));

        if ($required === '') {
            return $candidateEdu === '' ? 70 : 80;   // no requirement stated → neutral / slight credit
        }
        if ($candidateEdu === '') {
            return 40;                               // requirement exists, candidate gave nothing
        }

        // Token overlap between the requirement and the candidate's education.
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9+]+/', $required) ?: [],
            fn ($t) => strlen($t) > 2
        ));
        if (! $tokens) {
            return 70;
        }
        $hits = 0;
        foreach ($tokens as $t) {
            if (str_contains($candidateEdu, $t)) {
                $hits++;
            }
        }

        return (int) round(min(100, 40 + ($hits / count($tokens)) * 60));
    }

    /** Score screening answers: mandatory answered + Yes/No positivity. */
    private function scoreScreeningAnswers(array $questions, array $answers): int
    {
        if (empty($questions)) {
            return 100; // no gate configured → not a differentiator
        }
        $byId = collect($answers)->keyBy('question_id');
        $total = 0;
        $earned = 0;
        foreach ($questions as $q) {
            $total++;
            $a = $byId->get($q['id'] ?? null);
            $val = is_array($a) ? ($a['value'] ?? null) : null;
            $answered = $val !== null && $val !== '' && $val !== [];
            if (! $answered) {
                continue;
            }
            $earned += 1;
            if (($q['type'] ?? '') === 'yes_no' && strtolower((string) (is_array($val) ? implode('', $val) : $val)) === 'yes') {
                $earned += 0.2; // small bonus for a positive gate answer
            }
        }

        return (int) round(min(100, ($earned / max($total, 1)) * 100));
    }

    // ── LinkedIn Profile Extractor ─────────────────────────────────────
    public function linkedinParse(string $url): array
    {
        $url = trim($url);

        if (! str_contains($url, 'linkedin.com/in/')) {
            throw new BusinessException('Please provide a valid LinkedIn profile URL (linkedin.com/in/...)', 422);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->timeout(15)->get($url);

            if ($response->failed()) {
                return $this->linkedinFallback($url);
            }

            $html = $response->body();
            $data = $this->parseLinkedInHtml($html, $url);

            return [
                'success' => true,
                'data'    => $data,
                'source'  => 'scraped',
            ];
        } catch (\Exception $e) {
            return $this->linkedinFallback($url);
        }
    }

    private function parseLinkedInHtml(string $html, string $url): array
    {
        $data = [
            'name'            => '',
            'headline'        => '',
            'location'        => '',
            'current_company' => '',
            'skills'          => [],
            'profile_url'     => $url,
        ];

        if (preg_match('/<meta property="og:title" content="([^"]+)"/i', $html, $m)) {
            $ogTitle   = html_entity_decode($m[1]);
            $parts     = explode(' | ', $ogTitle);
            $nameTitle = $parts[0] ?? '';
            if (str_contains($nameTitle, ' - ')) {
                [$name, $rest] = explode(' - ', $nameTitle, 2);
                $data['name'] = trim($name);
                if (str_contains($rest, ' at ')) {
                    [$headline, $company] = explode(' at ', $rest, 2);
                    $data['headline']        = trim($headline);
                    $data['current_company'] = trim($company);
                } else {
                    $data['headline'] = trim($rest);
                }
            } else {
                $data['name'] = trim($nameTitle);
            }
        }

        if (preg_match('/<meta property="og:description" content="([^"]+)"/i', $html, $m)) {
            $desc                = html_entity_decode($m[1]);
            $data['linkedin_summary'] = $desc;
            if (preg_match('/(?:Location|·)\s*([^·]+)/i', $desc, $lm)) {
                $data['location'] = trim($lm[1]);
            }
        }

        if (empty($data['name'])) {
            preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $url, $slug);
            if (! empty($slug[1])) {
                $data['name'] = ucwords(str_replace(['-', '_'], ' ', $slug[1]));
            }
        }

        return $data;
    }

    private function linkedinFallback(string $url): array
    {
        preg_match('/linkedin\.com\/in\/([^\/\?#]+)/i', $url, $m);
        $slug = $m[1] ?? '';
        $name = ucwords(str_replace(['-', '_'], ' ', $slug));

        return [
            'success' => true,
            'data'    => [
                'name'            => $name,
                'headline'        => '',
                'location'        => '',
                'current_company' => '',
                'skills'          => [],
                'profile_url'     => $url,
            ],
            'source'  => 'url_extract',
            'note'    => 'LinkedIn rate-limited the request. Name extracted from URL. Please fill remaining details manually.',
        ];
    }

    /* ───────────────────────── Candidate Journey (SPK-1) ─────────────────────────
     | Complete recruitment-journey view for one candidate. Read-only aggregation
     | over the EXISTING relations (manpower → job → interviews → onboarding →
     | offer → employee) and audit logs. No new tables, no workflow changes.
     */
    public function journey(HrCandidate $candidate): array
    {
        $candidate->loadMissing([
            'jobPosting.manpowerRequest.requester', 'jobPosting.manpowerRequest.l1Approver', 'jobPosting.manpowerRequest.l2Approver',
            'interviewRounds', 'offer', 'onboarding', 'assignedRecruiter',
        ]);

        $job      = $candidate->jobPosting;
        $mr       = $job?->manpowerRequest;
        $onb      = $candidate->onboarding;
        $offer    = $candidate->offer;
        $employee = HrEmployee::where('candidate_id', $candidate->id)->first();
        $rounds   = $candidate->interviewRounds->sortBy('scheduled_at')->values();
        $recruiter = $candidate->assignedRecruiter?->name;

        $mrApproved = $mr && ($mr->l2_approved_at || $mr->approved_at || in_array($mr->status, [
            ManpowerRequestStatus::READY_FOR_HR, ManpowerRequestStatus::CONVERTED_TO_JD,
            ManpowerRequestStatus::JOB_POSTED, ManpowerRequestStatus::HIRING_IN_PROGRESS, ManpowerRequestStatus::CLOSED,
        ], true));
        $selected = $candidate->final_decision === 'Selected' || in_array($candidate->stage, ['Offer', 'Hired'], true) || (bool) $onb;

        $stage = fn ($key, $label, $done, $at, $by = null, $link = null) => [
            'key'          => $key,
            'label'        => $label,
            'status'       => $done ? 'completed' : 'pending',
            'at'           => $at ? \Carbon\Carbon::parse($at)->toIso8601String() : null,
            'completed_by' => $done ? $by : null,
            'link'         => $done ? $link : null,
        ];

        $stages = [];
        $stages[] = $stage('mr_created', 'Manpower Request Created', (bool) $mr, $mr?->created_at, $mr?->requester?->name, $mr ? ['type' => 'manpower', 'id' => $mr->id] : null);
        $stages[] = $stage('mr_approved', 'Manpower Approved', (bool) $mrApproved, $mr?->l2_approved_at ?? $mr?->approved_at ?? $mr?->l1_approved_at, $mr?->l2Approver?->name ?? $mr?->l1Approver?->name, $mr ? ['type' => 'manpower', 'id' => $mr->id] : null);
        $stages[] = $stage('job_posted', 'Job Posted', (bool) $job, $job?->published_at ?? $job?->created_at, $recruiter, $job ? ['type' => 'job', 'id' => $job->id] : null);
        $stages[] = $stage('applied', 'Candidate Applied', true, $candidate->applied_at ?? $candidate->created_at, $candidate->source, ['type' => 'candidate', 'id' => $candidate->id]);
        $stages[] = $stage('ai_screening', 'AI Screening Completed', (float) $candidate->ai_score > 0, $candidate->applied_at ?? $candidate->created_at, 'AI Screening'.($candidate->ai_score ? ' · '.$candidate->ai_score.'%' : ''), ['type' => 'candidate', 'id' => $candidate->id]);

        foreach ($rounds as $r) {
            $stages[] = $stage('interview_'.$r->id, 'Interview: '.$r->round_name, $r->status === 'Completed', $r->scheduled_at, $r->interviewer_name, ['type' => 'interview', 'id' => $r->id]);
        }

        $stages[] = $stage('selected', 'Selected', (bool) $selected, $offer?->created_at ?? $onb?->invited_at, $recruiter, ['type' => 'candidate', 'id' => $candidate->id]);
        $stages[] = $stage('onb_invited', 'Onboarding Invitation Sent', (bool) $onb?->invited_at, $onb?->invited_at, $recruiter, $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('onb_submitted', 'Candidate Submitted Documents', (bool) $onb?->submitted_at, $onb?->submitted_at, $candidate->name, $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('onb_verified', 'HR Verified Documents', $onb && $onb->verification_status === 'Approved', $onb?->verified_at, $recruiter ?? 'HR', $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('offer_generated', 'Offer Generated', (bool) $offer, $offer?->generated_at ?? $offer?->created_at, $recruiter ?? 'HR', $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('offer_sent', 'Offer Sent', (bool) $offer?->sent_at, $offer?->sent_at, $recruiter ?? 'HR', $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('offer_accepted', 'Offer Accepted', (bool) $offer?->accepted_at, $offer?->accepted_at, $candidate->name, $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('employee_created', 'Employee Created', (bool) $employee, $employee?->created_at, $recruiter ?? 'HR', $employee ? ['type' => 'employee', 'id' => $employee->id] : null);

        // Interview score = average of completed rounds' overall score.
        $scored = $rounds->whereNotNull('overall_score');
        $interviewScore = $scored->count() ? round((float) $scored->avg('overall_score'), 1) : null;

        // Auto-calculated macro-phase progress (0–100).
        $roundsDone = $rounds->where('status', 'Completed')->count();
        $onbPct = ! $onb ? 0 : ($onb->verification_status === 'Approved' ? 100 : ($onb->submitted_at ? 60 : ($onb->invited_at ? 30 : 0)));
        $offerPct = ! $offer ? 0 : (in_array($offer->status, ['Accepted', 'Completed'], true) ? 100 : ($offer->sent_at || in_array($offer->status, ['Sent', 'Viewed'], true) ? 66 : 33));
        $progress = [
            'application' => min(100, ($candidate->applied_at || $candidate->created_at ? 50 : 0) + ((float) $candidate->ai_score > 0 ? 50 : 0)),
            'interview'   => $rounds->count() ? (int) round($roundsDone / $rounds->count() * 100) : ($selected ? 100 : 0),
            'onboarding'  => $onbPct,
            'offer'       => $offerPct,
            'employee'    => $employee ? 100 : 0,
        ];

        return [
            'summary' => [
                'name'            => $candidate->name,
                'reference'       => 'CAND-'.str_pad((string) $candidate->id, 4, '0', STR_PAD_LEFT),
                'applied_job'     => $job?->title,
                'department'      => $job?->department ?? $mr?->department,
                'current_stage'   => $candidate->stage,
                'current_status'  => $employee ? 'Joined' : ($offer?->accepted_at ? 'Offer Accepted' : ($offer ? 'Offer '.$offer->status : ($onb ? 'Onboarding '.($onb->verification_status ?? 'In Progress') : ($candidate->final_decision ?: 'In Progress')))),
                'recruiter'       => $recruiter,
                'ai_score'        => $candidate->ai_score,
                'interview_score' => $interviewScore,
                'interview_count' => $rounds->count(),
                'applied_at'      => optional($candidate->applied_at ?? $candidate->created_at)->toIso8601String(),
                'offer_status'    => $offer?->status ?? 'Not Generated',
                'joining_status'  => $employee ? 'Joined' : ($onb?->joining_confirmed_at ? 'Joining Confirmed' : ($offer?->status === 'Accepted' ? 'Offer Accepted' : 'Pending')),
                'employee_code'   => $employee?->employee_code,
                'employee_id'     => $employee?->id,
                'progress_pct'    => (int) round(($progress['application'] + $progress['interview'] + $progress['onboarding'] + $progress['offer'] + $progress['employee']) / 5),
            ],
            'progress' => $progress,
            'stages'   => $this->decorateStages($stages),
        ];
    }

    /**
     * SPK-1: every stage reads Completed / Current / Pending, and each pending
     * stage names its owner and the action that will move it forward.
     *
     * "Current" is the first incomplete stage AFTER the last completed one — not
     * simply the first incomplete stage. A hired candidate can legitimately have
     * an earlier gap (e.g. no AI score on record); that gap is history, and
     * flagging it as "current" would tell HR the wrong thing entirely. When the
     * final stage is done, nothing is current — the journey is finished.
     */
    private function decorateStages(array $stages): array
    {
        $lastDone = -1;
        foreach ($stages as $i => $s) {
            if ($s['status'] === 'completed') {
                $lastDone = $i;
            }
        }

        $marked = false;
        $prevAt = null;   // completion time of the previous completed stage

        foreach ($stages as $i => $s) {
            $done = $s['status'] === 'completed';

            if (! $done && ! $marked && $i > $lastDone) {
                $stages[$i]['status'] = 'current';
                $marked = true;
            }

            [$owner, $action] = $this->stageOwnerAction($s['key']);
            // Completed stages already carry the real actor in completed_by.
            $stages[$i]['owner'] = $done ? ($s['completed_by'] ?: $owner) : $owner;
            $stages[$i]['pending_action'] = $done ? null : $action;

            // Duration spent in the stage (SPK-1): time from the previous stage's
            // completion to this one's. For the stage in progress it is time
            // elapsed so far. Null when there is nothing to measure against.
            $stages[$i]['duration_days'] = null;
            if ($done && $s['at'] && $prevAt) {
                $stages[$i]['duration_days'] = (int) round(abs(Carbon::parse($prevAt)->diffInDays(Carbon::parse($s['at']))));
            } elseif ($stages[$i]['status'] === 'current' && $prevAt) {
                $stages[$i]['duration_days'] = (int) round(abs(Carbon::parse($prevAt)->diffInDays(Carbon::now())));
            }

            if ($done && $s['at']) {
                $prevAt = $s['at'];
            }
        }

        return $stages;
    }

    /** Who owns a stage, and what is outstanding while it is not complete. */
    private function stageOwnerAction(string $key): array
    {
        if (str_starts_with($key, 'interview_')) {
            return ['Interview Panel', 'Complete the round and record feedback'];
        }

        return match ($key) {
            'mr_created'       => ['Requester', 'Raise the manpower request'],
            'mr_approved'      => ['L1 / L2 Approver', 'Awaiting approval'],
            'job_posted'       => ['HR', 'Post the job from the approved request'],
            'applied'          => ['Candidate', 'Awaiting application'],
            'ai_screening'     => ['System', 'Awaiting AI evaluation'],
            'selected'         => ['HR', 'Mark the final decision'],
            'onb_invited'      => ['HR', 'Send the onboarding invitation'],
            'onb_submitted'    => ['Candidate', 'Awaiting document upload'],
            'onb_verified'     => ['HR', 'Verify the submitted documents'],
            'offer_generated'  => ['HR', 'Generate the offer letter'],
            'offer_sent'       => ['HR', 'Send the offer to the candidate'],
            'offer_accepted'   => ['Candidate', 'Awaiting offer acceptance'],
            'employee_created' => ['HR', 'Confirm joining to create the employee'],
            default            => ['HR', 'Pending'],
        };
    }
}
