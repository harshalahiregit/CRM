<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvGateAttendance;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Support\Tpv\GateDecision;
use App\Support\Tpv\StrikeSeverity as Severity;
use App\Support\Tpv\TpvOnboardingStatus as OnbStatus;
use App\Support\Tpv\TpvWorkerStatus;
use Carbon\CarbonImmutable;

/**
 * Read-only aggregation across the whole TPV chain
 * (Onboarding → Workforce → Badge → Gate → Strikes) for one tenant.
 * Every query is ->forTenant() scoped; nothing here mutates.
 *
 * Mirrors PurchaseDashboardService. Termination/at-risk thresholds are read
 * from StrikeSeverity rather than restated, so the dashboard can never drift
 * from the policy the strike engine actually enforces.
 */
class TpvDashboardService
{
    /** Badges inside this many days of expiry are worth chasing. */
    private const EXPIRY_WINDOW_DAYS = 30;

    public function getDashboard(int $tenantId): array
    {
        return [
            'kpis'                => $this->kpis($tenantId),
            'onboarding_funnel'   => $this->onboardingFunnel($tenantId),
            'onboarding_status'   => $this->onboardingStatus($tenantId),
            'gate_activity'       => $this->gateActivity($tenantId),
            'strike_severity'     => $this->strikeSeverity($tenantId),
            'at_risk'             => $this->atRisk($tenantId),
            'workforce_by_vendor' => $this->workforceByVendor($tenantId),
            'recent_denials'      => $this->recentDenials($tenantId),
        ];
    }

