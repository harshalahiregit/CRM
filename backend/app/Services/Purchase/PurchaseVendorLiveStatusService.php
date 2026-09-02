<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseGateScan;
use App\Models\Purchase\PurchaseHsseIncident;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseMomIssue;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerInduction;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Support\Purchase\PurchaseMomActionStatus;
use App\Support\Purchase\PurchaseMomIssueStatus;

/**
 * The live vendor snapshot a Purchase meeting is planned against — the mirror of
 * Shared\VendorLiveStatusService on purchase_* tables.
 *
 * When someone schedules a meeting with a vendor, the useful thing to know is
 * what is actually true about that vendor right now: how many workers are
 * active, who is still uninducted, what is open from the last meeting. Purchase
 * had none of this, so a Purchase meeting was planned blind and its agenda was
 * whatever the organiser happened to remember.
 *
 * Each section is also an AGENDA SUGGESTION — `agenda` is the line the meeting
 * form offers to add — which is why a section returns null when it has nothing
 * to say rather than a zero. A section reading "0 open issues" is noise on an
 * agenda; the absence of the section is the signal.
 *
 * Output shape is identical to the shared service's so the same panel renders
 * both. Purchase has no safety-strike register, so that section is genuinely
 * absent here rather than stubbed at zero.
 */
class PurchaseVendorLiveStatusService
{
    /**
     * @return array{vendor: ?array, sections: array, has_history: bool}
     */
    public function snapshot(int $tenantId, int $vendorId): array
    {
        $vendor = PurchaseVendor::where('tenant_id', $tenantId)->find($vendorId);
        if (! $vendor) {
            return ['vendor' => null, 'sections' => [], 'has_history' => false];
        }

        $workerIds = $this->safe(fn () => PurchaseWorker::where('tenant_id', $tenantId)
            ->where('purchase_vendor_id', $vendorId)->pluck('id')->all(), []);

        $sections = array_values(array_filter([
            $this->workforce($tenantId, $vendorId, $workerIds),
            $this->training($workerIds),
            $this->ppe($workerIds),
            $this->compliance($vendor),
            $this->incidents($tenantId, $vendorId),
            $this->ncr($tenantId, $vendorId),
            $this->capa($tenantId, $vendorId),
            $this->openMeetingItems($tenantId, $vendorId),
            $this->gate($tenantId, $vendorId),
        ]));

        // Whether this vendor has any prior meeting. The live-status and
        // carry-forward panels are only meaningful for a RECURRING vendor — on a
        // vendor's first meeting there is no history to review or carry forward,
        // so the UI hides both when this is false.
        $hasHistory = $this->safe(fn () => PurchaseKickoffMeeting::where('tenant_id', $tenantId)
            ->where('purchase_vendor_id', $vendorId)->exists(), false);

        return [
            'vendor' => ['id' => $vendor->id, 'name' => $vendor->company_name],
            'sections' => $sections,
            'has_history' => (bool) $hasHistory,
        ];
    }

    private function workforce(int $tenantId, int $vendorId, array $workerIds): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId, $workerIds) {
            $total = count($workerIds);
            $active = PurchaseWorker::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->where('status', 'Active')->count();

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
            $inducted = PurchaseWorkerInduction::whereIn('purchase_worker_id', $workerIds)
                ->distinct()->count('purchase_worker_id');
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
            $issued = PurchaseWorkerPpeIssue::whereIn('purchase_worker_id', $workerIds)
                ->distinct()->count('purchase_worker_id');
            $pending = max(0, count($workerIds) - $issued);

            return $this->section('ppe', 'PPE', "{$issued} issued, {$pending} pending",
                "PPE status — {$pending} worker(s) without PPE issued", true);
        });
    }

    private function compliance(PurchaseVendor $vendor): ?array
    {
        return $this->safe(function () use ($vendor) {
            $status = $vendor->qualification_status;
            if (! $status) {
                return null;
            }
            $label = str_replace('_', ' ', $status);

            return $this->section('compliance', 'Prequalification', $label,
                "Prequalification status — {$label}",
                $status !== 'Qualified');
        });
    }

    private function incidents(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $open = PurchaseHsseIncident::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->whereNotIn('status', ['Closed', 'Cancelled'])->count();
            if ($open === 0) {
                return null;
            }

            return $this->section('incidents', 'HSSE Incidents', "{$open} open",
                "HSSE incidents — {$open} still open", true);
        });
    }

    private function ncr(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $open = PurchaseNcr::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->whereNotIn('status', ['Closed', 'Cancelled'])->count();
            if ($open === 0) {
                return null;
            }

            return $this->section('ncr', 'NCR', "{$open} open",
                "Non-conformance reports — {$open} still open", true);
        });
    }

    private function capa(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $open = PurchaseCapa::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->whereNotIn('status', ['Closed', 'Verified', 'Cancelled'])->count();
            if ($open === 0) {
                return null;
            }

            return $this->section('capa', 'CAPA', "{$open} open",
                "Corrective / preventive actions — {$open} still open", true);
        });
    }

    /**
     * What is still outstanding from this vendor's previous meetings — the
     * "review of previous MOM" item every recurring meeting opens with.
     */
    private function openMeetingItems(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $meetingIds = PurchaseKickoffMeeting::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)->pluck('id');

            if ($meetingIds->isEmpty()) {
                return null;
            }

            $actions = PurchaseMomActionItem::whereIn('purchase_kickoff_meeting_id', $meetingIds)
                ->whereIn('status', PurchaseMomActionStatus::OPEN_STATES)->count();
            $issues = PurchaseMomIssue::whereIn('purchase_kickoff_meeting_id', $meetingIds)
                ->whereIn('status', PurchaseMomIssueStatus::OPEN_STATES)->count();

            return $this->section('previous_mom', 'Previous MOM',
                "{$actions} open action(s), {$issues} open issue(s)",
                'Review of previous MOM — open actions and issues',
                $actions > 0 || $issues > 0);
        });
    }

    /** Site presence over the last week — who is actually turning up. */
    private function gate(int $tenantId, int $vendorId): ?array
    {
        return $this->safe(function () use ($tenantId, $vendorId) {
            $scans = PurchaseGateScan::where('tenant_id', $tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->where('scanned_at', '>=', now()->subWeek());

            $total = (clone $scans)->count();
            if ($total === 0) {
                return null;
            }
            $denied = (clone $scans)->where('decision', PurchaseGateScan::DENY)->count();

            return $this->section('gate', 'Site Access (7d)', "{$total} scans, {$denied} refused",
                "Gate refusals — {$denied} of {$total} scans refused in the last week",
                $denied > 0);
        });
    }

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

    /**
     * Run a section gatherer, swallowing any error into a null section.
     *
     * A snapshot is decoration on a meeting form — one register failing must not
     * stop the meeting being scheduled.
     */
    private function safe(callable $fn, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
