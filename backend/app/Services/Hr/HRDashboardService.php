<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Hr\HrOffer;
use App\Models\User;
use Carbon\Carbon;

class HRDashboardService
{
    public function summary(User $user): array
    {
        $today    = Carbon::today();
        $tenantId = $user->tenant_id;

        $openPositions    = HrJobPosting::where('tenant_id', $tenantId)->where('status', 'Active')->sum('number_of_openings');
        $activeCandidates = HrCandidate::where('tenant_id', $tenantId)->whereNotIn('stage', ['Hired', 'Rejected'])->count();
        $todayInterviews  = HrInterviewRound::where('tenant_id', $tenantId)->whereDate('scheduled_at', $today)->where('status', 'Scheduled')->count();
        $offersReleased   = HrOffer::where('tenant_id', $tenantId)->whereDate('created_at', '>=', $today->copy()->startOfMonth())->count();
        $hiredThisMonth   = HrCandidate::where('tenant_id', $tenantId)->where('stage', 'Hired')->whereDate('updated_at', '>=', $today->copy()->startOfMonth())->count();
        $rejected         = HrCandidate::where('tenant_id', $tenantId)->where('stage', 'Rejected')->count();
        $pendingFeedback  = HrInterviewRound::where('tenant_id', $tenantId)->where('result', 'Pending')->where('status', 'Scheduled')->count();
        $sources          = HrCandidate::where('tenant_id', $tenantId)->select('source')->distinct()->count();

        // Time to Hire calculation (average days from Applied → Hired)
        // SQLite compatible: use julianday() instead of DATEDIFF()
        $timeToHire = HrCandidate::where('tenant_id', $tenantId)
            ->where('stage', 'Hired')
            ->selectRaw('AVG(julianday(updated_at) - julianday(created_at)) as avg_days')
            ->value('avg_days');
        $timeToHire = $timeToHire ? round($timeToHire, 1) : 0;

        // Hiring trend (last 6 months)
        // SQLite compatible: use strftime() instead of DATE_FORMAT()
        $hiringTrend = HrCandidate::where('tenant_id', $tenantId)
            ->where('stage', 'Hired')
            ->where('updated_at', '>=', $today->copy()->subMonths(6))
            ->selectRaw('strftime("%Y-%m", updated_at) as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Pipeline
        $stages = ['Applied', 'Screening', 'Assessment', 'Interview', 'Offer', 'Hired'];
        $pipeline = [];
        foreach ($stages as $s) {
            $pipeline[] = ['stage' => $s, 'count' => HrCandidate::where('tenant_id', $tenantId)->where('stage', $s)->count()];
        }

        // Source breakdown
        $sourceBreakdown = HrCandidate::where('tenant_id', $tenantId)
            ->select('source')
            ->selectRaw('count(*) as count')
            ->groupBy('source')
            ->get();

        // Recent manpower requests
        $recentRequests = HrManpowerRequest::where('tenant_id', $tenantId)
            ->with(['requester', 'assignedManager'])
            ->latest()
            ->take(5)
            ->get();

        // Today's interviews
        $todayIV = HrInterviewRound::where('tenant_id', $tenantId)
            ->with('candidate')
            ->whereDate('scheduled_at', $today)
            ->where('status', 'Scheduled')
            ->get()
            ->map(fn ($iv) => [
                'id'          => $iv->id,
                'candidate'   => $iv->candidate?->name,
                'role'        => $iv->candidate?->jobPosting?->title ?? '—',
                'round'       => $iv->round_name,
                'time'        => Carbon::parse($iv->scheduled_at)->format('h:i A'),
                'interviewer' => $iv->interviewer_name,
            ]);

        // Pending approvals (for hiring managers)
        $pendingApprovals = 0;
        if ($user->isHiringManager() || $user->isAdmin()) {
            $pendingApprovals = HrManpowerRequest::where('tenant_id', $tenantId)
                ->where('status', 'Pending')
                ->when($user->isHiringManager(), function ($q) use ($user) {
                    $q->where('assigned_manager_id', $user->id);
                })
                ->count();
        }

        return [
            'kpis' => [
                'open_positions'    => $openPositions,
                'active_candidates' => $activeCandidates,
                'today_interviews'  => $todayInterviews,
                'offers_released'   => $offersReleased,
                'hired_this_month'  => $hiredThisMonth,
                'rejected'          => $rejected,
                'pending_feedback'  => $pendingFeedback,
                'sources_count'     => $sources,
                'time_to_hire_days' => $timeToHire,
                'pending_approvals' => $pendingApprovals,
            ],
            'pipeline'         => $pipeline,
            'source_breakdown' => $sourceBreakdown,
            'recent_requests'  => $recentRequests,
            'today_interviews' => $todayIV,
            'hiring_trend'     => $hiringTrend,
        ];
    }
}
