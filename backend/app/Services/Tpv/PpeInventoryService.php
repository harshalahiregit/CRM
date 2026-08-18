<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Category;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\User;
use App\Services\Inventory\ConfigService;
use App\Services\Inventory\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PPE, backed entirely by Inventory.
 *
 * There is ONE stock ledger — inventory_stock / inventory_movements — and this
 * service is the only bridge to it from the vendor modules. It holds no quantities
 * of its own: `tpv_worker_ppe_issues` records who is holding what, which Inventory
 * does not track, and every quantity shown or changed here is read from or written
 * through StockService.
 *
 * Issue/return therefore behave exactly like any other stock movement and appear in
 * the same ledger, with reference_type='ppe_issue' so the movement traces back to
 * the worker who received it.
 */
class PpeInventoryService
{
    /** Category name that marks a product as PPE. Sub-categories are included. */
    public const PPE_CATEGORY = 'PPE';

    /**
     * Valid outcomes for an issued item, and whether stock comes back.
     *
     * ONLY a genuine return moves stock. Lost and damaged items left inventory at
     * ISSUE time — the outward movement is already in the ledger — so writing a
     * second 'lost'/'damage' movement would decrement the same items twice and
     * under-report on-hand stock. The loss is recorded on the issue record instead,
     * and the original issue movement remains its ledger trace.
     */
    private const RETURN_CONDITIONS = [
        'returned' => 'return',   // physically back on the shelf → stock in
        'damaged'  => null,       // never coming back → already out of stock
        'lost'     => null,       // never coming back → already out of stock
    ];

    public function __construct(private StockService $stock, private ConfigService $config)
    {
    }

    /**
     * Which warehouse a PPE movement comes out of (or goes back into).
     *
     * StockService::record() needs a real warehouse — balances are held per
     * product × warehouse — and it 404s on a missing one. Neither PPE caller
     * sends a warehouse: the catalogue issues from a worker's kit list and the
     * return panel from an existing issue, and neither UI has any notion of
     * sites. So the site is resolved here, server-side, in this order:
     *
     *   1. An explicit warehouse_id — but only after it is confirmed to belong
     *      to this tenant. A vendor must never be able to move another tenant's
     *      stock by naming its id, so this is validated, never trusted.
     *   2. The tenant's configured default_warehouse_id (Inventory settings).
     *   3. The warehouse flagged is_default — WarehouseService keeps exactly one
     *      per tenant and makes the first one created the default.
     *
     * Failing with a plain message beats the old "That warehouse does not exist",
     * which pointed at a warehouse nobody had named.
     */
    private function resolveWarehouseId(int $tenantId, mixed $requested = null): int
    {
        if ($requested !== null && $requested !== '') {
            $owned = Warehouse::forTenant($tenantId)->whereKey((int) $requested)->value('id');

            // Tenant isolation: an id from another tenant reads as absent, not as
            // permission to touch it.
            return (int) ($owned ?? throw new BusinessException('That warehouse does not exist.', 404));
        }

        $configured = $this->config->get($tenantId, 'default_warehouse_id');
        if ($configured) {
            $owned = Warehouse::forTenant($tenantId)->whereKey((int) $configured)->value('id');
            if ($owned) {
                return (int) $owned;
            }
        }

        $fallback = Warehouse::forTenant($tenantId)->where('is_default', true)->value('id')
            ?? Warehouse::forTenant($tenantId)->orderBy('id')->value('id');

        return (int) ($fallback ?? throw new BusinessException(
            'No warehouse is set up for PPE stock. Add one in Inventory → Warehouses, or set a default.',
            422
        ));
    }

    /* ── Reads ──────────────────────────────────────────────────────── */

    /** Product ids that count as PPE for this tenant (the category and its children). */
    public function ppeProductIds(int $tenantId): array
    {
        $roots = Category::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [strtolower(self::PPE_CATEGORY)])
            ->pluck('id');

        if ($roots->isEmpty()) {
            return [];
        }

