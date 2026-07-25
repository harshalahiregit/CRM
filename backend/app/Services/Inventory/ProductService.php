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

    /**
     * @param  array{user_id?:int,is_admin?:bool}  $viewer  Decorates can_edit per row.
     */
    public function list(int $tenantId, array $filters = [], array $viewer = []): Collection
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

        // Item filter — the blueprint's per-item dropdown.
        if (! empty($filters['product_id'])) {
            $q->whereKey($filters['product_id']);
        }

        // Tag filter. Tags are a JSON array and SQLite can't do whereJsonContains,
        // so match the quoted needle in the encoded text — portable across drivers.
        if (! empty($filters['tag'])) {
            $q->where('tags', 'like', '%"'.$filters['tag'].'"%');
        }

        // Warehouse filter — items that actually sit at that site.
        if (! empty($filters['warehouse_id'])) {
            $q->whereExists(fn ($s) => $s->from('inventory_stock')
                ->whereColumn('inventory_stock.product_id', 'inventory_products.id')
                ->where('inventory_stock.warehouse_id', $filters['warehouse_id'])
                ->where('inventory_stock.quantity', '>', 0));
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

        // "low_stock" is a derived flag the list/kanban colour-codes on, and
        // can_edit mirrors the backend barrier so the UI hides what it can't do.
        $isAdmin = (bool) ($viewer['is_admin'] ?? false);
        $userId = $viewer['user_id'] ?? null;
        $rows->each(function (Product $p) use ($isAdmin, $userId) {
            $threshold = $p->reorderThreshold();
            $p->setAttribute('low_stock', $threshold > 0 && (float) $p->on_hand <= $threshold);
            $p->setAttribute('can_edit', $isAdmin || ($userId !== null && (int) $p->created_by === (int) $userId));
            $p->setAttribute('can_delete', $isAdmin);
        });

        // Alert filter (blueprint's Alert dropdown). It reads the computed
        // on_hand, so it can only run once the rows are in hand.
        if (! empty($filters['alert'])) {
            $rows = $this->applyAlert($rows, $filters['alert'], $tenantId);
        }

        return $rows->values();
    }

    /**
     * Minimum stock / Maximum stock / Out of stock / expiring within a month —
     * the four choices the blueprint's Alert dropdown offers. Items excluded
     * from stock math are never "alerting": they have no balance to judge.
     */
    private function applyAlert(Collection $rows, string $alert, int $tenantId): Collection
    {
        if ($alert === 'expiring') {
            $days = (int) (app(ConfigService::class)->get($tenantId, 'expiry_alert_days') ?? 30);

            // Lots expiring inside the window, from the voucher lines that carry them.
            $ids = DB::table('inventory_voucher_items')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', now()->toDateString())
                ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
                ->distinct()->pluck('product_id')->all();

            return $rows->filter(fn (Product $p) => in_array($p->id, $ids, true));
        }

        return $rows->filter(function (Product $p) use ($alert) {
            if ($p->without_checking_warehouse) {
                return false;
            }

            $onHand = (float) $p->on_hand;

            return match ($alert) {
                'min_stock'    => (float) $p->min_stock > 0 && $onHand <= (float) $p->min_stock,
                'max_stock'    => $p->max_stock !== null && (float) $p->max_stock > 0 && $onHand >= (float) $p->max_stock,
                'out_of_stock' => $onHand <= 0,
                default        => true,
            };
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

    /**
     * Every distinct tag in use, for the Items filter dropdown. Tags are freeform
     * per item, so the vocabulary is whatever the tenant has actually typed.
     */
    public function tags(int $tenantId): array
    {
        $all = Product::forTenant($tenantId)->whereNotNull('tags')->pluck('tags')
            ->flatMap(fn ($t) => is_array($t) ? $t : [])
            ->map(fn ($t) => trim((string) $t))
            ->filter()->unique()->sort()->values()->all();

        return $all;
    }

    /** Drop unknown/ill-typed custom field values before they reach the JSON bag. */
    private function cleanCustom(array $data, int $tenantId): array
    {
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = app(ConfigService::class)
                ->sanitizeValues($tenantId, 'product', $data['custom_fields']);
        }

        // Tags arrive as an array from the form or a comma string from an import.
        if (array_key_exists('tags', $data) && ! is_array($data['tags'])) {
            $data['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) $data['tags']))));
        }
        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $data['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags'])))) ?: null;
        }

        return $data;
    }

    public function create(array $data, int $tenantId, int $userId): Product
    {
        $data['sku'] = trim($data['sku'] ?? '') ?: $this->nextSku($tenantId, $data['name']);
        $this->assertSkuFree($data['sku'], $tenantId);
        $data = $this->cleanCustom($this->syncUnit($this->syncPricing($data), $tenantId), $tenantId);

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

        $data = $this->cleanCustom($this->syncUnit($this->syncPricing($data), $tenantId), $tenantId);
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
