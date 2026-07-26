<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVendor;
use App\Models\Inventory\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Vendor master + the product↔vendor links used by procurement/reordering.
 */
class VendorService
{
    /* ── Vendor master ──────────────────────────────────────────── */

    public function list(int $tenantId, array $f = [])
    {
        $q = Vendor::forTenant($tenantId)->withCount('productLinks');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('code', 'like', $s)
                ->orWhere('email', 'like', $s)->orWhere('gstin', 'like', $s));
        }

        return $q->orderBy('name')->get();
    }

    public function create(array $d, int $tenantId, int $userId): Vendor
    {
        $d['name'] = trim($d['name'] ?? '');
        if ($d['name'] === '') {
            throw new BusinessException('A vendor needs a name.', 422);
        }

        return Vendor::create(array_merge($d, ['tenant_id' => $tenantId, 'created_by' => $userId]));
    }

    public function update(int $id, array $d, int $tenantId): Vendor
    {
        $vendor = Vendor::forTenant($tenantId)->findOrFail($id);
        unset($d['tenant_id'], $d['created_by']);
        $vendor->update($d);

        return $vendor->fresh();
    }

    public function delete(int $id, int $tenantId): void
    {
        Vendor::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /* ── Product ↔ vendor links ─────────────────────────────────── */

    public function forProduct(int $productId, int $tenantId)
    {
        return ProductVendor::forTenant($tenantId)->where('product_id', $productId)
            ->with('vendor:id,name,code')
            ->orderByDesc('is_preferred')->orderBy('id')->get();
    }

    /**
     * Replace a product's vendor links. Exactly one may be preferred — the first
     * flagged wins, the rest are cleared, so "preferred vendor" is unambiguous.
     */
    public function setForProduct(int $productId, array $rows, int $tenantId)
    {
        if (! Product::forTenant($tenantId)->whereKey($productId)->exists()) {
            throw new BusinessException('That item does not exist.', 404);
        }

        DB::transaction(function () use ($productId, $rows, $tenantId) {
            ProductVendor::forTenant($tenantId)->where('product_id', $productId)->delete();

            $preferredSeen = false;
            foreach ($rows as $r) {
                $vendorId = (int) ($r['vendor_id'] ?? 0);
                if ($vendorId <= 0 || ! Vendor::forTenant($tenantId)->whereKey($vendorId)->exists()) {
                    continue;
                }
                $preferred = ! $preferredSeen && ! empty($r['is_preferred']);
                if ($preferred) {
                    $preferredSeen = true;
                }
                ProductVendor::create([
                    'tenant_id'      => $tenantId,
                    'product_id'     => $productId,
                    'vendor_id'      => $vendorId,
                    'vendor_sku'     => $r['vendor_sku'] ?? null,
                    'price'          => $r['price'] ?? null,
                    'moq'            => $r['moq'] ?? null,
                    'lead_time_days' => $r['lead_time_days'] ?? null,
                    'is_preferred'   => $preferred,
                ]);
            }
        });

        return $this->forProduct($productId, $tenantId);
    }

    /** The preferred vendor link for a product (or the first, or null). */
    public function preferredFor(int $productId, int $tenantId): ?ProductVendor
    {
        return ProductVendor::forTenant($tenantId)->where('product_id', $productId)
            ->with('vendor')
            ->orderByDesc('is_preferred')->orderBy('id')->first();
    }
}
