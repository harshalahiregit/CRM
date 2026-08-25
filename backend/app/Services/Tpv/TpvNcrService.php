<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\User;
use App\Support\Tpv\CapaSource;
use Illuminate\Support\Facades\Log;

/**
 * TPV Non-Conformance Reports (Sangoe TPV §24). CRUD + the ordered lifecycle
 * Raised → Assigned → Response → Corrective Action → Verification → Closed.
 *
 * Every NCR auto-raises a linked CAPA on creation (the corrective-action tracker
 * for the nonconformity), so an NCR is never an untracked promise — this is also
 * how an inspection finding gets a CAPA, since escalateToNcr() routes through
 * create() here — and auto-notifies the vendor over Communications (§31).
 */
class TpvNcrService
{
    public function __construct(
        private TpvCapaService $capas,
        private TpvCommunicationService $comms,
    ) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvNcr::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'responsible:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): TpvNcr
    {
        $ncr = TpvNcr::create([
            ...$data,
            'tenant_id' => $tenantId,
            'raised_by' => $userId,
            'status' => $data['status'] ?? 'Raised',
        ]);

        Log::channel('tpv')->info('TPV NCR raised', [
            'ncr_id' => $ncr->id, 'tenant_id' => $tenantId, 'reference' => $ncr->reference,
        ]);

        $this->autoRaiseCapa($ncr);
        $this->comms->onNcrRaised($ncr);

        return $ncr->load('vendor:id,company_name,vendor_code', 'responsible:id,name');
    }

    /**
     * Open a corrective-action CAPA linked to this NCR. Idempotent — one auto-CAPA
     * per NCR — so re-runs and manually-added CAPAs never produce duplicates. The
     * CAPA inherits the NCR's due date, responsible person and severity-mapped
     * priority; it stays Open and is closed only on Verified-with-evidence (Rule 12).
     */
    private function autoRaiseCapa(TpvNcr $ncr): void
    {
        $exists = TpvCapa::forTenant($ncr->tenant_id)
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
        ], (int) $ncr->tenant_id, $ncr->raised_by, $ncr->vendor_id);
    }

    public function update(TpvNcr $ncr, array $data): TpvNcr
    {
        $ncr->update($data);

        return $ncr->load('vendor:id,company_name,vendor_code', 'responsible:id,name');
    }

    /**
     * Advance the lifecycle. Verification → Closed stamps the verifier; closing
     * requires the corrective action to have been recorded.
     */
    public function transition(TpvNcr $ncr, string $to, User $actor, ?string $remarks = null): TpvNcr
    {
        if (! in_array($to, TpvNcr::STATUSES, true)) {
            throw new BusinessException("Unknown NCR status: {$to}.");
        }
        // Rule 11 — Every Action Has an Owner. Beyond the initial "Raised" state an
        // NCR must name a responsible person; it cannot be assigned/actioned/closed
        // while ownerless.
        if ($to !== 'Raised' && empty($ncr->responsible_by)) {
            throw new BusinessException('Assign a responsible person before progressing this NCR (Rule 11 — every action has an owner).');
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

        return $ncr->load('vendor:id,company_name,vendor_code', 'responsible:id,name');
    }

    public function delete(TpvNcr $ncr): void
    {
        $ncr->delete();
    }
}
