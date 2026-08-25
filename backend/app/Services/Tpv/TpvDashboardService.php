<?php

namespace App\Services\Tpv;

use App\Models\Shared\KickoffMomItem;
use App\Models\Tpv\IncidentCapa;
use App\Models\Tpv\TpvContract;
use App\Models\Tpv\TpvGateAttendance;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvRenewal;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\WorkPermit;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorScorecard;
use App\Support\Shared\MomActionStatus;
use App\Support\Tpv\GateDecision;
use App\Support\Tpv\StrikeSeverity as Severity;
use App\Support\Tpv\TpvOnboardingStatus as OnbStatus;
use App\Support\Tpv\TpvWorkerStatus;
use App\Support\Vendor\VendorStatus;
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

    public function __construct(
        private PpeInventoryService $ppe,
        private TpvComplianceService $compliance,
    ) {}

    public function getDashboard(int $tenantId): array
    {
        return [
            'kpis' => $this->kpis($tenantId),
            // §4/§37 Control Tower — executive KPIs, Action Centre, Risk breakdown.
            'control_tower' => $this->controlTower($tenantId),
            'action_centre' => $this->actionCentre($tenantId),
            'risk_breakdown' => $this->riskBreakdown($tenantId),
            'onboarding_funnel' => $this->onboardingFunnel($tenantId),
            'onboarding_status' => $this->onboardingStatus($tenantId),
            'gate_activity' => $this->gateActivity($tenantId),
            'strike_severity' => $this->strikeSeverity($tenantId),
            'at_risk' => $this->atRisk($tenantId),
            'workforce_by_vendor' => $this->workforceByVendor($tenantId),
            'recent_denials' => $this->recentDenials($tenantId),
        ];
    }

    /**
     * §4/§37 — the executive KPI layer of the Control Tower. Every number is a
     * live count over what exists today; areas not yet built (NCR, contracts)
     * are surfaced as 0/null so they light up automatically as later phases land,
     * rather than being silently omitted.
     */
    private function controlTower(int $tenantId): array
    {
        $v = fn () => Vendor::forTenant($tenantId);
        $today = now()->toDateString();
        $soon = now()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();

        $activeWorkers = TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE);
        $activeCount = (clone $activeWorkers)->count();

        // Readiness % over the active workforce (0 workers → null, not a fake 0%).
        $medicalOk = (clone $activeWorkers)
            ->whereHas('medical', fn ($q) => $q->whereDate('valid_until', '>=', $today)
                ->whereIn('fitness_status', ['Fit', 'Fit_With_Restrictions']))->count();
        $trainingOk = (clone $activeWorkers)
            ->whereHas('induction', fn ($q) => $q->where('passed', true))->count();

        $latestPeriod = VendorScorecard::where('tenant_id', $tenantId)->max('period');
        $avgPerf = $latestPeriod
            ? (int) round(VendorScorecard::where('tenant_id', $tenantId)->where('period', $latestPeriod)->avg('overall_score'))
            : null;

        return [
            'vendors' => [
                'total' => (clone $v())->count(),
                'active' => (clone $v())->where('status', VendorStatus::ACTIVE)->count(),
                'pending' => (clone $v())->whereIn('status', [VendorStatus::DRAFT, VendorStatus::PENDING_APPROVAL])->count(),
                'suspended' => (clone $v())->where('status', VendorStatus::SUSPENDED)->count(),
                'blacklisted' => (clone $v())->where('status', VendorStatus::BLACKLISTED)->count(),
                'offboarded' => (clone $v())->where('status', VendorStatus::OFFBOARDED)->count(),
                'temporary' => (clone $v())->temporary()->count(),
                'high_risk' => (clone $v())->whereIn('risk_level', ['High', 'Critical'])->count(),
                'expiring' => (clone $v())->temporary()
                    ->whereNotNull('access_expires_at')
                    ->whereBetween('access_expires_at', [$today, $soon])->count(),
                // §4 KPI — onboardings still in flight (not yet approved or rejected).
                'pending_onboarding' => TpvOnboarding::forTenant($tenantId)
                    ->whereNotIn('status', [OnbStatus::APPROVED, OnbStatus::REJECTED])->count(),
            ],
            'workforce' => [
                'total' => TpvWorker::forTenant($tenantId)->count(),
                'active' => $activeCount,
                'on_site_now' => TpvGateAttendance::forTenant($tenantId)->forDate($today)->onSite()->count(),
            ],
            'readiness' => [
                'training_pct' => $activeCount ? (int) round($trainingOk / $activeCount * 100) : null,
                'medical_pct' => $activeCount ? (int) round($medicalOk / $activeCount * 100) : null,
            ],
            // §4 executive compliance KPIs — PPE equipping and the §21 register.
            'compliance' => $this->complianceKpis($tenantId),
            'open' => [
                'actions' => KickoffMomItem::where('tenant_id', $tenantId)
                    ->whereIn('status', MomActionStatus::OPEN_STATES)->count(),
                'overdue_actions' => KickoffMomItem::where('tenant_id', $tenantId)
                    ->whereIn('status', MomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
                'capas' => IncidentCapa::where('tenant_id', $tenantId)
                    ->whereNotIn('status', ['Done', 'Verified'])->count(),
                'ncrs' => TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed')->count(),
                'active_permits' => WorkPermit::where('tenant_id', $tenantId)
                    ->whereIn('status', ['Approved', 'Active'])
                    ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))->count(),
                'total_strikes' => TpvSafetyStrike::forTenant($tenantId)->active()->count(),
                // §4 KPI — cumulative gate violations (entries the gate refused).
                'gate_violations' => TpvGateScan::forTenant($tenantId)->denied()->count(),
            ],
            'performance' => [
                'avg_score' => $avgPerf,
                'period' => $latestPeriod,
            ],
        ];
    }

    /**
     * §4 executive compliance KPIs. PPE compliance % is over workers that have a
     * PPE rule at all (unconfigured workers are excluded, not counted as 0%); the
     * overall compliance % is the mean of the §21 per-vendor register scores.
     * Both are null when there is nothing to measure, so a tile never shows a fake 0%.
     */
    private function complianceKpis(int $tenantId): array
    {
        $ppe = $this->ppe->complianceSummary($tenantId);
        $configured = (int) $ppe['workers'] - (int) $ppe['not_configured'];
        $ppePct = $configured > 0 ? (int) round(((int) $ppe['fully_equipped'] / $configured) * 100) : null;

        $roster = $this->compliance->roster($tenantId);
        $overallPct = count($roster) > 0
            ? (int) round(array_sum(array_column($roster, 'percent')) / count($roster))
            : null;

        return [
            'ppe_pct'          => $ppePct,
            'ppe_missing'      => (int) $ppe['missing_ppe'],
            'ppe_configured'   => $configured,
            'overall_pct'      => $overallPct,
            'vendors_tracked'  => count($roster),
        ];
    }

    /**
     * §4 Action Centre — one queue of everything waiting on a human, each row a
     * count + the page that clears it. Ordered most-urgent first.
     */
    private function actionCentre(int $tenantId): array
    {
        $today = now()->toDateString();
        $week = now()->addDays(7)->toDateString();
        $soon = now()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();

        $rows = [
            ['key' => 'approvals', 'label' => 'Approvals pending', 'path' => '/app/tpv/approvals',
                'count' => TpvOnboarding::forTenant($tenantId)
                    ->whereIn('status', [OnbStatus::SUBMITTED, OnbStatus::UNDER_REVIEW])->count()
                    + WorkPermit::where('tenant_id', $tenantId)->where('status', 'Requested')->count()],
            ['key' => 'documents', 'label' => 'Documents pending review', 'path' => '/app/tpv/vendors',
                'count' => VendorDocument::where('tenant_id', $tenantId)
                    ->whereNotIn('status', ['Approved', 'Rejected'])->count()],
            ['key' => 'docs_expiring', 'label' => 'Documents expiring (30d)', 'path' => '/app/tpv/vendors',
                'count' => VendorDocument::where('tenant_id', $tenantId)->where('status', 'Approved')
                    ->whereNotNull('expires_at')->whereBetween('expires_at', [$today, $soon])->count()],
            ['key' => 'workforce', 'label' => 'Workforce pending approval', 'path' => '/app/tpv/workforce',
                'count' => TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::DRAFT)->count()],
            ['key' => 'training', 'label' => 'Training pending', 'path' => '/app/tpv/workforce',
                'count' => TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE)
                    ->whereDoesntHave('induction', fn ($q) => $q->where('passed', true))->count()],
            ['key' => 'ppe_pending', 'label' => 'PPE pending issue', 'path' => '/app/tpv/ppe',
                'count' => (int) $this->ppe->complianceSummary($tenantId)['missing_ppe']],
            ['key' => 'medical', 'label' => 'Medical expiring/expired', 'path' => '/app/tpv/workforce',
                'count' => TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE)
                    ->whereDoesntHave('medical', fn ($q) => $q->whereDate('valid_until', '>=', $soon))->count()],
            ['key' => 'capa_overdue', 'label' => 'CAPA overdue', 'path' => '/app/tpv/incidents',
                'count' => IncidentCapa::where('tenant_id', $tenantId)->whereNotIn('status', ['Done', 'Verified'])
                    ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count()],
            ['key' => 'ncr_overdue', 'label' => 'NCR overdue', 'path' => '/app/tpv/ncr',
                'count' => TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed')
                    ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count()],
            ['key' => 'mom_pending', 'label' => 'MOM actions pending', 'path' => '/app/tpv/kickoff',
                'count' => KickoffMomItem::where('tenant_id', $tenantId)->whereIn('status', MomActionStatus::OPEN_STATES)->count()],
            ['key' => 'mom_actions_overdue', 'label' => 'Meeting actions overdue', 'path' => '/app/tpv/kickoff',
                'count' => KickoffMomItem::where('tenant_id', $tenantId)->whereIn('status', MomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count()],
            ['key' => 'contract_expiry', 'label' => 'Contracts expiring (30d)', 'path' => '/app/tpv/contracts',
                'count' => TpvContract::forTenant($tenantId)->whereIn('status', ['Active', 'Expiring'])
                    ->whereNotNull('end_date')->whereBetween('end_date', [$today, $soon])->count()],
            ['key' => 'renewal_assessment_due', 'label' => 'Vendor renewals to assess', 'path' => '/app/tpv/renewals',
                'count' => TpvRenewal::forTenant($tenantId)->where('status', 'Pending')->count()],
            ['key' => 'permit_expiry', 'label' => 'Permits expiring (7d)', 'path' => '/app/tpv/permits',
                'count' => WorkPermit::where('tenant_id', $tenantId)->whereIn('status', ['Approved', 'Active'])
                    ->whereNotNull('valid_to')->whereBetween('valid_to', [$today, $week])->count()],
            ['key' => 'renewal_due', 'label' => 'Temporary vendors expiring (7d)', 'path' => '/app/tpv/temporary',
                'count' => Vendor::forTenant($tenantId)->temporary()->whereNotNull('access_expires_at')
                    ->whereBetween('access_expires_at', [$today, $week])->count()],
        ];

        // Surface only what needs attention; a zeroed row is noise on a to-do list.
        return array_values(array_filter($rows, fn ($r) => $r['count'] > 0));
    }

    /**
     * §4 Risk dashboard — vendors by classification. Fixed Critical→Low order,
     * plus the unclassified bucket so the total always reconciles to Vendor count.
     */
    private function riskBreakdown(int $tenantId): array
    {
        $rows = Vendor::forTenant($tenantId)
            ->selectRaw('risk_level, COUNT(*) as count')
            ->groupBy('risk_level')->get()
            ->mapWithKeys(fn ($r) => [($r->risk_level ?: 'Unclassified') => (int) $r->count]);

        return collect(['Critical', 'High', 'Medium', 'Low', 'Unclassified'])
            ->map(fn ($lvl) => ['level' => $lvl, 'count' => (int) ($rows[$lvl] ?? 0)])
            ->all();
    }

    /** Headline numbers. */
    private function kpis(int $tenantId): array
    {
        $today = now()->toDateString();

        return [
            // Live site state.
            'on_site_now' => TpvGateAttendance::forTenant($tenantId)->forDate($today)->onSite()->count(),
            'checked_in_today' => TpvGateAttendance::forTenant($tenantId)->forDate($today)->count(),
            'active_workers' => TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE)->count(),

            // Queue waiting on a human.
            'awaiting_review' => TpvOnboarding::forTenant($tenantId)
                ->whereIn('status', [OnbStatus::SUBMITTED, OnbStatus::UNDER_REVIEW])->count(),
            'approved_vendors' => TpvOnboarding::forTenant($tenantId)->where('status', OnbStatus::APPROVED)->count(),

            // Safety.
            'active_strikes' => TpvSafetyStrike::forTenant($tenantId)->active()->count(),
            'at_risk' => $this->atRiskQuery($tenantId)->count(),
            'terminations' => TpvSafetyStrike::forTenant($tenantId)->where('triggered_termination', true)->count(),

            // Attention counters.
            'denied_today' => TpvGateScan::forTenant($tenantId)->whereDate('scanned_at', $today)->denied()->count(),
            'badges_expiring' => TpvWorker::forTenant($tenantId)
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
            'label' => OnbStatus::label($s),
            'count' => (int) ($rows[$s]->count ?? 0),
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
            $day = CarbonImmutable::now()->subDays($i);
            $scans = TpvGateScan::forTenant($tenantId)->whereDate('scanned_at', $day->toDateString());

            $out[] = [
                'day' => $day->format('D'),
                'date' => $day->toDateString(),
                'admitted' => (clone $scans)->whereIn('decision', GateDecision::PERMITS_ENTRY)->count(),
                'refused' => (clone $scans)->where('decision', GateDecision::DENY)->count(),
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
            'label' => Severity::label($s),
            'count' => (int) ($rows[$s]->count ?? 0),
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
                'id' => $w->id,
                'name' => $w->name,
                'worker_code' => $w->worker_code,
                'badge_number' => $w->badge_number,
                'vendor' => $w->vendor?->company_name,
                'active_strikes' => $w->strikes->count(),
                'limit' => Severity::LIMIT,
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
            'name' => $r->vendor?->company_name ?? 'Unknown',
            'code' => $r->vendor?->vendor_code,
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
                'id' => $s->id,
                'worker' => $s->worker?->name ?? 'Unknown badge',
                'worker_code' => $s->worker?->worker_code,
                'gate' => $s->gate,
                'reasons' => $s->reasons ?? [],
                'scanned_at' => $s->scanned_at,
            ])->all();
    }
}
