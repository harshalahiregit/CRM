<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseVendorItem;
use App\Repositories\BaseRepository;
use App\Support\Purchase\PurchaseVendorItemStatus as Status;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseVendorItemRepository extends BaseRepository
{
    protected string $modelClass = PurchaseVendorItem::class;

    /**
     * Tenant-scoped mapping list. Filters: purchase_vendor_id, group_id (via the
     * joined Inventory product), inventory_product_id, status and a free-text
     * search across vendor + item. Item fields are always READ from the joined
     * Inventory product — never stored on the mapping.
     */
    public function filtered(int $tenantId, array $filters): LengthAwarePaginator
    {
        $query = PurchaseVendorItem::forTenant($tenantId)
            ->with([
                'vendor:id,company_name,purchase_vendor_code',
                'product:id,name,sku,sku_code,sku_name,group_id,base_unit,status',
                'product.group:id,name',
            ]);

        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }
        if (! empty($filters['inventory_product_id'])) {
            $query->where('inventory_product_id', $filters['inventory_product_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        // Group filter resolves through the Inventory item — the mapping itself
        // stores no group, so Inventory remains authoritative.
        if (! empty($filters['group_id'])) {
            $query->whereHas('product', fn ($q) => $q->where('group_id', $filters['group_id']));
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->whereHas('vendor', fn ($v) => $v->where('company_name', 'like', "%{$s}%")
                    ->orWhere('purchase_vendor_code', 'like', "%{$s}%"))
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")
                      ->orWhere('sku', 'like', "%{$s}%")
                      ->orWhere('sku_code', 'like', "%{$s}%"))
                  ->orWhere('remarks', 'like', "%{$s}%");
            });
        }

        // Honour the client's page size; cap it so a rogue per_page can't dump the table.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = $perPage > 0 ? min($perPage, 200) : 25;

        return $query->latest()->paginate($perPage);
    }

    public function findForTenant(int $id, int $tenantId): ?PurchaseVendorItem
    {
        return PurchaseVendorItem::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'product:id,name,sku,sku_code,group_id', 'product.group:id,name'])
            ->find($id);
    }

    /** Does this exact vendor↔item pair already exist for the tenant? */
    public function pairExists(int $tenantId, int $vendorId, int $productId, ?int $exceptId = null): bool
    {
        return PurchaseVendorItem::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->where('inventory_product_id', $productId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseVendorItem::forTenant($tenantId);

        return [
            'total'    => $base()->count(),
            'active'   => $base()->where('status', Status::ACTIVE)->count(),
            'inactive' => $base()->where('status', Status::INACTIVE)->count(),
            'vendors'  => $base()->distinct('purchase_vendor_id')->count('purchase_vendor_id'),
            'items'    => $base()->distinct('inventory_product_id')->count('inventory_product_id'),
        ];
    }
}
