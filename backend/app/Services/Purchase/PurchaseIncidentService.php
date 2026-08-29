<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseHsseIncident;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Support\Purchase\PurchaseCapaSource as CapaSource;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Purchase HSSE incident lifecycle — the Purchase-side mirror of TPV's
 * IncidentService (parity rule): report → investigate (RCA) → act (CAPA) →
 * close. Two governance rules are enforced here, not left to the UI:
 *
 *  - A Serious/Fatal incident, or one carrying a stop-work order, auto-suspends
 *    (On_Hold) the responsible purchase vendor — a grave event withholds site
 *    access the moment it is logged.
 *  - An incident cannot be CLOSED until its root cause is recorded AND every
 *    linked CAPA is Verified — no skipping the investigation.
 *
 * Unlike the TPV mirror (dedicated IncidentCapa model), corrective actions live
 * in the unified purchase_capas register, linked polymorphically via
 * PurchaseCapaService::raiseFrom('incident', …).
 */
class PurchaseIncidentService
{
    public function __construct(
        private PurchaseCapaService $capas,
        private PurchaseVendorService $vendors,
    ) {
    }

    public function list(int $tenantId, array $filters = []): Collection
    {
        return PurchaseHsseIncident::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'capas'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->latest('occurred_at')
            ->get();
    }

    public function find(int $id, int $tenantId): PurchaseHsseIncident
    {
        $incident = PurchaseHsseIncident::forTenant($tenantId)->find($id);
        if (! $incident) {
            throw new BusinessException('Incident not found.', 404);
        }

        return $incident;
    }

    public function create(int $tenantId, array $data, ?User $actor): PurchaseHsseIncident
    {
        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $incident = PurchaseHsseIncident::create([
                'tenant_id'          => $tenantId,
                'purchase_vendor_id' => $data['purchase_vendor_id'] ?? null,
                'reported_by'        => $actor?->id,
                'title'              => $data['title'],
                'type'               => $data['type'],
                'severity'           => $data['severity'],
                'status'             => 'Reported',
                'occurred_at'        => $data['occurred_at'] ?? now(),
                'location'           => $data['location'] ?? null,
                'description'        => $data['description'] ?? null,
                'immediate_action'   => $data['immediate_action'] ?? null,
                'stop_work'          => (bool) ($data['stop_work'] ?? false),
            ]);

            // Grave event → withhold the vendor's site access immediately (On_Hold).
            if ($incident->isSuspending() && $incident->purchase_vendor_id && $actor) {
                $vendor = PurchaseVendor::find($incident->purchase_vendor_id);
                if ($vendor && $vendor->status === Status::ACTIVE) {
                    $reason = "Suspended after {$incident->severity} incident {$incident->reference}"
                        .($incident->stop_work ? ' (stop-work order)' : '').'.';
                    $this->vendors->updateStatus($vendor, Status::ON_HOLD, $actor, $reason);
                    $incident->update(['triggered_suspension' => true]);
                }
            }

            $incident->recordAudit('Incident Reported', $actor, null, [
                'reference' => $incident->reference, 'severity' => $incident->severity,
            ]);

            Log::channel('purchase')->info('Purchase HSSE incident reported', [
                'incident_id' => $incident->id, 'reference' => $incident->reference,
                'severity' => $incident->severity, 'suspended_vendor' => $incident->triggered_suspension,
            ]);

            return $incident->fresh(['capas']);
        });
    }

    /** Record the root-cause analysis and move to Investigating. */
    public function recordRca(PurchaseHsseIncident $incident, array $data, ?User $actor): PurchaseHsseIncident
    {
        $incident->update([
            'rca_method'           => $data['rca_method'] ?? $incident->rca_method,
            'root_cause'           => $data['root_cause'] ?? $incident->root_cause,
            'contributing_factors' => $data['contributing_factors'] ?? $incident->contributing_factors,
            'rca_completed_at'     => now(),
            'status'               => $incident->status === 'Closed' ? 'Closed' : 'Investigating',
        ]);

        $incident->recordAudit('Incident RCA Recorded', $actor, null, ['method' => $incident->rca_method]);

        return $incident->fresh(['capas']);
    }

    /** Raise a linked corrective/preventive action into the unified CAPA register. */
    public function addCapa(PurchaseHsseIncident $incident, array $data, ?User $actor): PurchaseCapa
    {
        $capa = $this->capas->raiseFrom('incident', $incident->id, [
            'title'       => Str::limit((string) $data['description'], 180, ''),
            'action'      => $data['description'],
            'type'        => $data['type'] ?? 'Corrective',
            'priority'    => CapaSource::priorityForSeverity($incident->severity),
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_date'    => $data['due_date'] ?? null,
        ], $incident->tenant_id, $actor?->id, $incident->purchase_vendor_id);

        if ($incident->status === 'Reported') {
            $incident->update(['status' => 'Investigating']);
        }

        return $capa;
    }

    /**
     * Update a linked CAPA. Non-status fields go straight through; a status change
     * runs the CAPA lifecycle transition, so verification stays gated on evidence
     * (Rule 12) exactly as the standalone register enforces it.
     */
    public function updateCapa(PurchaseCapa $capa, array $data, User $actor): PurchaseCapa
    {
        $patch = [];
        foreach (['type', 'assigned_to', 'due_date', 'evidence_path', 'notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = $data[$f];
            }
        }
        if (array_key_exists('description', $data)) {
            $patch['action'] = $data['description'];
        }
        if ($patch !== []) {
            $this->capas->update($capa, $patch);
        }

        if (array_key_exists('status', $data) && $data['status'] !== $capa->status) {
            $this->capas->transition($capa->fresh(), $data['status'], $actor);
        }

        return $capa->fresh(['vendor:id,company_name,purchase_vendor_code', 'assignee:id,name']);
    }

    /**
     * Close an incident — only once the root cause is recorded and every linked
     * CAPA is Verified. The governance gate: no closing an un-investigated event.
     */
    public function close(PurchaseHsseIncident $incident, ?User $actor): PurchaseHsseIncident
    {
        if (! $incident->rca_done) {
            throw new BusinessException('Record the root-cause analysis before closing this incident.', 422);
        }
        $open = $incident->capas()->where('status', '!=', 'Verified')->count();
        if ($open > 0) {
            throw new BusinessException("Cannot close — {$open} corrective action(s) are not yet verified.", 422);
        }

        $incident->update(['status' => 'Closed', 'closed_at' => now(), 'closed_by' => $actor?->id]);
        $incident->recordAudit('Incident Closed', $actor, null, ['reference' => $incident->reference]);

        return $incident->fresh(['capas']);
    }
}