    /** Headline numbers. */
    private function kpis(int $tenantId): array
    {
        $today = now()->toDateString();

        return [
            // Live site state.
            'on_site_now'      => TpvGateAttendance::forTenant($tenantId)->forDate($today)->onSite()->count(),
            'checked_in_today' => TpvGateAttendance::forTenant($tenantId)->forDate($today)->count(),
            'active_workers'   => TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE)->count(),

            // Queue waiting on a human.
            'awaiting_review'  => TpvOnboarding::forTenant($tenantId)
                                      ->whereIn('status', [OnbStatus::SUBMITTED, OnbStatus::UNDER_REVIEW])->count(),
            'approved_vendors' => TpvOnboarding::forTenant($tenantId)->where('status', OnbStatus::APPROVED)->count(),

            // Safety.
            'active_strikes'   => TpvSafetyStrike::forTenant($tenantId)->active()->count(),
            'at_risk'          => $this->atRiskQuery($tenantId)->count(),
            'terminations'     => TpvSafetyStrike::forTenant($tenantId)->where('triggered_termination', true)->count(),

            // Attention counters.
            'denied_today'     => TpvGateScan::forTenant($tenantId)->whereDate('scanned_at', $today)->denied()->count(),
            'badges_expiring'  => TpvWorker::forTenant($tenantId)
                                      ->where('status', TpvWorkerStatus::ACTIVE)
                                      ->whereNotNull('badge_valid_until')
                                      ->whereDate('badge_valid_until', '>=', now())
                                      ->whereDate('badge_valid_until', '<=', now()->addDays(self::EXPIRY_WINDOW_DAYS))
                                      ->count(),
        ];
    }

    /**
     * A true funnel — each stage is a strict subset of the one before it, so
     * the bars can only ever shrink. Built from the persisted timestamps
     * (submitted_at / approved_at), NOT from the current status: an approved
     * onboarding was still submitted, and counting only its present state
     * would make the funnel widen at the bottom.
     */
    private function onboardingFunnel(int $tenantId): array
    {
        $base = fn () => TpvOnboarding::forTenant($tenantId);

        return [
            ['key' => 'started',   'label' => 'Started',   'count' => $base()->count()],
            ['key' => 'submitted', 'label' => 'Submitted', 'count' => $base()->whereNotNull('submitted_at')->count()],
            ['key' => 'approved',  'label' => 'Approved',  'count' => $base()->whereNotNull('approved_at')->count()],
        ];
    }

    /** Current-state breakdown — mutually exclusive, unlike the funnel above. */
    private function onboardingStatus(int $tenantId): array
    {
        $rows = TpvOnboarding::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')->get()->keyBy('status');

        // Fixed order so the strip is stable regardless of what exists.
        return collect(OnbStatus::ALL)->map(fn ($s) => [
            'status' => $s,
            'label'  => OnbStatus::label($s),
            'count'  => (int) ($rows[$s]->count ?? 0),
        ])->filter(fn ($r) => $r['count'] > 0)->values()->all();
    }

    /**
     * Last 7 days of gate traffic — two same-scale (count) series.
     * "Admitted" follows GateDecision::PERMITS_ENTRY, so a warned worker counts
     * as admitted: a warning does not block entry, and splitting it out here
     * would understate how many people actually walked on site.
     * Computed per-day in PHP so it stays DB-agnostic (SQLite dev / MySQL prod).
     */
    private function gateActivity(int $tenantId): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = CarbonImmutable::now()->subDays($i);
            $scans = TpvGateScan::forTenant($tenantId)->whereDate('scanned_at', $day->toDateString());

            $out[] = [
                'day'      => $day->format('D'),
                'date'     => $day->toDateString(),
                'admitted' => (clone $scans)->whereIn('decision', GateDecision::PERMITS_ENTRY)->count(),
                'refused'  => (clone $scans)->where('decision', GateDecision::DENY)->count(),
            ];
        }

        return $out;
    }

    /** Active strikes by severity — fixed Minor → Major → Critical order. */
    private function strikeSeverity(int $tenantId): array
    {
        $rows = TpvSafetyStrike::forTenant($tenantId)->active()
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')->get()->keyBy('severity');

        return collect(Severity::ALL)->map(fn ($s) => [
            'severity' => $s,
            'label'    => Severity::label($s),
            'count'    => (int) ($rows[$s]->count ?? 0),
        ])->all();
    }

    /**
     * Workers one strike from termination — the watch list.
     * Threshold comes from StrikeSeverity::WARN_AT so this list always means
     * exactly what the gate's amber warning means.
     */
    private function atRiskQuery(int $tenantId)
    {
        return TpvWorker::forTenant($tenantId)
            ->where('status', TpvWorkerStatus::ACTIVE)
            ->whereHas('strikes', fn ($q) => $q->whereNull('voided_at'), '>=', Severity::WARN_AT);
    }

    private function atRisk(int $tenantId): array
    {
        return $this->atRiskQuery($tenantId)
            ->with(['vendor:id,company_name', 'strikes' => fn ($q) => $q->whereNull('voided_at')])
            ->limit(8)->get()
            ->map(fn ($w) => [
                'id'             => $w->id,
                'name'           => $w->name,
                'worker_code'    => $w->worker_code,
                'badge_number'   => $w->badge_number,
                'vendor'         => $w->vendor?->company_name,
                'active_strikes' => $w->strikes->count(),
                'limit'          => Severity::LIMIT,
            ])
            ->sortByDesc('active_strikes')->values()->all();
    }

    /** Top 5 vendors by active headcount, with how many are on site right now. */
    private function workforceByVendor(int $tenantId): array
    {
        $rows = TpvWorker::forTenant($tenantId)
            ->where('status', TpvWorkerStatus::ACTIVE)
            ->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, COUNT(*) as workers')
            ->groupBy('vendor_id')
            ->orderByDesc('workers')
            ->limit(5)
            ->with('vendor:id,company_name,vendor_code')
            ->get();

        // Who is on site right now, resolved once and counted per vendor in PHP
        // (keeps this DB-agnostic and avoids a correlated subquery per vendor).
        $onSiteWorkerIds = TpvGateAttendance::forTenant($tenantId)
            ->forDate(now()->toDateString())->onSite()->pluck('tpv_worker_id');

        $onSiteByVendor = TpvWorker::forTenant($tenantId)
            ->whereIn('id', $onSiteWorkerIds)
            ->selectRaw('vendor_id, COUNT(*) as c')
            ->groupBy('vendor_id')->pluck('c', 'vendor_id');

        return $rows->map(fn ($r) => [
            'name'    => $r->vendor?->company_name ?? 'Unknown',
            'code'    => $r->vendor?->vendor_code,
            'workers' => (int) $r->workers,
            'on_site' => (int) ($onSiteByVendor[$r->vendor_id] ?? 0),
        ])->all();
    }

    /** Most recent refusals — what the gate turned away, and why. */
    private function recentDenials(int $tenantId): array
    {
        return TpvGateScan::forTenant($tenantId)->denied()
            ->with('worker:id,name,worker_code')
            ->latest('scanned_at')->limit(6)->get()
            ->map(fn ($s) => [
                'id'          => $s->id,
                'worker'      => $s->worker?->name ?? 'Unknown badge',
                'worker_code' => $s->worker?->worker_code,
                'gate'        => $s->gate,
                'reasons'     => $s->reasons ?? [],
                'scanned_at'  => $s->scanned_at,
            ])->all();
    }
}
