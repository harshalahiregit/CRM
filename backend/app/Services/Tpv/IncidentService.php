<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\IncidentCapa;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HSSE incident lifecycle (Doc_4 Phase 5): report → investigate (RCA) → act
 * (CAPA) → close. Two governance rules are enforced here, not left to the UI:
 *
 *  - A Serious/Fatal incident, or one carrying a stop-work order, auto-SUSPENDS
 *    the responsible vendor (tie-in with the Wave 4 compliance suspension), so a
 *    grave event withholds site access the moment it is logged.
 *  - An incident cannot be CLOSED until its root cause is recorded AND every CAPA
 *    is verified — no skipping the investigation.
 */
class IncidentService
{
    public function __construct(private VendorService $vendors)
    {
    }

    public function create(int $tenantId, array $data, ?User $actor): HsseIncident
    {
        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $incident = HsseIncident::create([
                'tenant_id'        => $tenantId,
                'reference'        => $this->nextReference($tenantId),
                'vendor_id'        => $data['vendor_id'] ?? null,
                'tpv_worker_id'    => $data['tpv_worker_id'] ?? null,
                'reported_by'      => $actor?->id,
                'title'            => $data['title'],
                'type'             => $data['type'],
                'severity'         => $data['severity'],
                'status'           => 'Reported',
                'occurred_at'      => $data['occurred_at'] ?? now(),
                'location'         => $data['location'] ?? null,
                'description'      => $data['description'] ?? null,
                'immediate_action' => $data['immediate_action'] ?? null,
                'stop_work'        => (bool) ($data['stop_work'] ?? false),
            ]);

            // Grave event → withhold the vendor's site access immediately.
            if ($incident->isSuspending() && $incident->vendor_id) {
                $vendor = Vendor::find($incident->vendor_id);
                if ($vendor && $vendor->status === \App\Support\Vendor\VendorStatus::ACTIVE) {
                    $reason = "Suspended after {$incident->severity} incident {$incident->reference}"
                        .($incident->stop_work ? ' (stop-work order)' : '').'.';
                    // auto=false: an incident suspension is a deliberate safety hold,
                    // not lifted by the nightly document-compliance sweep.
                    $this->vendors->suspend($vendor, $reason, $actor, false);
                    $incident->update(['triggered_suspension' => true]);
                }
            }

            $incident->recordAudit('Incident Reported', $actor, null, [
                'reference' => $incident->reference, 'severity' => $incident->severity,
            ]);

            Log::channel('tpv')->info('HSSE incident reported', [
                'incident_id' => $incident->id, 'reference' => $incident->reference,
                'severity' => $incident->severity, 'suspended_vendor' => $incident->triggered_suspension,
            ]);

            return $incident->fresh(['capas']);
        });
    }

    /** Record the root-cause analysis and move to Investigating. */
    public function recordRca(HsseIncident $incident, array $data, ?User $actor): HsseIncident
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

    public function addCapa(HsseIncident $incident, array $data, ?User $actor): IncidentCapa
    {
        $capa = $incident->capas()->create([
            'tenant_id'   => $incident->tenant_id,
            'type'        => $data['type'] ?? 'Corrective',
            'description' => $data['description'],
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_date'    => $data['due_date'] ?? null,
            'status'      => 'Open',
        ]);

        if ($incident->status === 'Reported') {
            $incident->update(['status' => 'Investigating']);
        }

        return $capa;
    }

    /** Update a CAPA, stamping completion/verification as the status advances. */
    public function updateCapa(IncidentCapa $capa, array $data, ?User $actor): IncidentCapa
    {
        $update = [];
        foreach (['type', 'description', 'assigned_to', 'due_date', 'notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }

        if (array_key_exists('status', $data) && $data['status'] !== $capa->status) {
            $status = $data['status'];
            if (! in_array($status, IncidentCapa::STATUSES, true)) {
                throw new BusinessException("Unknown CAPA status: {$status}");
            }
            $update['status'] = $status;
            $update['completed_at'] = in_array($status, ['Done', 'Verified'], true) ? ($capa->completed_at ?? now()) : null;
            if ($status === 'Verified') {
                $update['verified_at'] = now();
                $update['verified_by'] = $actor?->id;
            } else {
                $update['verified_at'] = null;
                $update['verified_by'] = null;
            }
        }

        $capa->update($update);

        return $capa->fresh();
    }

    /**
     * Close an incident — only once the root cause is recorded and every CAPA is
     * verified. This is the governance gate: no closing an un-investigated event.
     */
    public function close(HsseIncident $incident, ?User $actor): HsseIncident
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

    /** INC-YYYY-NNN, sequential per tenant per year. */
    private function nextReference(int $tenantId): string
    {
        $year = now()->format('Y');
        $n = HsseIncident::where('tenant_id', $tenantId)
            ->where('reference', 'like', "INC-{$year}-%")
            ->count() + 1;

        return sprintf('INC-%s-%03d', $year, $n);
    }
}
