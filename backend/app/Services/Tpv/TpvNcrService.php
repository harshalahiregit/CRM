<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvNcr;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * TPV Non-Conformance Reports (Sangoe TPV §24). CRUD + the ordered lifecycle
 * Raised → Assigned → Response → Corrective Action → Verification → Closed.
 */
class TpvNcrService
{
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

        return $ncr->load('vendor:id,company_name,vendor_code', 'responsible:id,name');
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
