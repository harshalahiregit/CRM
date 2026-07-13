<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\User;
use App\Notifications\WhatsApp\ApplicationReceivedNotification;
use App\Notifications\WhatsApp\StatusUpdateNotification;
use App\Repositories\Hr\CandidateRepository;
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
}
