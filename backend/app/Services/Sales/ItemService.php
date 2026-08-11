<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\SalesItem;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Log;

class ItemService
{
    public function list(int $tenantId, ?string $category, ?string $search): \Illuminate\Support\Collection
    {
        $query = SalesItem::forTenant($tenantId);

        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId): SalesItem
    {
        $data = HtmlSanitizer::cleanFields($data, ['long_description']);

        $item = SalesItem::create([...$data, 'tenant_id' => $tenantId]);

        Log::channel('sales')->info('Sales item created', ['id' => $item->id, 'tenant_id' => $tenantId]);

        return $item;
    }

    public function show(SalesItem $item, int $tenantId): SalesItem
    {
        $this->authorizeItem($item, $tenantId);

        return $item;
    }

    public function update(SalesItem $item, array $data, int $tenantId): SalesItem
    {
        $this->authorizeItem($item, $tenantId);

        $data = HtmlSanitizer::cleanFields($data, ['long_description']);

        $item->update($data);

        Log::channel('sales')->info('Sales item updated', ['id' => $item->id, 'tenant_id' => $tenantId]);

        return $item;
    }

    public function delete(SalesItem $item, int $tenantId): void
    {
        $this->authorizeItem($item, $tenantId);

        $item->delete();

        Log::channel('sales')->info('Sales item deleted', ['id' => $item->id, 'tenant_id' => $tenantId]);
    }

    private function authorizeItem(SalesItem $item, int $tenantId): void
    {
        if ($item->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Sales item tenant mismatch', ['id' => $item->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
