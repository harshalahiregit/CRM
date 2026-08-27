<?php

namespace App\Services\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingIssue;
use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\IncidentCapa;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerInduction;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\Vendor\Vendor;
use App\Support\Shared\MeetingIssueStatus;
use App\Support\Shared\MomActionStatus;
use App\Support\Tpv\GateDecision;
use App\Support\Tpv\TpvWorkerStatus;

/**
 * A vendor's live governance status for the meeting engine (Meeting.docx §4 —
 * "select Weekly TPV Review and Sangoe automatically loads Workforce / Compliance
 * / Training / PPE / Incidents / NCR-CAPA / Gate violations / Strikes / Vendor
 * performance / New issues").
 *
 * Each section is gathered defensively: this reads across several TPV modules, so
 * a missing table or renamed column must degrade to a null section rather than
 * break the whole snapshot (and the create form that shows it). Every section
 * carries a ready-made agenda line so the organiser can one-click it in.
 */
class VendorLiveStatusService
{
    /**
     * @return array{vendor: array<string,mixed>, sections: array<int, array<string,mixed>>}
     */
    public function snapshot(int $tenantId, int $vendorId): array
    {
        $vendor = Vendor::where('tenant_id', $tenantId)->find($vendorId);
        if (! $vendor) {
            return ['vendor' => null, 'sections' => []];
        }

        $workerIds = $this->safe(fn () => TpvWorker::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)->pluck('id')->all(), []);

        $sections = array_values(array_filter([
            $this->workforce($tenantId, $vendorId, $workerIds),
            $this->training($workerIds),
            $this->ppe($workerIds),
            $this->compliance($vendor),
            $this->performance($vendor),
            $this->incidents($tenantId, $vendorId),
            $this->ncr($tenantId, $vendorId),
            $this->capa($tenantId, $vendorId),
            $this->openMeetingItems($tenantId, $vendorId),
            $this->strikes($workerIds),
            $this->gate($workerIds),
        ]));

