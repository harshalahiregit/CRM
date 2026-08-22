<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\Tpv\CapaSource;
use App\Support\Tpv\ViolationType;
use App\Support\Vendor\VendorStatus;
use Illuminate\Support\Facades\Log;

/**
 * TPV vendor violations & strike escalation (Sangoe TPV §26). Records violations,
 * computes the escalation ladder from cumulative OPEN points, and applies the
 * suspension / blacklist actions through the shared VendorService.
 *
 * A Major/Critical violation also auto-raises a linked corrective-action CAPA;
 * Minor violations are strike-only.
 */
class TpvViolationService
{
    public function __construct(private VendorService $vendors, private TpvCapaService $capas) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvVendorViolation::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->get();
    }

    public function record(array $data, int $tenantId, int $userId): TpvVendorViolation
    {
        $v = TpvVendorViolation::create([
            ...$data,
            'tenant_id' => $tenantId,
            'recorded_by' => $userId,
            'status' => $data['status'] ?? 'Open',
        ]);

        Log::channel('tpv')->info('TPV vendor violation recorded', [
            'violation_id' => $v->id, 'tenant_id' => $tenantId, 'vendor_id' => $v->vendor_id, 'reference' => $v->reference,
        ]);

        $this->autoRaiseCapa($v);

        return $v->load('vendor:id,company_name,vendor_code,status');
    }

    /**
     * Open a corrective-action CAPA for a significant violation. Minor violations
     * are strike-only (points → escalation ladder); Major/Critical also warrant a
     * tracked corrective action. Idempotent — one auto-CAPA per violation.
     */
    private function autoRaiseCapa(TpvVendorViolation $v): void
    {
        if (! in_array($v->severity, ['Major', 'Critical'], true)) {
            return;
        }
        $exists = TpvCapa::forTenant($v->tenant_id)
            ->where('source_kind', 'violation')->where('source_id', $v->id)->exists();
        if ($exists) {
            return;
        }

        $this->capas->raiseFrom('violation', $v->id, [
            'title'    => 'Corrective action for violation '.$v->reference,
            'type'     => 'Corrective',
            'priority' => CapaSource::priorityForSeverity($v->severity),
        ], (int) $v->tenant_id, $v->recorded_by, $v->vendor_id);
    }

    public function update(TpvVendorViolation $violation, array $data): TpvVendorViolation
    {
        $violation->update($data);

        return $violation->load('vendor:id,company_name,vendor_code,status');
    }

    public function delete(TpvVendorViolation $violation): void
    {
        $violation->delete();
    }

    /** Cumulative escalation for one vendor from its OPEN violations. */
    public function escalationFor(int $tenantId, int $vendorId): array
    {
        $open = TpvVendorViolation::forTenant($tenantId)->where('vendor_id', $vendorId)->where('status', 'Open');
        $points = (int) (clone $open)->sum('points');
        $count = (clone $open)->count();
        $level = ViolationType::levelFor($points);

        return [
            'vendor_id' => $vendorId,
            'open_count' => $count,
            'open_points' => $points,
            'level' => $level,
            'level_label' => ViolationType::levelLabel($level),
            'recommend_suspend' => in_array($level, ['Suspension', 'Blacklist'], true),
            'recommend_blacklist' => $level === 'Blacklist',
        ];
    }

    /** Per-vendor escalation summary for every vendor carrying open violations. */
    public function escalations(int $tenantId): array
    {
        return TpvVendorViolation::forTenant($tenantId)->where('status', 'Open')
            ->selectRaw('vendor_id, COUNT(*) as c, SUM(points) as pts')
            ->groupBy('vendor_id')
            ->with('vendor:id,company_name,vendor_code,status')
            ->get()
            ->map(function ($r) {
                $level = ViolationType::levelFor((int) $r->pts);

                return [
                    'vendor_id' => $r->vendor_id,
                    'vendor' => $r->vendor?->company_name,
                    'vendor_code' => $r->vendor?->vendor_code,
                    'vendor_status' => $r->vendor?->status,
                    'open_count' => (int) $r->c,
                    'open_points' => (int) $r->pts,
                    'level' => $level,
                    'level_label' => ViolationType::levelLabel($level),
                ];
            })
            ->sortByDesc('open_points')->values()->all();
    }

    /** Apply the escalation action (suspend / blacklist) via the shared VendorService. */
    public function enforce(Vendor $vendor, string $action, User $actor, ?string $reason = null): Vendor
    {
        return match ($action) {
            'suspend' => $this->vendors->suspend($vendor, $reason ?? 'Escalated for repeated violations', $actor),
            'blacklist' => $this->vendors->updateStatus($vendor, VendorStatus::BLACKLISTED, $actor, $reason ?? 'Blacklisted for repeated violations'),
            default => throw new BusinessException("Unknown enforcement action: {$action}."),
        };
    }
}
