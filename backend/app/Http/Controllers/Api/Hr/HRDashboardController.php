<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrCandidate;
use App\Models\HrEmployee;
use App\Models\HrInterviewRound;
use App\Models\HrJobPosting;
use App\Models\HrManpowerRequest;
use App\Models\HrOffer;
use Illuminate\Http\Request;
use Carbon\Carbon;

class HRDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // KPIs
        $openPositions   = HrJobPosting::where('status', 'Active')->sum('number_of_openings');
        $activeCandidates= HrCandidate::whereNotIn('stage', ['Hired','Rejected'])->count();
        $todayInterviews = HrInterviewRound::whereDate('scheduled_at', $today)->where('status','Scheduled')->count();
        $offersReleased  = HrOffer::whereDate('created_at', '>=', $today->copy()->startOfMonth())->count();
        $hiredThisMonth  = HrCandidate::where('stage','Hired')->whereDate('updated_at', '>=', $today->copy()->startOfMonth())->count();
        $rejected        = HrCandidate::where('stage','Rejected')->count();
        $pendingFeedback = HrInterviewRound::where('result','Pending')->where('status','Scheduled')->count();
        $sources         = HrCandidate::select('source')->distinct()->count();

        // ⭐ NEW: Time to Hire calculation (average days from Applied → Hired)
        $timeToHire = HrCandidate::where('stage', 'Hired')
            ->selectRaw('AVG(DATEDIFF(updated_at, created_at)) as avg_days')
            ->value('avg_days');
        $timeToHire = $timeToHire ? round($timeToHire, 1) : 0;

        // ⭐ NEW: Hiring trend (last 6 months)
        $hiringTrend = HrCandidate::where('stage', 'Hired')
            ->where('updated_at', '>=', $today->copy()->subMonths(6))
            ->selectRaw('DATE_FORMAT(updated_at, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Pipeline
        $stages = ['Applied','Screening','Assessment','Interview','Offer','Hired'];
        $pipeline = [];
        foreach ($stages as $s) {
            $pipeline[] = ['stage' => $s, 'count' => HrCandidate::where('stage', $s)->count()];
        }

        // Source breakdown
        $sourceBreakdown = HrCandidate::select('source')
            ->selectRaw('count(*) as count')
            ->groupBy('source')
            ->get();

        // Recent manpower requests
        $recentRequests = HrManpowerRequest::with(['requester', 'assignedManager'])
            ->latest()
            ->take(5)
            ->get();

        // Today's interviews
        $todayIV = HrInterviewRound::with('candidate')
            ->whereDate('scheduled_at', $today)
            ->where('status', 'Scheduled')
            ->get()
            ->map(fn($iv) => [
                'id'          => $iv->id,
                'candidate'   => $iv->candidate?->name,
                'role'        => $iv->candidate?->jobPosting?->title ?? '—',
                'round'       => $iv->round_name,
                'time'        => Carbon::parse($iv->scheduled_at)->format('h:i A'),
                'interviewer' => $iv->interviewer_name,
            ]);

        // ⭐ NEW: Pending approvals (for hiring managers)
        $pendingApprovals = 0;
        if (auth()->user()->isHiringManager() || auth()->user()->isAdmin()) {
            $pendingApprovals = HrManpowerRequest::where('status', 'Pending')
                ->when(auth()->user()->isHiringManager(), function ($q) {
                    $q->where('assigned_manager_id', auth()->id());
                })
                ->count();
        }

        return response()->json([
            'kpis' => [
                'open_positions'    => $openPositions,
                'active_candidates' => $activeCandidates,
                'today_interviews'  => $todayInterviews,
                'offers_released'   => $offersReleased,
                'hired_this_month'  => $hiredThisMonth,
                'rejected'          => $rejected,
                'pending_feedback'  => $pendingFeedback,
                'sources_count'     => $sources,
                'time_to_hire_days' => $timeToHire, // ⭐ NEW
                'pending_approvals' => $pendingApprovals, // ⭐ NEW
            ],
            'pipeline'          => $pipeline,
            'source_breakdown'  => $sourceBreakdown,
            'recent_requests'   => $recentRequests,
            'today_interviews'  => $todayIV,
            'hiring_trend'      => $hiringTrend, // ⭐ NEW
        ]);
    }
}