        return [
            'vendor' => ['id' => $vendor->id, 'name' => $vendor->company_name],
            'sections' => $sections,
        ];
    }

    private function workforce(int $tenantId, int $vendorId, array $workerIds): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId, $workerIds) {
            $total = count($workerIds);
            $active = TpvWorker::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)
                ->where('status', TpvWorkerStatus::ACTIVE)->count();

            return $this->section('workforce', 'Workforce', "{$active} active / {$total} total",
                "Workforce status — {$active} active of {$total}", $total > 0);
        });
    }

    private function training(array $workerIds): ?array
    {
        if (empty($workerIds)) {
            return null;
        }

        return $this->safe(function () use ($workerIds) {
            $inducted = TpvWorkerInduction::whereIn('tpv_worker_id', $workerIds)
                ->distinct()->count('tpv_worker_id');
            $pending = max(0, count($workerIds) - $inducted);

            return $this->section('training', 'Induction / Training', "{$inducted} inducted, {$pending} pending",
                "Training status — {$pending} worker(s) pending induction", true);
        });
    }

    private function ppe(array $workerIds): ?array
    {
        if (empty($workerIds)) {
            return null;
        }

        return $this->safe(function () use ($workerIds) {
            $issued = TpvWorkerPpeIssue::whereIn('tpv_worker_id', $workerIds)
                ->distinct()->count('tpv_worker_id');
            $pending = max(0, count($workerIds) - $issued);

            return $this->section('ppe', 'PPE', "{$issued} issued, {$pending} pending",
                "PPE status — {$pending} worker(s) without PPE issued", true);
        });
    }

    private function compliance(Vendor $vendor): ?array
    {
        return $this->safe(function () use ($vendor) {
            $status = $vendor->qualification_status;
            if (! $status) {
                return null;
            }
            $label = str_replace('_', ' ', $status);

            return $this->section('compliance', 'Qualification / Compliance', $label,
                "Compliance / qualification review — currently {$label}", true);
        });
    }

    private function performance(Vendor $vendor): ?array
    {
        return $this->safe(function () use ($vendor) {
            $risk = $vendor->risk_level;
            if (! $risk) {
                return null;
            }

            return $this->section('performance', 'Risk / Performance', $risk.' risk',
                "Vendor performance & risk review — currently {$risk} risk", true);
        });
    }

    private function incidents(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $open = HsseIncident::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)
                ->where('status', '!=', 'Closed')->count();

            return $this->section('incidents', 'Incidents', "{$open} open",
                "Incident review — {$open} open incident(s)", $open > 0);
        });
    }

    /**
     * Open NCRs (Meeting.docx §4 lists NCR/CAPA together in the review template).
     * CAPA was already here; NCR was not, so a Weekly TPV Review loaded the
     * corrective actions without the non-conformities that caused them.
     */
    private function ncr(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $open = TpvNcr::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)
                ->whereNotIn('status', ['Closed', 'Verified'])->count();

            if ($open === 0) {
                return null;
            }

            return $this->section('ncr', 'NCR', "{$open} open",
                "NCR review — {$open} open non-conformity report(s)", true);
        });
    }

    /**
     * What earlier meetings left behind (Meeting.docx §4 — "Previous MOM" and
     * "Open actions" are the first two lines of the review template).
     *
     * Counted straight off the meeting registers for this vendor, so the agenda
     * that loads already knows what is still owed from last time.
     */
    private function openMeetingItems(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $meetingIds = KickoffMeeting::forTenant($tenantId)
                ->where('kickoffable_type', Vendor::class)
                ->where('kickoffable_id', $vendorId)
                ->pluck('id');

            if ($meetingIds->isEmpty()) {
                return null;
            }

            $actions = KickoffMomItem::whereIn('kickoff_meeting_id', $meetingIds)
                ->whereIn('status', MomActionStatus::OPEN_STATES)->count();
            $issues = MeetingIssue::whereIn('kickoff_meeting_id', $meetingIds)
                ->whereIn('status', MeetingIssueStatus::OPEN_STATES)->count();

            return $this->section('previous_mom', 'Previous MOM',
                "{$actions} open action(s), {$issues} open issue(s)",
                'Review of previous MOM — open actions and issues',
                $actions > 0 || $issues > 0);
        });
    }

    private function capa(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $incidentIds = HsseIncident::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->pluck('id');
            if ($incidentIds->isEmpty()) {
                return null;
            }
            $pending = IncidentCapa::whereIn('hsse_incident_id', $incidentIds)
                ->whereIn('status', ['Open', 'In_Progress'])->count();

            return $this->section('capa', 'CAPA', "{$pending} pending",
                "Pending CAPA review — {$pending} corrective/preventive action(s) open", $pending > 0);
        });
    }

    private function strikes(array $workerIds): ?array
    {
        if (empty($workerIds)) {
            return null;
        }

        return $this->safe(function () use ($workerIds) {
            $count = TpvSafetyStrike::whereIn('tpv_worker_id', $workerIds)->count();

            return $this->section('strikes', 'Safety Strikes', (string) $count,
                "Safety strikes review — {$count} strike(s) on record", $count > 0);
        });
    }

    private function gate(array $workerIds): ?array
    {
        if (empty($workerIds)) {
            return null;
        }

        return $this->safe(function () use ($workerIds) {
            $denials = TpvGateScan::whereIn('tpv_worker_id', $workerIds)
                ->where('decision', GateDecision::DENY)->count();

            return $this->section('gate', 'Gate Violations', "{$denials} denied", null, $denials > 0);
        });
    }

    /** Shape one status section. `agenda` is a suggested line (null = no default). */
    private function section(string $key, string $label, string $value, ?string $agenda, bool $flag): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'agenda' => $agenda,
            // A truthy flag = worth attention (drives highlighting on the card).
            'flag' => $flag,
        ];
    }

    /** Run a section gatherer, swallowing any error into a null/default section. */
    private function safe(callable $fn, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
