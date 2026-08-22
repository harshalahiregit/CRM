<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseNcr;
use App\Models\User;
use App\Support\Purchase\PurchaseCapaSource as CapaSource;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Non-Conformance Reports — the Purchase-side mirror of TpvNcrService
 * (parity rule). CRUD + the ordered lifecycle Raised → Assigned → Response →
 * Corrective Action → Verification → Closed.
 *
 * Every NCR auto-raises a linked CAPA on creation (also how an inspection finding
 * gets a CAPA, since escalateToNcr() routes through create() here).
 */
class PurchaseNcrService
{
    public function __construct(private PurchaseCapaService $capas) {}

    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseNcr::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'responsible:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): PurchaseNcr
    {
        $ncr = PurchaseNcr::create([
            ...$data,
            'tenant_id' => $tenantId,
            'raised_by' => $userId,
            'status' => $data['status'] ?? 'Raised',
        ]);

        Log::channel('purchase')->info('Purchase NCR raised', [
            'ncr_id' => $ncr->id, 'tenant_id' => $tenantId, 'reference' => $ncr->reference,
        ]);

        $this->autoRaiseCapa($ncr);

        return $ncr->load('vendor:id,company_name,purchase_vendor_code', 'responsible:id,name');
    }

    /**
     * Open a corrective-action CAPA linked to this NCR. Idempotent — one auto-CAPA
     * per NCR — so re-runs and manually-added CAPAs never produce duplicates. The
     * CAPA inherits the NCR's due date, responsible person and severity-mapped
     * priority; closed only on Verified-with-evidence (Rule 12). Mirrors TPV.
     */
    private function autoRaiseCapa(PurchaseNcr $ncr): void
    {
        $exists = PurchaseCapa::forTenant($ncr->tenant_id)
            ->where('source_kind', 'ncr')->where('source_id', $ncr->id)->exists();
        if ($exists) {
            return;
        }

        $this->capas->raiseFrom('ncr', $ncr->id, [
            'title'       => 'Corrective action for '.$ncr->reference,
            'type'        => 'Corrective',
            'priority'    => CapaSource::priorityForSeverity($ncr->severity),
            'due_date'    => $ncr->due_date,
            'assigned_to' => $ncr->responsible_by,
        ], (int) $ncr->tenant_id, $ncr->raised_by, $ncr->purchase_vendor_id);
    }

    public function update(PurchaseNcr $ncr, array $data): PurchaseNcr
    {
        $ncr->update($data);

        return $ncr->load('vendor:id,company_name,purchase_vendor_code', 'responsible:id,name');
    }

    /**
     * Advance the lifecycle. Verification → Closed stamps the verifier; closing
     * requires the corrective action to have been recorded.
     */
    public function transition(PurchaseNcr $ncr, string $to, User $actor, ?string $remarks = null): PurchaseNcr
    {
        if (! in_array($to, PurchaseNcr::STATUSES, true)) {
            throw new BusinessException("Unknown NCR status: {$to}.");
        }
        if ($to === 'Closed' && empty($ncr->corrective_action)) {
            throw new BusinessException('Record the corrective action before closing the NCR.');
        }

        $from = $ncr->status;
        $patch = ['status' => $to];
        if ($to === 'Closed') {
            $patch['closed_at'] = now();
            $patch['verified_by'] = $actor->id;
            $patch['verified_at'] = now();
        }
        $ncr->update($patch);

        $ncr->recordAudit('NCR '.$to, $actor, $remarks, ['from' => $from, 'to' => $to]);

        return $ncr->load('vendor:id,company_name,purchase_vendor_code', 'responsible:id,name');
    }

    public function delete(PurchaseNcr $ncr): void
    {
        $ncr->delete();
    }
}
