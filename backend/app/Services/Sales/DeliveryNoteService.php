<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\DeliveryNote;
use Illuminate\Support\Facades\Log;

class DeliveryNoteService
{
    public function list(int $tenantId, ?string $status): \Illuminate\Support\Collection
    {
        $query = DeliveryNote::forTenant($tenantId)->with('invoice');

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId, int $userId): DeliveryNote
    {
        $dn = DeliveryNote::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
            'status'     => 'Draft',
        ]);

        Log::channel('sales')->info('Delivery note created', ['id' => $dn->id, 'tenant_id' => $tenantId]);

        return $dn;
    }

    public function show(DeliveryNote $deliveryNote, int $tenantId): DeliveryNote
    {
        $this->authorize($deliveryNote, $tenantId);

        return $deliveryNote->load('invoice');
    }

    public function update(DeliveryNote $deliveryNote, array $data, int $tenantId): DeliveryNote
    {
        $this->authorize($deliveryNote, $tenantId);

        $deliveryNote->update($data);

        Log::channel('sales')->info('Delivery note updated', ['id' => $deliveryNote->id, 'tenant_id' => $tenantId]);

        return $deliveryNote->fresh();
    }

    public function markDelivered(DeliveryNote $deliveryNote, int $tenantId): DeliveryNote
    {
        $this->authorize($deliveryNote, $tenantId);

        $deliveryNote->update(['status' => 'Delivered']);

        Log::channel('sales')->info('Delivery note marked delivered', ['id' => $deliveryNote->id, 'tenant_id' => $tenantId]);

        return $deliveryNote->fresh();
    }

    public function delete(DeliveryNote $deliveryNote, int $tenantId): void
    {
        $this->authorize($deliveryNote, $tenantId);

        $deliveryNote->delete();

        Log::channel('sales')->info('Delivery note deleted', ['id' => $deliveryNote->id, 'tenant_id' => $tenantId]);
    }

    private function authorize(DeliveryNote $dn, int $tenantId): void
    {
        if ($dn->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Delivery note tenant mismatch', ['id' => $dn->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException();
        }
    }
}