        $ids = $roots->all();
        $children = Category::where('tenant_id', $tenantId)->whereIn('parent_id', $ids)->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $children)));
    }

    /**
     * The PPE catalogue with LIVE inventory figures — what the vendor sees.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function catalogue(int $tenantId): Collection
    {
        $ids = $this->ppeProductIds($tenantId);
        if ($ids === []) {
            return collect();
        }

        $products = Product::forTenant($tenantId)->whereIn('category_id', $ids)->orderBy('name')->get();

        // Issued-and-still-held, per product, in one query rather than per row.
        $issued = TpvWorkerPpeIssue::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'issued')
            ->whereIn('inventory_item_id', $products->pluck('id'))
            ->selectRaw('inventory_item_id, SUM(qty - returned_qty) AS held')
            ->groupBy('inventory_item_id')
            ->pluck('held', 'inventory_item_id');

        return $products->map(function (Product $p) use ($tenantId, $issued) {
            $totals = $this->stock->totalsFor($p->id, $tenantId);
            $available = (float) ($totals['available'] ?? 0);
            $reorder = (float) ($p->reorder_point ?: $p->min_stock ?: 0);

            return [
                'product_id'    => $p->id,
                'name'          => $p->name,
                'sku'           => $p->sku,
                'available'     => $available,
                'on_hand'       => (float) ($totals['quantity'] ?? 0),
                'reserved'      => (float) ($totals['reserved'] ?? 0),
                'issued'        => (float) ($issued[$p->id] ?? 0),
                'reorder_level' => $reorder,
                'status'        => $this->stockStatus($available, $reorder),
                // The file itself is private; the UI fetches it from the PPE image
                // route, which both portals can reach.
                'has_image'     => (bool) $p->image_path,
            ];
        });
    }

    /**
     * In Stock / Low Stock / Out of Stock, decided by the numbers alone.
     * A zero reorder level means "no threshold set", so anything above zero is fine.
     */
    public function stockStatus(float $available, float $reorderLevel): string
    {
        if ($available <= 0) {
            return 'out_of_stock';
        }
        if ($reorderLevel > 0 && $available <= $reorderLevel) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /** Dashboard summary — every figure derived from Inventory. */
    public function summary(int $tenantId): array
    {
        $rows = $this->catalogue($tenantId);
        $today = now()->toDateString();

        $issuedToday = TpvWorkerPpeIssue::where('tenant_id', $tenantId)
            ->whereDate('issued_date', $today)->sum('qty');
        $returnedToday = TpvWorkerPpeIssue::where('tenant_id', $tenantId)
            ->whereDate('returned_at', $today)->sum('returned_qty');

        return [
            'total_items'      => $rows->count(),
            'total_available'  => (float) $rows->sum('available'),
            'total_issued'     => (float) $rows->sum('issued'),
            'low_stock_items'  => $rows->where('status', 'low_stock')->count(),
            'out_of_stock_items' => $rows->where('status', 'out_of_stock')->count(),
            'issued_today'     => (float) $issuedToday,
            'returned_today'   => (float) $returnedToday,
        ];
    }

    /**
     * The same dashboard figures, but the "issued" side counted from one vendor's
     * own workers.
     *
     * Stock availability stays tenant-wide on purpose: there is one central store
     * and a vendor has to see what is on the shelf to know whether it can kit a
     * worker out. What must NOT be tenant-wide is how much everyone else is
     * holding, so those three figures are scoped here.
     */
    public function summaryForVendor(int $vendorId, int $tenantId): array
    {
        $rows  = $this->catalogue($tenantId);
        $today = now()->toDateString();

        $mine = fn () => TpvWorkerPpeIssue::query()
            ->where('tpv_worker_ppe_issues.tenant_id', $tenantId)
            ->whereIn('tpv_worker_id', TpvWorker::where('vendor_id', $vendorId)->select('id'));

        return [
            'total_items'        => $rows->count(),
            'total_available'    => (float) $rows->sum('available'),
            'total_issued'       => (float) $mine()->where('status', 'issued')->sum(DB::raw('qty - returned_qty')),
            'low_stock_items'    => $rows->where('status', 'low_stock')->count(),
            'out_of_stock_items' => $rows->where('status', 'out_of_stock')->count(),
            'issued_today'       => (float) $mine()->whereDate('issued_date', $today)->sum('qty'),
            'returned_today'     => (float) $mine()->whereDate('returned_at', $today)->sum('returned_qty'),
        ];
    }

    /** One worker's PPE history — issued, returned, lost, damaged. */
    public function forWorker(int $workerId, int $tenantId): Collection
    {
        return TpvWorkerPpeIssue::query()
            ->where('tenant_id', $tenantId)
            ->where('tpv_worker_id', $workerId)
            ->with('product:id,name,sku')
            ->latest('issued_date')
            ->get()
            ->map(fn (TpvWorkerPpeIssue $i) => [
                'id'          => $i->id,
                'item'        => $i->product?->name ?: $i->item,
                'sku'         => $i->product?->sku,
                'qty'         => (float) $i->qty,
                'returned_qty' => (float) $i->returned_qty,
                'size'        => $i->size,
                'issue_date'  => $i->issued_date,
                'return_date' => $i->returned_at,
                'status'      => $i->status,
                'notes'       => $i->notes,
            ]);
    }

    /* ── Writes ─────────────────────────────────────────────────────── */

    /**
     * Issue PPE to a worker: stock out, movement recorded, holding logged.
     *
     * The availability check happens HERE rather than inside StockService, because
     * stock is legitimately allowed to go negative elsewhere (corrections, opening
     * balances). For a PPE issue it never should — you cannot hand out a helmet you
     * do not have.
     */
    public function issue(TpvWorker $worker, array $data, ?User $actor = null): TpvWorkerPpeIssue
    {
        $tenantId = (int) $worker->tenant_id;
        $qty = round((float) ($data['qty'] ?? 0), 3);

        if ($qty <= 0) {
            throw new BusinessException('Quantity must be greater than zero.', 422);
        }

        $product = Product::forTenant($tenantId)->find($data['inventory_item_id'] ?? null)
            ?? throw new BusinessException('That PPE item does not exist in Inventory.', 404);

        $totals = $this->stock->totalsFor($product->id, $tenantId);
        $available = (float) ($totals['available'] ?? 0);

        if ($available < $qty) {
            throw new BusinessException(sprintf(
                'Insufficient stock. Only %s %s %s available.',
                rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                $product->name,
                $available === 1.0 ? 'is' : 'are'
            ), 422);
        }

        // Resolved before the transaction so a misconfigured tenant fails cleanly
        // rather than half-way through writing an issue row.
        $warehouseId = $this->resolveWarehouseId($tenantId, $data['warehouse_id'] ?? null);

        return DB::transaction(function () use ($worker, $product, $qty, $data, $actor, $tenantId, $warehouseId) {
            $issue = TpvWorkerPpeIssue::create([
                'tenant_id'         => $tenantId,
                'tpv_worker_id'     => $worker->id,
                'inventory_item_id' => $product->id,
                'item'              => $product->name,   // snapshot: survives a rename
                'qty'               => $qty,
                'size'              => $data['size'] ?? null,
                'issued_date'       => $data['issued_date'] ?? now()->toDateString(),
                'issued_by'         => $actor?->id,
                'notes'             => $data['notes'] ?? null,
                'status'            => 'issued',
                'returned_qty'      => 0,
            ]);

            // The single ledger. reference_* ties the movement back to this issue,
            // so every PPE hand-out is traceable from the stock side too.
            $this->stock->record([
                'product_id'     => $product->id,
                'type'           => 'issue',
                'quantity'       => $qty,
                'warehouse_id'   => $warehouseId,
                'reason'         => 'PPE issued to worker',
                'notes'          => trim(sprintf('%s (worker #%d)', $worker->full_name ?? $worker->name ?? 'Worker', $worker->id)),
                'reference_type' => 'ppe_issue',
                'reference_id'   => $issue->id,
            ], $tenantId, $actor?->id);

            // Step 4 of the worker wizard is "PPE issued". It is now derived from
            // the issues themselves rather than set by hand, so the gate cannot
            // claim PPE was handed out when no stock ever moved.
            $this->syncWorkerPpeState($worker);

            return $issue->fresh(['product']);
        });
    }

    /**
     * PPE required for one worker, from the role-based requirement matrix.
     *
     * A worker's requirements are the union of every rule that matches them: the
     * 'all' rules plus anything scoped to their designation or skill category. An
     * item required by two rules is still one item.
     *
     * Requirements are configuration, not code — changing a rule changes the next
     * validation immediately. Inventory still owns the products themselves.
     */
    public function requiredFor(TpvWorker $worker): Collection
    {
        return TpvPpeRequirement::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('is_active', true)
            ->with('product:id,name,sku,status')
            ->get()
            ->filter(fn (TpvPpeRequirement $r) => $r->product && $r->matches($worker))
            ->unique('product_id')
            ->values();
    }

    /** Product ids this worker currently holds (issued and not fully handed back). */
    private function heldProductIds(TpvWorker $worker): Collection
    {
        return TpvWorkerPpeIssue::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('tpv_worker_id', $worker->id)
            ->where('status', 'issued')
            ->whereRaw('qty > returned_qty')
            ->pluck('inventory_item_id')
            ->filter()
            ->unique();
    }

    /**
     * The full ✓/✗ picture for a worker: every required item and whether it is
     * currently held. This is what the badge screen shows, so a blocked badge can
     * always say exactly what is missing.
     */
    public function complianceFor(TpvWorker $worker): array
    {
        $required = $this->requiredFor($worker);
        $held     = $this->heldProductIds($worker);

        $items = $required->map(fn (TpvPpeRequirement $r) => [
            'product_id' => $r->product_id,
            'name'       => $r->product->name,
            'sku'        => $r->product->sku,
            'qty'        => $r->qty,
            'scope'      => $r->scope_type === 'all' ? 'All Workers' : $r->scope_value,
            'issued'     => $held->contains($r->product_id),
        ])->values()->all();

        $missing = collect($items)->reject(fn ($i) => $i['issued'])->values();

        return [
            'role'       => $worker->designation,
            'skill'      => $worker->skill_category,
            'items'      => $items,
            'missing'    => $missing->all(),
            'compliant'  => $missing->isEmpty(),
            // No rules configured for this role is not the same as being equipped.
            'configured' => $required->isNotEmpty(),
        ];
    }

    /** Required PPE this worker is NOT holding. Empty = cleared to badge. */
    public function missingMandatoryFor(TpvWorker $worker): Collection
    {
        $held = $this->heldProductIds($worker);

        return $this->requiredFor($worker)
            ->reject(fn (TpvPpeRequirement $r) => $held->contains($r->product_id))
            ->map(fn (TpvPpeRequirement $r) => $r->product)
            ->values();
    }

    /**
     * Tenant-wide PPE compliance: how many workers are fully equipped, and which
     * items are most often missing. Every figure is derived on read.
     */
    public function complianceSummary(int $tenantId): array
    {
        $rules = TpvPpeRequirement::query()
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->with('product:id,name,sku')->get()->filter(fn ($r) => $r->product);

        $workers = TpvWorker::query()->where('tenant_id', $tenantId)->get();

        if ($rules->isEmpty() || $workers->isEmpty()) {
            return [
                'workers'          => $workers->count(),
                'fully_equipped'   => 0,
                'missing_ppe'      => 0,
                'not_configured'   => $rules->isEmpty() ? $workers->count() : 0,
                'missing_by_item'  => [],
            ];
        }

        // One query for every outstanding issue rather than one per worker.
        $heldByWorker = TpvWorkerPpeIssue::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'issued')
            ->whereRaw('qty > returned_qty')
            ->get(['tpv_worker_id', 'inventory_item_id'])
            ->groupBy('tpv_worker_id')
            ->map(fn ($g) => $g->pluck('inventory_item_id')->filter()->unique());

        $equipped = 0;
        $short = 0;
        $unconfigured = 0;
        $missingCounts = [];

        foreach ($workers as $worker) {
            $required = $rules->filter(fn (TpvPpeRequirement $r) => $r->matches($worker))->unique('product_id');

            if ($required->isEmpty()) {
                $unconfigured++;
                continue;
            }

            $held = $heldByWorker[$worker->id] ?? collect();
            $missing = $required->reject(fn ($r) => $held->contains($r->product_id));

            if ($missing->isEmpty()) {
                $equipped++;
                continue;
            }

            $short++;
            foreach ($missing as $rule) {
                $key = $rule->product_id;
                $missingCounts[$key] ??= ['product_id' => $key, 'name' => $rule->product->name, 'workers' => 0];
                $missingCounts[$key]['workers']++;
            }
        }

        return [
            'workers'         => $workers->count(),
            'fully_equipped'  => $equipped,
            'missing_ppe'     => $short,
            'not_configured'  => $unconfigured,
            'missing_by_item' => collect($missingCounts)->sortByDesc('workers')->values()->all(),
        ];
    }

    /**
     * Re-derive a worker's PPE step state from their outstanding issues.
     *
     * `ppe_status`: 1 = issued, 0 = pending. A deliberate skip (2) is a decision,
     * not a fact about stock, so it is left alone until something is actually issued.
     */
    public function syncWorkerPpeState(TpvWorker $worker): void
    {
        $held = TpvWorkerPpeIssue::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('tpv_worker_id', $worker->id)
            ->where('status', 'issued')
            ->whereRaw('qty > returned_qty')
            ->pluck('item')
            ->unique()
            ->values();

        $worker->forceFill([
            'ppe_status'   => $held->isNotEmpty() ? 1 : ((int) $worker->ppe_status === 2 ? 2 : 0),
            'ppe_items'    => $held->isNotEmpty() ? $held->implode(', ') : null,
            // Step 4 reached — the wizard must not fall back behind it.
            'current_step' => $held->isNotEmpty()
                ? max((int) $worker->current_step, 4)
                : (int) $worker->current_step,
        ])->save();
    }

    /**
     * The site this issue's stock left from, read back off its ledger entry.
     *
     * Null for an issue predating warehouse resolution, or one whose movement was
     * pruned — the caller then falls back to the tenant default.
     */
    private function issuedFromWarehouseId(TpvWorkerPpeIssue $issue): ?int
    {
        return \App\Models\Inventory\Movement::query()
            ->where('tenant_id', $issue->tenant_id)
            ->where('reference_type', 'ppe_issue')
            ->where('reference_id', $issue->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->value('from_warehouse_id');
    }

    /**
     * Return, or write off as lost/damaged.
     *
     * Only a genuine return puts stock back; lost and damaged leave the ledger with
     * an outward movement of their own so the loss is visible rather than silent.
     */
    public function returnIssue(TpvWorkerPpeIssue $issue, array $data, ?User $actor = null): TpvWorkerPpeIssue
    {
        $condition = $data['condition'] ?? 'returned';
        if (! array_key_exists($condition, self::RETURN_CONDITIONS)) {
            throw new BusinessException('Unknown return condition.', 422);
        }

        $outstanding = round((float) $issue->qty - (float) $issue->returned_qty, 3);
        $qty = round((float) ($data['qty'] ?? $outstanding), 3);

        if ($qty <= 0) {
            throw new BusinessException('Quantity must be greater than zero.', 422);
        }
        if ($qty > $outstanding) {
            throw new BusinessException(sprintf(
                'Only %s of this issue %s outstanding.',
                rtrim(rtrim(number_format($outstanding, 3, '.', ''), '0'), '.'),
                $outstanding === 1.0 ? 'is' : 'are'
            ), 422);
        }

        // Only a genuine return moves stock, so only then is a site needed —
        // resolving it for a lost/damaged write-off would fail a tenant that has
        // no warehouse at all, for a call that touches no stock.
        //
        // Kit goes back where it came from: the outward movement recorded at issue
        // time carries the site, so a return does not silently relocate stock to
        // the default warehouse. An explicit warehouse_id still wins; the tenant
        // default is the last resort.
        $movementType = self::RETURN_CONDITIONS[$condition];
        $warehouseId  = $movementType
            ? $this->resolveWarehouseId(
                (int) $issue->tenant_id,
                $data['warehouse_id'] ?? $this->issuedFromWarehouseId($issue),
            )
            : null;

        return DB::transaction(function () use ($issue, $qty, $condition, $data, $actor, $movementType, $warehouseId) {
            $tenantId = (int) $issue->tenant_id;

            if ($movementType && $issue->inventory_item_id) {
                $this->stock->record([
                    'product_id'     => $issue->inventory_item_id,
                    'type'           => $movementType,
                    'quantity'       => $qty,
                    'warehouse_id'   => $warehouseId,
                    'reason'         => 'PPE '.$condition,
                    'notes'          => $data['notes'] ?? null,
                    'reference_type' => 'ppe_issue',
                    'reference_id'   => $issue->id,
                ], $tenantId, $actor?->id);
            }

            $returned = round((float) $issue->returned_qty + $qty, 3);
            $issue->update([
                'returned_qty' => $returned,
                'returned_at'  => now(),
                'returned_by'  => $actor?->id,
                'return_notes' => $data['notes'] ?? $issue->return_notes,
                // Partly-returned stays 'issued' — the worker still holds the rest.
                'status'       => $returned >= (float) $issue->qty ? $condition : 'issued',
            ]);

            // Handing everything back drops the worker out of "PPE issued".
            if ($issue->worker) {
                $this->syncWorkerPpeState($issue->worker);
            }

            return $issue->fresh(['product']);
        });
    }
}
