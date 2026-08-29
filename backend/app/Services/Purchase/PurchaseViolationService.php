<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Models\User;
use App\Support\Purchase\PurchaseCapaSource as CapaSource;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Purchase\PurchaseViolationType;
use Illuminate\Support\Facades\Log;

/**
 * Purchase vendor violations & strike escalation — the Purchase-side mirror of
 * TpvViolationService (parity rule). Records violations, computes the escalation
 * ladder from cumulative OPEN points, and applies suspension (On_Hold) /
 * blacklist actions through the shared PurchaseVendorService.
 *
 * A Major/Critical violation also auto-raises a linked corrective-action CAPA;
 * Minor violations are strike-only. Every recorded violation auto-notifies the
 * vendor over Communications (mirror of TPV §31).
 */
class PurchaseViolationService
{
    public function __construct(
        private PurchaseVendorService $vendors,
        private PurchaseCapaService $capas,
        private PurchaseCommunicationService $comms,
    ) {}

    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseVendorViolation::forTenant($tenantId)
            ->with('vendor:id,company_name,purchase_vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->get();
    }

    public function record(array $data, int $tenantId, int $userId): PurchaseVendorViolation
    {
        $v = PurchaseVendorViolation::create([
            ...$data,
            'tenant_id' => $tenantId,
            'recorded_by' => $userId,
            'status' => $data['status'] ?? 'Open',
        ]);

        Log::channel('purchase')->info('Purchase vendor violation recorded', [
            'violation_id' => $v->id, 'tenant_id' => $tenantId, 'vendor_id' => $v->purchase_vendor_id, 'reference' => $v->reference,
        ]);

        $this->autoRaiseCapa($v);
        $this->comms->onViolationRecorded($v);
        $this->autoEscalate($v, $userId);   // Rule 9 — repeated violations escalate automatically.

        return $v->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    /**
     * Rule 9 — Repeated Violations Escalate (parity with TpvViolationService). After
     * a violation is recorded, if the vendor's cumulative OPEN points cross a ladder
     * threshold, apply the escalation automatically (On_Hold / Blacklist) instead of
     * waiting for a manual enforce(). Best-effort: a failure never rolls back the
     * recorded violation, and a state the vendor is already in is never re-applied.
     */
    private function autoEscalate(PurchaseVendorViolation $v, ?int $userId): void
    {
        try {
            $vendor = $v->vendor;
            if (! $vendor) {
                return;
            }
            $esc = $this->escalationFor((int) $v->tenant_id, (int) $v->purchase_vendor_id);
            $actor = $userId ? User::find($userId) : null;
            if (! $actor) {
                return;
            }
            $reason = "Auto-escalated (Rule 9): {$esc['open_count']} open violations / {$esc['open_points']} points → {$esc['level_label']} — triggered by {$v->reference}.";

            if ($esc['level'] === 'Blacklist' && $vendor->status !== PurchaseVendorStatus::BLACKLISTED) {
                $this->vendors->updateStatus($vendor, PurchaseVendorStatus::BLACKLISTED, $actor, $reason);
            } elseif ($esc['level'] === 'Suspension'
                && ! in_array($vendor->status, [PurchaseVendorStatus::ON_HOLD, PurchaseVendorStatus::BLACKLISTED], true)) {
                $this->vendors->updateStatus($vendor, PurchaseVendorStatus::ON_HOLD, $actor, $reason);
            } else {
                return;
            }

            Log::channel('purchase')->warning('Purchase vendor auto-escalated', [
                'vendor_id' => $vendor->id, 'tenant_id' => $v->tenant_id, 'level' => $esc['level'],
                'points' => $esc['open_points'], 'trigger' => $v->reference,
            ]);
        } catch (\Throwable $e) {
            Log::channel('purchase')->warning('Purchase auto-escalation failed', [
                'violation_id' => $v->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open a corrective-action CAPA for a significant violation. Minor violations
     * are strike-only (points → escalation ladder); Major/Critical also warrant a
     * tracked corrective action. Idempotent — one auto-CAPA per violation. Mirrors TPV.
     */
    private function autoRaiseCapa(PurchaseVendorViolation $v): void
    {
        if (! in_array($v->severity, ['Major', 'Critical'], true)) {
            return;
        }
        $exists = PurchaseCapa::forTenant($v->tenant_id)
            ->where('source_kind', 'violation')->where('source_id', $v->id)->exists();
        if ($exists) {
            return;
        }

        $this->capas->raiseFrom('violation', $v->id, [
            'title'    => 'Corrective action for violation '.$v->reference,
            'type'     => 'Corrective',
            'priority' => CapaSource::priorityForSeverity($v->severity),
        ], (int) $v->tenant_id, $v->recorded_by, $v->purchase_vendor_id);
    }

    public function update(PurchaseVendorViolation $violation, array $data): PurchaseVendorViolation
    {
        $violation->update($data);

        return $violation->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    public function delete(PurchaseVendorViolation $violation): void
    {
        $violation->delete();
    }

    /** Cumulative escalation for one vendor from its OPEN violations. */
    public function escalationFor(int $tenantId, int $vendorId): array
    {
        $open = PurchaseVendorViolation::forTenant($tenantId)->where('purchase_vendor_id', $vendorId)->where('status', 'Open');
        $points = (int) (clone $open)->sum('points');
        $count = (clone $open)->count();
        $level = PurchaseViolationType::levelFor($points);

        return [
            'vendor_id' => $vendorId,
            'open_count' => $count,
            'open_points' => $points,
            'level' => $level,
            'level_label' => PurchaseViolationType::levelLabel($level),
            'recommend_suspend' => in_array($level, ['Suspension', 'Blacklist'], true),
            'recommend_blacklist' => $level === 'Blacklist',
        ];
    }

    /** Per-vendor escalation summary for every vendor carrying open violations. */
    public function escalations(int $tenantId): array
    {
        return PurchaseVendorViolation::forTenant($tenantId)->where('status', 'Open')
            ->selectRaw('purchase_vendor_id, COUNT(*) as c, SUM(points) as pts')
            ->groupBy('purchase_vendor_id')
            ->with('vendor:id,company_name,purchase_vendor_code,status')
            ->get()
            ->map(function ($r) {
                $level = PurchaseViolationType::levelFor((int) $r->pts);

                return [
                    'vendor_id' => $r->purchase_vendor_id,
                    'vendor' => $r->vendor?->company_name,
                    'vendor_code' => $r->vendor?->purchase_vendor_code,
                    'vendor_status' => $r->vendor?->status,
                    'open_count' => (int) $r->c,
                    'open_points' => (int) $r->pts,
                    'level' => $level,
                    'level_label' => PurchaseViolationType::levelLabel($level),
                ];
            })
            ->sortByDesc('open_points')->values()->all();
    }

    /** Apply the escalation action (suspend → On_Hold / blacklist) via the shared PurchaseVendorService. */
    public function enforce(PurchaseVendor $vendor, string $action, User $actor, ?string $reason = null): PurchaseVendor
    {
        return match ($action) {
            'suspend' => $this->vendors->updateStatus($vendor, PurchaseVendorStatus::ON_HOLD, $actor, $reason ?? 'Placed on hold for repeated violations'),
            'blacklist' => $this->vendors->updateStatus($vendor, PurchaseVendorStatus::BLACKLISTED, $actor, $reason ?? 'Blacklisted for repeated violations'),
            default => throw new BusinessException("Unknown enforcement action: {$action}."),
        };
    }
}
