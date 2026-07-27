<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Purchase Vendor category master (Settings → Vendor category).
 * Purchase-owned; feeds the Vendor Category dropdown on the Purchase Vendor form.
 */
class PurchaseVendorCategoryService
{
    public function list(int $tenantId): Collection
    {
        return PurchaseVendorCategory::forTenant($tenantId)->orderBy('name')->get()
            ->each(fn ($c) => $c->setAttribute('vendor_count', $c->vendorCount()));
    }

    public function create(array $data, User $actor): PurchaseVendorCategory
    {
        $category = PurchaseVendorCategory::create([
            ...$data,
            'tenant_id'  => $actor->tenant_id,
            'created_by' => $actor->id,
        ]);
        $category->recordAudit('Vendor Category Created', $actor, null, ['name' => $category->name]);

        return $category;
    }

    public function update(PurchaseVendorCategory $category, array $data, User $actor): PurchaseVendorCategory
    {
        $previous = $category->name;
        $category->update($data);

        // Vendors store the category by name, so a rename must carry them along
        // or every vendor in that category would silently lose it.
        if (isset($data['name']) && $data['name'] !== $previous) {
            PurchaseVendor::forTenant($category->tenant_id)
                ->where('category', $previous)
                ->update(['category' => $data['name']]);
        }

        $category->recordAudit('Vendor Category Updated', $actor, null, ['name' => $category->name]);

        return $category->fresh();
    }

    public function delete(PurchaseVendorCategory $category, User $actor): void
    {
        if ($category->vendorCount() > 0) {
            throw new BusinessException('This category is in use by one or more vendors.');
        }

        $category->recordAudit('Vendor Category Deleted', $actor, null, ['name' => $category->name]);
        $category->delete();
    }
}
