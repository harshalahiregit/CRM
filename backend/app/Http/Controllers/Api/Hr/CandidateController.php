<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrCandidate;
use App\Models\HrJobPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CandidateController extends Controller
{
    public function index(Request $request)
    {
        $query = HrCandidate::with('jobPosting');

        if ($request->filled('stage') && $request->stage !== 'All') {
            $query->where('stage', $request->stage);
        }
        if ($request->filled('job_posting_id')) {
            $query->where('job_posting_id', $request->job_posting_id);
        }
        if ($request->filled('source') && $request->source !== 'All') {
            $query->where('source', $request->source);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:200',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string|max:20',
            'location'         => 'nullable|string',
            'current_company'  => 'nullable|string',
            'experience_years' => 'nullable|numeric',
            'source'           => 'required|string',
            'stage'            => 'in:Applied,Screening,Assessment,Interview,Offer,Hired,Rejected',
            'job_posting_id'   => 'nullable|exists:hr_job_postings,id',
            'linkedin_url'     => 'nullable|url',
            'skills'           => 'nullable|array',
            'ai_score'         => 'nullable|integer|min:0|max:100',
            'notes'            => 'nullable|string',
        ]);

        // Duplicate email check per job posting
        if (!empty($validated['email']) && !empty($validated['job_posting_id'])) {
            $exists = HrCandidate::where('email', $validated['email'])
                ->where('job_posting_id', $validated['job_posting_id'])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'A candidate with this email already applied for this job.'], 422);
            }
        }

        // Auto-compute AI score
        $aiData = $this->computeAiScore($validated);
        $validated['ai_score']     = $aiData['score'];
        $validated['ai_breakdown'] = $aiData['breakdown'];

        $candidate = HrCandidate::create($validated);

        // Increment job posting applicant count
        if ($candidate->job_posting_id) {
            HrJobPosting::where('id', $candidate->job_posting_id)->increment('applicant_count');
        }

        return response()->json($candidate, 201);
    }

    public function show(HrCandidate $candidate)
    {
        return response()->json(
            $candidate->load(['jobPosting', 'interviewRounds', 'offer', 'onboarding'])
        );
    }

    public function update(Request $request, HrCandidate $candidate)
    {
        $candidate->update($request->all());
        return response()->json($candidate->fresh()->load(['jobPosting','interviewRounds','offer']));
    }

    public function updateStage(Request $request, HrCandidate $candidate)
    {
        $request->validate(['stage' => 'required|in:Applied,Screening,Assessment,Interview,Offer,Hired,Rejected']);

        // PRD: stage can only move forward (or to Rejected)
        $order = ['Applied'=>0,'Screening'=>1,'Assessment'=>2,'Interview'=>3,'Offer'=>4,'Hired'=>5,'Rejected'=>6];
        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$request->stage] ?? 0;
        if ($newIdx < $currentIdx && $request->stage !== 'Rejected') {
            return response()->json(['message' => 'Stage can only move forward in the pipeline.'], 422);
        }

        $candidate->update(['stage' => $request->stage]);
        return response()->json($candidate);
    }

    public function updateDecision(Request $request, HrCandidate $candidate)
    {
        $request->validate(['final_decision' => 'required|in:Pending,Selected,Hold,Rejected']);
        $candidate->update(['final_decision' => $request->final_decision]);

        // Auto-move stage if selected
        if ($request->final_decision === 'Selected') {
            $candidate->update(['stage' => 'Hired']);
        } elseif ($request->final_decision === 'Rejected') {
            $candidate->update(['stage' => 'Rejected']);
        }

        return response()->json($candidate);
    }

    public function destroy(HrCandidate $candidate)
    {
        $candidate->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // ── AI Score Calculator ─────────────────────────────────────────────
    private function computeAiScore(array $data): array
    {
        $skills    = $data['skills'] ?? [];
        $expYears  = (float)($data['experience_years'] ?? 0);
        $location  = $data['location'] ?? '';
        $job       = isset($data['job_posting_id'])
            ? HrJobPosting::find($data['job_posting_id'])
            : null;

        // Skills Match (40%) — compare candidate skills to job requirements
        $skillScore = 50; // base
        if ($job && $job->requirements && !empty($skills)) {
            $reqs   = strtolower($job->requirements);
            $matched = 0;
            foreach ($skills as $skill) {
                if (str_contains($reqs, strtolower($skill))) {
                    $matched++;
                }
            }
            $skillScore = min(100, ($matched / max(count($skills), 1)) * 100);
        } elseif (!empty($skills)) {
            $skillScore = min(100, count($skills) * 12); // 12 pts per skill up to 100
        }

        // Experience Match (30%) — 1yr=30%, 2yr=50%, 4yr=80%, 6yr=100%
        $expScore = match(true) {
            $expYears >= 6  => 100,
            $expYears >= 4  => 85,
            $expYears >= 2  => 65,
            $expYears >= 1  => 45,
            default         => 25,
        };

        // Location Match (10%)
        $locationScore = 60; // default neutral
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

        // Education (10%) — scored statically at 70
        $educationScore = 70;

        // Overall Fit (10%) — averaged
        $overallFit = ($skillScore + $expScore) / 2;

        // Weighted total
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
    public function linkedinParse(Request $request)
    {
        $request->validate(['url' => 'required|url']);
        $url = trim($request->url);

        // Validate it's a LinkedIn URL
        if (!str_contains($url, 'linkedin.com/in/')) {
            return response()->json(['error' => 'Please provide a valid LinkedIn profile URL (linkedin.com/in/...)'], 422);
        }

        try {
            // Fetch public LinkedIn page Open Graph metadata
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->timeout(15)->get($url);

            if ($response->failed()) {
                return $this->linkedinFallback($url);
            }

            $html = $response->body();
            $data = $this->parseLinkedInHtml($html, $url);

            return response()->json([
                'success' => true,
                'data'    => $data,
                'source'  => 'scraped',
            ]);

        } catch (\Exception $e) {
            // If scraping fails, do best-effort from URL
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

        // Extract OG title (usually "Name - Title at Company | LinkedIn")
        if (preg_match('/<meta property="og:title" content="([^"]+)"/i', $html, $m)) {
            $ogTitle = html_entity_decode($m[1]);
            // Format: "Arjun Sharma - Senior Developer at TechCorp | LinkedIn"
            $parts = explode(' | ', $ogTitle);
            $nameTitle = $parts[0] ?? '';
            if (str_contains($nameTitle, ' - ')) {
                [$name, $rest] = explode(' - ', $nameTitle, 2);
                $data['name'] = trim($name);
                // "Senior Developer at TechCorp"
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

        // Extract OG description (usually contains location)
        if (preg_match('/<meta property="og:description" content="([^"]+)"/i', $html, $m)) {
            $desc = html_entity_decode($m[1]);
            $data['linkedin_summary'] = $desc;
            // Try to extract location
            if (preg_match('/(?:Location|·)\s*([^·]+)/i', $desc, $lm)) {
                $data['location'] = trim($lm[1]);
            }
        }

        // Fallback: extract from URL slug
        if (empty($data['name'])) {
            preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $url, $slug);
            if (!empty($slug[1])) {
                $data['name'] = ucwords(str_replace(['-', '_'], ' ', $slug[1]));
            }
        }

        return $data;
    }

    private function linkedinFallback(string $url)
    {
        // Extract name from URL slug as last resort
        preg_match('/linkedin\.com\/in\/([^\/\?#]+)/i', $url, $m);
        $slug = $m[1] ?? '';
        $name = ucwords(str_replace(['-', '_'], ' ', $slug));

        return response()->json([
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
        ]);
    }
}
