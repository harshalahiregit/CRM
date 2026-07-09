<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\RetainerInvoice;
use Illuminate\Support\Facades\Log;

class RetainerInvoiceService
{
    public function list(int $tenantId, array $filters)
    {
        $query = RetainerInvoice::where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId, int $userId): RetainerInvoice
    {
        $retainer = RetainerInvoice::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
            'status'     => 'Draft',
        ]);

        Log::channel('sales')->info('Retainer invoice created', ['retainer_id' => $retainer->id, 'tenant_id' => $tenantId]);

        return $retainer;
    }

    public function show(RetainerInvoice $retainer, int $tenantId): RetainerInvoice
    {
        $this->assertTenant($retainer, $tenantId);

        return $retainer;
    }

    public function update(RetainerInvoice $retainer, array $data, int $tenantId): RetainerInvoice
    {
        $this->assertTenant($retainer, $tenantId);
        $retainer->update($data);

        Log::channel('sales')->info('Retainer invoice updated', ['retainer_id' => $retainer->id, 'tenant_id' => $tenantId]);

        return $retainer->fresh();
    }

    public function delete(RetainerInvoice $retainer, int $tenantId): void
    {
        $this->assertTenant($retainer, $tenantId);
        $retainer->delete();
        Log::channel('sales')->info('Retainer invoice deleted', ['retainer_id' => $retainer->id, 'tenant_id' => $tenantId]);
    }

    private function assertTenant(RetainerInvoice $retainer, int $tenantId): void
    {
        if ($retainer->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
