<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Hr\HrOffer;
use App\Models\Hr\HrOnboarding;
use App\Models\User;
use App\Support\Hr\ManpowerRequestStatus as Status;
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

        // ── Hiring KPIs (Phase 3) ────────────────────────────────────────────
        $totalHired        = HrCandidate::where('tenant_id', $tenantId)->where('stage', 'Hired')->count();
        $offersSent        = HrOffer::where('tenant_id', $tenantId)->whereNotNull('sent_at')->count();
        $offersAccepted    = HrOffer::where('tenant_id', $tenantId)->whereIn('status', ['Accepted', 'Completed'])->count();
        $acceptancePct     = $offersSent > 0 ? (int) round($offersAccepted / $offersSent * 100) : 0;
        $pendingInterviews = HrInterviewRound::where('tenant_id', $tenantId)->where('status', 'Scheduled')->count();

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

        // ── Recruitment workflow metrics (Manpower Request pipeline) ─────────
        $mrBase = fn () => HrManpowerRequest::where('tenant_id', $tenantId);
        $manpower = [
            'total_requests'   => $mrBase()->count(),
            'l1_pending'       => $mrBase()->where('status', Status::L1_PENDING)->count(),
            'l2_pending'       => $mrBase()->where('status', Status::L2_PENDING)->count(),
            'ready_for_hr'     => $mrBase()->where('status', Status::READY_FOR_HR)->count(),
            'converted_to_jd'  => $mrBase()->where('status', Status::CONVERTED_TO_JD)->count(),
            'posted_jobs'      => $mrBase()->where('status', Status::JOB_POSTED)->count(),
            'active_hiring'    => $mrBase()->where('status', Status::HIRING_IN_PROGRESS)->count(),
            'closed_positions' => $mrBase()->where('status', Status::CLOSED)->count(),
        ];

        // ── Recruitment onboarding → offer → joining KPIs (auto-derived) ─────
        $recruitmentKpis = $this->recruitmentKpis($tenantId, $today);

        // Pending approvals awaiting the current user's action (L1 and/or L2)
        $pendingApprovals = 0;
        if ($user->canApproveL1()) {
            $pendingApprovals += $mrBase()->where('status', Status::L1_PENDING)
                ->when($user->isHiringManager(), fn ($q) => $q->where('assigned_manager_id', $user->id))
                ->count();
        }
        if ($user->canApproveL2()) {
            $pendingApprovals += $mrBase()->where('status', Status::L2_PENDING)->count();
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
                // Phase 3 hiring KPIs
                'total_hired'        => $totalHired,
                'offers_sent'        => $offersSent,
                'offers_accepted'    => $offersAccepted,
                'acceptance_pct'     => $acceptancePct,
                'pending_interviews' => $pendingInterviews,
            ],
            'recruitment_kpis' => $recruitmentKpis,
            'manpower'         => $manpower,
            'pipeline'         => $pipeline,
            'source_breakdown' => $sourceBreakdown,
            'recent_requests'  => $recentRequests,
            'today_interviews' => $todayIV,
            'hiring_trend'     => $hiringTrend,
        ];
    }

    /**
     * Recruitment workflow KPIs, all derived from the live onboarding / offer /
     * employee state (no stored counters). Tenant-scoped throughout.
     */
    private function recruitmentKpis(int $tenantId, Carbon $today): array
    {
        // Onboarding-stage counts (waiting on candidate vs waiting on HR, and
        // how many are actually ready for approval — mandatory docs verified).
        $onboardings = HrOnboarding::where('tenant_id', $tenantId)
            ->whereIn('verification_status', ['Pending', 'Submitted'])
            ->with(['candidate:id,experience_years', 'documents:id,onboarding_id,type,status'])
            ->get();

        $baseMandatory = ['aadhaar', 'pan', 'resume', 'photo', 'address_proof', 'educational_certificate'];
        $waitingForCandidate = 0;
        $waitingForHr = 0;
        $readyForApproval = 0;

        foreach ($onboardings as $onb) {
            if ($onb->verification_status === 'Pending') {
                $waitingForCandidate++;

                continue;
            }
            $waitingForHr++;                                 // Submitted → HR must verify
            $required = $baseMandatory;
            if ((float) ($onb->candidate?->experience_years ?? 0) > 0) {
                $required[] = 'experience_document';
            }
            $verified = $onb->documents->where('status', 'Verified')->pluck('type')->all();
            if (! array_diff($required, $verified) && $onb->background_verified) {
                $readyForApproval++;
            }
        }

        // Offer-stage counts. Employee creation is decoupled: an accepted offer
        // is "waiting to join" until HR confirms joining (joining_confirmed_at).
        $offerBase = fn () => HrOffer::where('tenant_id', $tenantId);
        $offerPending  = $offerBase()->whereIn('status', ['Generated', 'Sent', 'Viewed'])->count();
        $offerAccepted = $offerBase()->where('status', 'Accepted')->whereNull('joining_confirmed_at')->count();
        $joiningThisWeek = $offerBase()->where('status', 'Accepted')->whereNull('joining_confirmed_at')
            ->whereNotNull('joining_date')
            ->whereBetween('joining_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->count();
        // Joining day has arrived/passed but the employee record isn't created yet.
        $employeeCreationPending = $offerBase()->where('status', 'Accepted')->whereNull('joining_confirmed_at')
            ->whereNotNull('joining_date')
            ->whereDate('joining_date', '<=', $today->toDateString())
            ->count();

        return [
            'waiting_for_candidate'     => $waitingForCandidate,
            'waiting_for_hr'            => $waitingForHr,
            'ready_for_approval'        => $readyForApproval,
            'offer_pending'             => $offerPending,
            'offer_accepted'            => $offerAccepted,
            'joining_this_week'         => $joiningThisWeek,
            'employee_creation_pending' => $employeeCreationPending,
        ];
    }
}
