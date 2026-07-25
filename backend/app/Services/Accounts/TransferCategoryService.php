<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\TransferCategory;

/** CRUD for the Transfer Funds "Category / Head" master. */
class TransferCategoryService
{
    public function list(int $tenantId)
    {
        return TransferCategory::forTenant($tenantId)->orderBy('name')->get();
    }

    public function create(array $data, int $tenantId): TransferCategory
    {
        return TransferCategory::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(TransferCategory $category, array $data, int $tenantId): TransferCategory
    {
        $this->assert($category, $tenantId);
        $category->update(array_intersect_key($data, array_flip(['name', 'description', 'is_active'])));
        return $category;
    }

    /** Safe to delete any time — acc_vouchers.transfer_category_id is nullOnDelete. */
    public function delete(TransferCategory $category, int $tenantId): void
    {
        $this->assert($category, $tenantId);
        $category->delete();
    }

    private function assert(TransferCategory $category, int $tenantId): void
    {
        if ($category->tenant_id !== $tenantId) {
            throw new BusinessException('Category not found.');
        }
    }
}
