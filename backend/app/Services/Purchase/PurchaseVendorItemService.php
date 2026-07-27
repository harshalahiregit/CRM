<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendorItem;
use App\Models\User;
use App\Repositories\Purchase\PurchaseVendorItemRepository;
use App\Support\Purchase\PurchaseVendorItemStatus as Status;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase Vendor ↔ Inventory Item mapping engine.
 *
 * It manages the LINK only — it never creates, updates or deletes an Inventory
 * product, and never copies item data into the Purchase schema. Inventory stays
 * the single Item Master; Purchase owns which of its vendors supply which items.
 */
class PurchaseVendorItemService
{
    public function __construct(private PurchaseVendorItemRepository $repo)
    {
    }

    public function list(int $tenantId, array $filters): LengthAwarePaginator
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    public function find(int $id, int $tenantId): PurchaseVendorItem
    {
        $mapping = $this->repo->findForTenant($id, $tenantId);
        if (! $mapping) {
            throw new BusinessException('Vendor item mapping not found.', 404);
        }

        return $mapping;
    }

    public function create(array $data, User $actor): PurchaseVendorItem
    {
        $tenantId = $actor->tenant_id;

        if ($this->repo->pairExists($tenantId, (int) $data['purchase_vendor_id'], (int) $data['inventory_product_id'])) {
            throw new BusinessException('This item is already mapped to that vendor.');
        }

        $mapping = PurchaseVendorItem::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'status'     => $data['status'] ?? Status::ACTIVE,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $mapping->recordAudit('Vendor Item Mapped', $actor, null, [
            'purchase_vendor_id'   => $mapping->purchase_vendor_id,
            'inventory_product_id' => $mapping->inventory_product_id,
        ]);

        Log::channel('purchase')->info('Purchase vendor item mapped', [
            'mapping_id' => $mapping->id, 'tenant_id' => $tenantId,
        ]);

        return $this->find($mapping->id, $tenantId);
    }

    public function update(PurchaseVendorItem $mapping, array $data, User $actor): PurchaseVendorItem
    {
        $vendorId  = (int) ($data['purchase_vendor_id'] ?? $mapping->purchase_vendor_id);
        $productId = (int) ($data['inventory_product_id'] ?? $mapping->inventory_product_id);

        if ($this->repo->pairExists($mapping->tenant_id, $vendorId, $productId, $mapping->id)) {
            throw new BusinessException('This item is already mapped to that vendor.');
        }

        $mapping->update([...$data, 'updated_by' => $actor->id]);
        $mapping->recordAudit('Vendor Item Mapping Updated', $actor, null, [
            'purchase_vendor_id' => $vendorId, 'inventory_product_id' => $productId,
        ]);

        return $this->find($mapping->id, $mapping->tenant_id);
    }

    /**
     * Remove the mapping ONLY. The Inventory product and every other vendor's
     * mapping for that same product are deliberately untouched.
     */
    public function delete(PurchaseVendorItem $mapping, User $actor): void
    {
        $mapping->recordAudit('Vendor Item Unmapped', $actor, null, [
            'purchase_vendor_id'   => $mapping->purchase_vendor_id,
            'inventory_product_id' => $mapping->inventory_product_id,
        ]);

        $mapping->update(['updated_by' => $actor->id]);
        $mapping->delete();

        Log::channel('purchase')->info('Purchase vendor item unmapped', [
            'mapping_id' => $mapping->id, 'tenant_id' => $mapping->tenant_id,
        ]);
    }
}
