<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCatalogItem;
use App\Models\User;
use App\Repositories\Purchase\PurchaseCatalogItemRepository;
use App\Support\Purchase\PurchaseCatalogStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PurchaseCatalogService
{
    public function __construct(private PurchaseCatalogItemRepository $catalogRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->catalogRepository->filtered($tenantId, $filters);
    }

    /** Active items only — this feeds the pick-lists, so it must never surface a
     *  Draft or Discontinued item. */
    public function activeSearch(int $tenantId, ?string $search = null): Collection
    {
        $query = PurchaseCatalogItem::forTenant($tenantId)->active();
        if ($search) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->limit(100)->get();
    }

    public function create(array $data, User $actor): PurchaseCatalogItem
    {
        $tenantId = $actor->tenant_id;
        $this->assertSkuUnique($data['sku'] ?? null, $tenantId);

        $item = PurchaseCatalogItem::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $actor->id,
            'status'     => $data['status'] ?? Status::DRAFT,
        ]);

        $item->recordAudit('Catalog Item Created', $actor, null, ['sku' => $item->sku, 'name' => $item->name]);
        Log::channel('purchase')->info('Catalog item created', ['catalog_item_id' => $item->id, 'tenant_id' => $tenantId]);

        return $item->fresh();
    }

    public function update(PurchaseCatalogItem $item, array $data, User $actor): PurchaseCatalogItem
    {
        // Definition stays editable across Draft/Active — a catalog item's cost
        // legitimately drifts, and snapshot-on-select means edits never touch
        // lines already raised. Only the SKU's uniqueness is guarded.
        if (isset($data['sku']) && $data['sku'] !== $item->sku) {
            $this->assertSkuUnique($data['sku'], $item->tenant_id, $item->id);
        }

        $item->update($data);
        $item->recordAudit('Catalog Item Updated', $actor);

        return $item->fresh();
    }

    public function setStatus(PurchaseCatalogItem $item, string $status, User $actor): PurchaseCatalogItem
    {
        if (! Status::isValid($status)) {
            throw new BusinessException('Unknown status.');
        }
        $verb = match ($status) {
            Status::ACTIVE       => 'Catalog Item Activated',
            Status::DISCONTINUED => 'Catalog Item Discontinued',
            default              => 'Catalog Item Set to Draft',
        };
        $item->update(['status' => $status]);
        $item->recordAudit($verb, $actor, null, ['to' => $status]);

        return $item->fresh();
    }

    public function delete(PurchaseCatalogItem $item, User $actor): void
    {
        // Never hard-remove an item other records may reference; discontinue an
        // active one instead. Only an untouched Draft may be deleted.
        if ($item->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft item can be deleted. Discontinue an active item instead.');
        }
        $item->recordAudit('Catalog Item Deleted', $actor);
        $item->delete();
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseCatalogItem::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'draft'        => $base()->where('status', Status::DRAFT)->count(),
            'active'       => $base()->where('status', Status::ACTIVE)->count(),
            'discontinued' => $base()->where('status', Status::DISCONTINUED)->count(),
            'categories'   => $base()->whereNotNull('category')->distinct()->count('category'),
        ];
    }

    /**
     * Resolve a catalog_item_id supplied on a procurement line into a snapshot
     * of the item's values — the shared guard every line-saving service calls.
     *
     * Enforces the core rule: only an Active item, in the caller's tenant, may be
     * selected onto a NEW line. Returns [description, unit, rate, tax] to snapshot
     * (callers may override with explicit line values). Null id → null (free text).
     */
    public function snapshotForLine(?int $catalogItemId, int $tenantId): ?array
    {
        if (! $catalogItemId) {
            return null;
        }

        $item = PurchaseCatalogItem::forTenant($tenantId)->find($catalogItemId);
        if (! $item) {
            throw new BusinessException('Catalog item not found.', 404);
        }
        if (! $item->isSelectable()) {
            throw new BusinessException("Catalog item {$item->sku} is {$item->status_label} and cannot be added to a new line.");
        }

        return [
            'catalog_item_id' => $item->id,
            'description'     => $item->name,
            'unit'            => $item->uom,
            'rate'            => (float) $item->default_rate,
            'tax'             => (float) $item->default_tax,
        ];
    }

    private function assertSkuUnique(?string $sku, int $tenantId, ?int $ignoreId = null): void
    {
        if (! $sku) {
            return; // auto-generated on create
        }
        $exists = PurchaseCatalogItem::withTrashed()->forTenant($tenantId)
            ->where('sku', $sku)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("SKU {$sku} already exists in your catalog.");
        }
    }
}
