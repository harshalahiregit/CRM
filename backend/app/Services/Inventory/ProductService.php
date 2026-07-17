<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Category;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(private StockService $stock)
    {
    }

    /* ── Products ───────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): Collection
    {
        $q = Product::forTenant($tenantId)->with('category:id,name');

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)
                ->orWhere('sku', 'like', $s)
                ->orWhere('barcode', 'like', $s));
        }
        foreach (['category_id', 'brand', 'status'] as $col) {
            if (! empty($filters[$col])) {
                $q->where($col, $filters[$col]);
            }
        }

        // On-hand as a subquery — one query for the page instead of N per row.
        $q->selectRaw('inventory_products.*')
            ->selectSub(
                Stock::selectRaw('COALESCE(SUM(quantity),0)')
                    ->whereColumn('inventory_stock.product_id', 'inventory_products.id'),
                'on_hand'
            )
            ->selectSub(
                Stock::selectRaw('COALESCE(SUM(reserved_quantity),0)')
                    ->whereColumn('inventory_stock.product_id', 'inventory_products.id'),
                'reserved'
            );

        $rows = $q->latest('inventory_products.id')->get();

        // "low_stock" is a derived flag the list/kanban colour-codes on.
        return $rows->each(function (Product $p) {
            $threshold = (float) ($p->reorder_point ?: $p->min_stock);
            $p->setAttribute('low_stock', $threshold > 0 && (float) $p->on_hand <= $threshold);
        });
    }

    public function show(int $id, int $tenantId): Product
    {
        $product = $this->find($id, $tenantId);
        $product->load('category:id,name', 'creator:id,name');
        $product->setAttribute('levels', $this->stock->levelsFor($id, $tenantId));
        $product->setAttribute('totals', $this->stock->totalsFor($id, $tenantId));

        return $product;
    }

    /**
     * Keep the three pricing fields consistent (blueprint's caculator_sale_price /
     * caculator_profit_rate). The form computes live as you type; this fills in
     * whichever one an API caller left out, so the numbers can't disagree.
     */
    private function syncPricing(array $data): array
    {
        $cost = isset($data['cost_price']) ? (float) $data['cost_price'] : null;
        $sale = isset($data['sale_price']) ? (float) $data['sale_price'] : null;
        $ratio = isset($data['profit_ratio']) ? (float) $data['profit_ratio'] : null;

        if ($cost > 0 && $ratio !== null && $sale === null) {
            $data['sale_price'] = round($cost * (1 + $ratio / 100), 2);
        } elseif ($cost > 0 && $sale !== null && $ratio === null) {
            $data['profit_ratio'] = round((($sale - $cost) / $cost) * 100, 2);
        }

        return $data;
    }

    /** The blueprint stores a unit label on the item; keep it in step with unit_id. */
    private function syncUnit(array $data, int $tenantId): array
    {
        if (! empty($data['unit_id'])) {
            $unit = \App\Models\Inventory\Unit::forTenant($tenantId)->find($data['unit_id']);
            if ($unit) {
                $data['base_unit'] = $unit->short_name ?: $unit->name;
            }
        }

        return $data;
    }

    public function create(array $data, int $tenantId, int $userId): Product
    {
        $data['sku'] = trim($data['sku'] ?? '') ?: $this->nextSku($tenantId, $data['name']);
        $this->assertSkuFree($data['sku'], $tenantId);
        $data = $this->syncUnit($this->syncPricing($data), $tenantId);

        // A barcode is expected on every physical item; generate one when the
        // user doesn't have a printed code to type in.
        $data['barcode'] = trim($data['barcode'] ?? '') ?: $this->nextBarcode($tenantId);

        $opening = $data['opening_stock'] ?? null;
        $openingWarehouse = $data['opening_warehouse_id'] ?? null;
        unset($data['opening_stock'], $data['opening_warehouse_id']);

        return DB::transaction(function () use ($data, $tenantId, $userId, $opening, $openingWarehouse) {
            $product = Product::create([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);

            // Opening stock is a real movement, not a seeded number, so day one
            // has the same audit trail as day one hundred.
            if ($opening > 0 && $openingWarehouse) {
                $this->stock->record([
                    'product_id'   => $product->id,
                    'warehouse_id' => (int) $openingWarehouse,
                    'type'         => 'opening',
                    'quantity'     => (float) $opening,
                    'reason'       => 'Opening stock',
                ], $tenantId, $userId);
            }

            return $product;
        });
    }

    public function update(int $id, array $data, int $tenantId): Product
    {
        $product = $this->find($id, $tenantId);

        if (! empty($data['sku']) && $data['sku'] !== $product->sku) {
            $this->assertSkuFree($data['sku'], $tenantId, $id);
        }
        unset($data['opening_stock'], $data['opening_warehouse_id']);

        // An item can't be its own parent — that would loop the item tree.
        if (! empty($data['parent_id']) && (int) $data['parent_id'] === $id) {
            throw new BusinessException('An item cannot be its own parent.', 422);
        }

        $data = $this->syncUnit($this->syncPricing($data), $tenantId);
        $product->fill($data)->save();

        return $product->fresh('category');
    }

    public function delete(int $id, int $tenantId): void
    {
        $product = $this->find($id, $tenantId);

        // Refuse to hide a product that still has stock — the balance would
        // vanish from the UI while the ledger says it exists.
        $onHand = (float) (Stock::forTenant($tenantId)->where('product_id', $id)->sum('quantity'));
        if ($onHand > 0) {
            throw new BusinessException('This product still has stock on hand. Issue or write it off before deleting.', 422);
        }

        $product->delete();
    }

    /* ── Categories ─────────────────────────────────────────────── */

    public function categories(int $tenantId): Collection
    {
        return Category::forTenant($tenantId)
            ->withCount('products')
            ->orderBy('order')->orderBy('name')->get();
    }

    public function createCategory(array $data, int $tenantId): Category
    {
        return Category::create([...$data, 'tenant_id' => $tenantId]);
    }

    public function updateCategory(int $id, array $data, int $tenantId): Category
    {
        $c = Category::forTenant($tenantId)->findOrFail($id);

        // A category can't be its own ancestor — that would orphan the tree.
        if (! empty($data['parent_id']) && (int) $data['parent_id'] === $id) {
            throw new BusinessException('A category cannot be its own parent.', 422);
        }

        $c->fill($data)->save();

        return $c;
    }

    public function deleteCategory(int $id, int $tenantId): void
    {
        $c = Category::forTenant($tenantId)->findOrFail($id);

        if (Product::forTenant($tenantId)->where('category_id', $id)->exists()) {
            throw new BusinessException('This category still has products. Move them first.', 422);
        }
        if (Category::forTenant($tenantId)->where('parent_id', $id)->exists()) {
            throw new BusinessException('This category has sub-categories. Remove them first.', 422);
        }

        $c->delete();
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): Product
    {
        return Product::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That product does not exist.', 404);
    }

    /** Resolve a scanned barcode (or SKU) to a product — powers scanner input. */
    public function findByCode(string $code, int $tenantId): Product
    {
        $code = trim($code);

        return Product::forTenant($tenantId)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first()
            ?? throw new BusinessException("No product matches “{$code}”.", 404);
    }

    private function assertSkuFree(string $sku, int $tenantId, ?int $exceptId = null): void
    {
        $exists = Product::forTenant($tenantId)->where('sku', $sku)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw new BusinessException("SKU “{$sku}” is already used by another product.", 422);
        }
    }

    /** Readable SKU from the name plus a per-tenant counter: TSH-0007. */
    private function nextSku(int $tenantId, string $name): string
    {
        $prefix = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z]/', '')->substr(0, 3);
        $prefix = $prefix->isEmpty() ? 'SKU' : (string) $prefix;

        do {
            $n = Product::forTenant($tenantId)->withTrashed()->count() + 1;
            $sku = sprintf('%s-%04d', $prefix, $n);
            $taken = Product::forTenant($tenantId)->withTrashed()->where('sku', $sku)->exists();
            // Deleted rows keep their SKU, so skip past any collision.
            if ($taken) {
                $sku = sprintf('%s-%04d', $prefix, $n + random_int(1, 999));
            }
        } while (Product::forTenant($tenantId)->withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    /** Numeric, scanner-friendly internal barcode. */
    private function nextBarcode(int $tenantId): string
    {
        do {
            $code = (string) random_int(1000000000000, 9999999999999);
        } while (Product::forTenant($tenantId)->withTrashed()->where('barcode', $code)->exists());

        return $code;
    }
}
