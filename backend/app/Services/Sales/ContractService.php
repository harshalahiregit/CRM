<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\ContractType;
use App\Models\Sales\SalesContract;
use App\Repositories\Sales\SalesContractRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractService
{
    public function __construct(private SalesContractRepository $contractRepository)
    {
    }

    public function list(int $tenantId, array $filters)
    {
        return $this->contractRepository->filtered($tenantId, $filters);
    }

    public function expiringSoon(int $tenantId, int $days = 30)
    {
        return $this->contractRepository->expiringSoon($tenantId, $days);
    }

    public function create(array $data, int $tenantId, int $userId): SalesContract
    {
        $contract = DB::transaction(function () use ($data, $tenantId, $userId) {
            $contract = SalesContract::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
            ]);
            $contract->logActivity('created', "Contract \"{$contract->subject}\" created", null, null, $userId);
            return $contract;
        });

        Log::channel('sales')->info('Contract created', ['contract_id' => $contract->id, 'tenant_id' => $tenantId]);

        return $this->loadFull($contract);
    }

    public function show(SalesContract $contract, int $tenantId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        return $this->loadFull($contract);
    }

    public function update(SalesContract $contract, array $data, int $tenantId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        $contract->update($data);
        return $this->loadFull($contract->fresh());
    }

    public function delete(SalesContract $contract, int $tenantId): void
    {
        $this->assertTenant($contract, $tenantId);
        $contract->delete();
    }

    public function updateStatus(SalesContract $contract, string $status, int $tenantId, int $userId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        $contract->update(['status' => $status]);
        $contract->logActivity('status_changed', "Contract status → {$status}", null, $status, $userId);
        return $this->loadFull($contract->fresh());
    }

    /**
     * Renew a contract: create a new version copying its terms, link back via
     * renewed_from_id, and mark the old one renewed. Mirrors old-CRM renewal.
     */
    public function renew(SalesContract $contract, array $data, int $tenantId, int $userId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);

        return DB::transaction(function () use ($contract, $data, $tenantId, $userId) {
            $new = SalesContract::create([
                'tenant_id'          => $tenantId,
                'subject'            => $contract->subject,
                'client_id'          => $contract->client_id,
                'contract_type_id'   => $contract->contract_type_id,
                'value'              => $data['value'] ?? $contract->value,
                'currency'           => $contract->currency,
                'start_date'         => $data['start_date'] ?? now()->toDateString(),
                'end_date'           => $data['end_date'] ?? null,
                'description'        => $contract->description,
                'status'             => 'active',
                'renewed_from_id'    => $contract->id,
                'renewal_notice_days'=> $contract->renewal_notice_days,
                'version'            => $contract->version + 1,
                'created_by'         => $userId,
            ]);

            $contract->update(['status' => 'renewed', 'is_renewed' => true]);
            $contract->logActivity('renewed', "Renewed as {$new->reference_no}", null, null, $userId);
            $new->logActivity('created', "Renewed from {$contract->reference_no}", null, null, $userId);

            Log::channel('sales')->info('Contract renewed', [
                'contract_id' => $contract->id, 'new_id' => $new->id, 'tenant_id' => $tenantId,
            ]);

            return $this->loadFull($new);
        });
    }

    public function sign(SalesContract $contract, array $data, int $tenantId, int $userId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        $contract->update([
            'signature_data' => $data['signature_data'] ?? null,
            'signed_by_name' => $data['signed_by_name'] ?? null,
            'signed_at'      => now(),
            'status'         => $contract->status === 'draft' ? 'active' : $contract->status,
        ]);
        $contract->logActivity('updated', 'Contract signed', null, null, $userId);
        return $this->loadFull($contract->fresh());
    }

    /* ── Contract Types ─────────────────────────────────────────── */
    public function types(int $tenantId)
    {
        return ContractType::forTenant($tenantId)->orderBy('name')->get();
    }

    public function createType(string $name, int $tenantId): ContractType
    {
        return ContractType::create(['tenant_id' => $tenantId, 'name' => $name]);
    }

    public function deleteType(ContractType $type, int $tenantId): void
    {
        if ($type->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
        $type->delete();
    }

    /* ── Helpers ────────────────────────────────────────────────── */
    private function loadFull(SalesContract $contract): SalesContract
    {
        // refresh() re-hydrates DB-default columns (reference_no, version) that
        // aren't set in the insert payload before returning.
        return $contract->refresh()->load(['client:id,company', 'type:id,name', 'creator:id,name', 'renewedFrom:id,reference_no']);
    }

    private function assertTenant(SalesContract $contract, int $tenantId): void
    {
        if ($contract->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
