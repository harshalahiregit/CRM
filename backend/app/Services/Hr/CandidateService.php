<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Notifications\WhatsApp\ApplicationReceivedNotification;
use App\Notifications\WhatsApp\StatusUpdateNotification;
use App\Repositories\Hr\CandidateRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CandidateService
{
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

        Log::channel('hr')->info('Candidate created', ['candidate_id' => $candidate->id, 'tenant_id' => $tenantId]);

        return $candidate;
    }

    public function update(HrCandidate $candidate, array $data): HrCandidate
    {
        $candidate->update($data);

        Log::channel('hr')->info('Candidate updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        return $candidate->fresh()->load(['jobPosting', 'interviewRounds', 'offer']);
    }

    public function updateStage(HrCandidate $candidate, string $stage): HrCandidate
    {
        $order = ['Applied' => 0, 'Screening' => 1, 'Assessment' => 2, 'Interview' => 3, 'Offer' => 4, 'Hired' => 5, 'Rejected' => 6];
        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$stage] ?? 0;
        if ($newIdx < $currentIdx && $stage !== 'Rejected') {
            Log::channel('hr')->warning('Candidate stage change rejected: backward move', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $candidate->stage, 'to' => $stage]);
            throw new BusinessException('Stage can only move forward in the pipeline.', 422);
        }

        $oldStage = $candidate->stage;
        $candidate->update(['stage' => $stage]);

        if ($candidate->email && $oldStage !== $stage) {
            $message = '';
            if ($stage === 'Rejected') {
                $message = 'We appreciate your interest and wish you the best in your career endeavors.';
            } elseif ($stage === 'Hired') {
                $message = 'Congratulations on successfully completing all rounds!';
            }

            Mail::to($candidate->email)->send(
                new \App\Mail\ApplicationStatusMail($candidate->load('jobPosting'), $stage, $message)
            );
        }

        if ($oldStage !== $stage) {
            StatusUpdateNotification::send($candidate, $stage);
            Log::channel('hr')->info('Candidate stage changed', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $oldStage, 'to' => $stage]);
        }

        return $candidate;
    }

    public function updateDecision(HrCandidate $candidate, string $decision): HrCandidate
    {
        $candidate->update(['final_decision' => $decision]);

        if ($decision === 'Selected') {
            $candidate->update(['stage' => 'Hired']);
        } elseif ($decision === 'Rejected') {
            $candidate->update(['stage' => 'Rejected']);
        }

        Log::channel('hr')->info('Candidate decision updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'decision' => $decision]);

        return $candidate;
    }

    public function destroy(HrCandidate $candidate): void
    {
        $candidate->delete();

        Log::channel('hr')->info('Candidate deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);
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
