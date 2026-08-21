<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvContract;
use App\Models\Tpv\TpvWorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * TPV Contracts & Work Orders (Sangoe TPV §8). Plain CRUD over the two TPV-owned
 * commercial entities, tenant-scoped; references auto-generate on the model.
 */
class TpvContractService
{
    /* ── Contracts ──────────────────────────────────────────────────────── */

    public function listContracts(int $tenantId, array $filters = [])
    {
        return TpvContract::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code')
            ->withCount('workOrders')
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->get();
    }

    public function createContract(array $data, int $tenantId, int $userId): TpvContract
    {
        $contract = TpvContract::create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $data['status'] ?? 'Draft',
        ]);

        Log::channel('tpv')->info('TPV contract created', [
            'contract_id' => $contract->id, 'tenant_id' => $tenantId, 'reference' => $contract->reference,
        ]);

        return $contract->load('vendor:id,company_name,vendor_code');
    }

    public function updateContract(TpvContract $contract, array $data): TpvContract
    {
        $contract->update($data);

        return $contract->load('vendor:id,company_name,vendor_code');
    }

    public function contractDetail(TpvContract $contract): TpvContract
    {
        return $contract->load([
            'vendor:id,company_name,vendor_code',
            'workOrders' => fn ($q) => $q->latest('id'),
        ]);
    }

    public function deleteContract(TpvContract $contract): void
    {
        $contract->delete();
    }

    /* ── Work Orders ────────────────────────────────────────────────────── */

    public function listWorkOrders(int $tenantId, array $filters = [])
    {
        return TpvWorkOrder::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'contract:id,reference,title'])
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['contract_id'] ?? null, fn ($q, $c) => $q->where('contract_id', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->get();
    }

    public function createWorkOrder(array $data, int $tenantId, int $userId): TpvWorkOrder
    {
        $wo = TpvWorkOrder::create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $data['status'] ?? 'Draft',
        ]);

        Log::channel('tpv')->info('TPV work order created', [
            'work_order_id' => $wo->id, 'tenant_id' => $tenantId, 'reference' => $wo->reference,
        ]);

        return $wo->load(['vendor:id,company_name,vendor_code', 'contract:id,reference,title']);
    }

    public function updateWorkOrder(TpvWorkOrder $wo, array $data): TpvWorkOrder
    {
        $wo->update($data);

        return $wo->load(['vendor:id,company_name,vendor_code', 'contract:id,reference,title']);
    }

    public function deleteWorkOrder(TpvWorkOrder $wo): void
    {
        $wo->delete();
    }
}
