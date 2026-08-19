<?php

namespace App\Services\Tpv;

use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\SafetyObservation;
use App\Models\Tpv\ToolboxTalk;
use App\Models\Tpv\WorkPermit;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Vendor\VendorStatus;
use Illuminate\Support\Carbon;

/**
 * Periodic HSSE compliance reports (Doc 6): DPR (daily), WPR (weekly), MCR
 * (monthly). One engine parameterised by a date window — each report is a
 * point-in-time roll-up of the activity in the period plus the standing
 * compliance posture. Rendered on screen and printable to PDF.
 */
class ComplianceReportService
{
    /** Resolve the [from, to] window + a label for a report kind. */
    public function window(string $kind, ?string $anchor = null): array
    {
        $now = $anchor ? Carbon::parse($anchor) : now();

        return match (strtoupper($kind)) {
            'WPR'   => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'Weekly Progress Report', $now->format('\W\e\e\k W, o')],
            'MCR'   => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'Monthly Compliance Report', $now->format('F Y')],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Daily Progress Report', $now->format('d M Y')],
        };
    }

    public function build(int $tenantId, string $kind, ?string $anchor = null): array
    {
        [$from, $to, $title, $periodLabel] = $this->window($kind, $anchor);

        $inPeriod = fn ($q, $col) => $q->where('tenant_id', $tenantId)->whereBetween($col, [$from, $to]);

        // Activity in the window.
        $incidents = HsseIncident::query()->tap(fn ($q) => $inPeriod($q, 'occurred_at'))
            ->with('vendor:id,company_name')->orderByDesc('occurred_at')
            ->get(['id', 'reference', 'title', 'severity', 'status', 'vendor_id', 'occurred_at']);

        $permits = WorkPermit::query()->tap(fn ($q) => $inPeriod($q, 'created_at'))
            ->get(['id', 'reference', 'type', 'status', 'created_at']);

        $observations = SafetyObservation::query()->tap(fn ($q) => $inPeriod($q, 'observed_at'))
            ->get(['id', 'category', 'severity', 'status', 'observed_at']);

        $talks = ToolboxTalk::query()->tap(fn ($q) => $inPeriod($q, 'held_at'))
            ->get(['id', 'topic', 'attendee_count', 'held_at']);

        // Standing posture (as-of now).
        $suspended = Vendor::forTenant($tenantId)->where('status', VendorStatus::SUSPENDED)
            ->get(['id', 'company_name', 'suspension_reason', 'suspended_at']);

        $docsExpiring = VendorDocument::where('tenant_id', $tenantId)
            ->where('status', 'Approved')->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        return [
            'kind'         => strtoupper($kind),
            'title'        => $title,
            'period_label' => $periodLabel,
            'from'         => $from->toDateTimeString(),
            'to'           => $to->toDateTimeString(),
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'incidents'    => $incidents->count(),
                'open_incidents' => $incidents->where('status', '!=', 'Closed')->count(),
                'permits'      => $permits->count(),
                'observations' => $observations->count(),
                'toolbox_talks'=> $talks->count(),
                'talk_attendance' => $talks->sum('attendee_count'),
                'suspended_vendors' => $suspended->count(),
                'docs_expiring_30d' => $docsExpiring,
            ],
            'incidents'    => $incidents,
            'permits'      => $permits,
            'observations' => $observations,
            'toolbox_talks'=> $talks,
            'suspended'    => $suspended,
        ];
    }
}
