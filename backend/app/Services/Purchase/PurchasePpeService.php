<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Models\User;
use App\Services\Inventory\ConfigService;
use App\Services\Inventory\StockService;
use App\Services\Tpv\PpeInventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PPE issue / return for Purchase workers.
 *
 * There is ONE stock ledger — inventory_stock / inventory_movements — and this
 * service does not keep a second one. Every quantity change goes through
 * StockService, so a Purchase hand-out appears in Admin Inventory beside every
 * other movement, with reference_type = 'purchase_ppe_issue' tying it back.
 *
 * The CATALOGUE is deliberately reused from PpeInventoryService: which products
 * count as PPE is a tenant-level fact about Inventory, not a per-module one, and
 * duplicating that rule would let the two modules disagree about what PPE is.
 * Only the issue/return records are Purchase-owned.
 *
 * Warehouse ids are never taken from the portal — see resolveWarehouseId().
 */
class PurchasePpeService
{
    /**
     * Valid outcomes for an issued item, and whether stock comes back.
     *
     * ONLY a genuine return moves stock. Lost and damaged items left inventory at
     * ISSUE time — that outward movement is already in the ledger — so writing a
     * second movement would decrement the same items twice and under-report
     * on-hand stock. The loss is recorded on the issue row instead.
     */
    private const RETURN_CONDITIONS = [
        'returned' => 'return',
        'damaged'  => null,
        'lost'     => null,
    ];

    public function __construct(
        private StockService $stock,
        private ConfigService $config,
        private PpeInventoryService $catalogueSource,
    ) {
    }

    /* ── Reads ──────────────────────────────────────────────────────── */

    /** The PPE shelf — tenant-wide, because there is one central store. */
    public function catalogue(int $tenantId): Collection
    {
        return $this->catalogueSource->catalogue($tenantId);
    }

    /**
     * Dashboard figures for ONE vendor.
     *
     * Availability is tenant-wide (the shelf is shared and a vendor must see what
     * is on it); the issued figures are the vendor's own. Showing tenant-wide
     * issuance would tell a vendor how much everyone else is holding.
     */
    public function summaryForVendor(int $vendorId, int $tenantId): array
    {
        $rows  = $this->catalogue($tenantId);
        $today = now()->toDateString();

        $mine = fn () => PurchaseWorkerPpeIssue::query()
            ->where('purchase_worker_ppe_issues.tenant_id', $tenantId)
            ->whereIn('purchase_worker_id',
                PurchaseWorker::where('purchase_vendor_id', $vendorId)->select('id'));

        return [
            'total_items'        => $rows->count(),
            'total_available'    => (float) $rows->sum('available'),
            'low_stock_items'    => $rows->where('status', 'low_stock')->count(),
            'out_of_stock_items' => $rows->where('status', 'out_of_stock')->count(),
            'total_issued'       => (float) $mine()->where('status', 'issued')->sum(DB::raw('qty - returned_qty')),
            'issued_today'       => (float) $mine()->whereDate('issued_date', $today)->sum('qty'),
            'returned_today'     => (float) $mine()->whereDate('returned_at', $today)->sum('returned_qty'),
        ];
    }

    /** One worker's PPE history. */
    public function forWorker(PurchaseWorker $worker): Collection
    {
        return PurchaseWorkerPpeIssue::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('purchase_worker_id', $worker->id)
            ->with('product:id,name,sku')
            ->latest('issued_date')
            ->get();
    }

    /** Items the worker currently holds — issued and not fully handed back. */
    public function heldBy(PurchaseWorker $worker): Collection
    {
        return PurchaseWorkerPpeIssue::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('purchase_worker_id', $worker->id)
            ->where('status', 'issued')
            ->whereRaw('qty > returned_qty')
            ->get();
    }

    /**
     * Whether the worker is equipped enough to be badged.
     *
     * Purchase has no per-role PPE requirement matrix (TpvPpeRequirement is a TPV
     * table and its scopes read TPV worker fields), so the rule here is the
     * simpler one the Purchase data supports: holding at least one item counts as
     * PPE issued. Stated explicitly rather than silently reusing a TPV matrix that
     * would never match a Purchase worker.
     */
    public function complianceFor(PurchaseWorker $worker): array
    {
        $held = $this->heldBy($worker);

        return [
            'designation' => $worker->designation,
            'items'       => $held->map(fn (PurchaseWorkerPpeIssue $i) => [
                'issue_id'   => $i->id,
                'product_id' => $i->inventory_item_id,
                'name'       => $i->item,
                'qty'        => (float) $i->qty - (float) $i->returned_qty,
                'size'       => $i->size,
                'issued_on'  => optional($i->issued_date)->toDateString(),
            ])->values()->all(),
            'held_count' => $held->count(),
            'compliant'  => $held->isNotEmpty(),
        ];
    }

    /* ── Writes ─────────────────────────────────────────────────────── */

    /**
     * Issue PPE to a Purchase worker and move the stock.
     *
     * Availability is checked BEFORE the transaction opens so an over-issue fails
     * without writing an issue row; StockService then performs the decrement
     * inside it, so stock and the issue record commit together or not at all.
     */
    public function issue(PurchaseWorker $worker, array $data, ?User $actor = null): PurchaseWorkerPpeIssue
    {
        $tenantId = (int) $worker->tenant_id;
        $qty      = round((float) ($data['qty'] ?? 0), 3);

        if ($qty <= 0) {
            throw new BusinessException('Quantity must be greater than zero.', 422);
        }

        $product = Product::forTenant($tenantId)->find($data['inventory_item_id'] ?? null)
            ?? throw new BusinessException('That PPE item does not exist in Inventory.', 404);

        $available = (float) ($this->stock->totalsFor($product->id, $tenantId)['available'] ?? 0);

        if ($available < $qty) {
            throw new BusinessException(sprintf(
                'Insufficient stock. Only %s %s available.',
                rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                $product->name,
            ), 422);
        }

        // Resolved up front so a tenant with no warehouse fails cleanly rather than
        // half-way through writing an issue row.
        $warehouseId = $this->resolveWarehouseId($tenantId, $data['warehouse_id'] ?? null);

        return DB::transaction(function () use ($worker, $product, $qty, $data, $actor, $tenantId, $warehouseId) {
            $issue = PurchaseWorkerPpeIssue::create([
                'tenant_id'          => $tenantId,
                'purchase_worker_id' => $worker->id,
                'inventory_item_id'  => $product->id,
                'item'               => $product->name,
                'qty'                => $qty,
                'size'               => $data['size'] ?? null,
                'issued_date'        => $data['issued_date'] ?? now()->toDateString(),
                'issued_by'          => $actor?->id,
                'notes'              => $data['notes'] ?? null,
                'status'             => 'issued',
                'returned_qty'       => 0,
            ]);

            $this->stock->record([
                'product_id'     => $product->id,
                'type'           => 'issue',
                'quantity'       => $qty,
                'warehouse_id'   => $warehouseId,
                'reason'         => 'PPE issued to purchase worker',
                'notes'          => trim(sprintf('%s (worker #%d)', $worker->full_name ?? 'Worker', $worker->id)),
                'reference_type' => 'purchase_ppe_issue',
                'reference_id'   => $issue->id,
            ], $tenantId, $actor?->id);

            $this->syncWorkerPpeState($worker);

            return $issue->fresh(['product']);
        });
    }

    /**
     * Hand back, or write off as lost/damaged.
     *
     * Kit goes back where it came from: the outward movement written at issue time
     * carries the site, so a return does not silently relocate stock to the default
     * warehouse.
     */
    public function returnIssue(PurchaseWorkerPpeIssue $issue, array $data, ?User $actor = null): PurchaseWorkerPpeIssue
    {
        $condition = $data['condition'] ?? 'returned';

        if (! array_key_exists($condition, self::RETURN_CONDITIONS)) {
            throw new BusinessException('Unknown return condition.', 422);
        }

        $outstanding = round((float) $issue->qty - (float) $issue->returned_qty, 3);
        $qty         = round((float) ($data['qty'] ?? $outstanding), 3);

        if ($qty <= 0) {
            throw new BusinessException('Quantity must be greater than zero.', 422);
        }
        if ($qty > $outstanding) {
            throw new BusinessException(sprintf(
                'Only %s of this issue is outstanding.',
                rtrim(rtrim(number_format($outstanding, 3, '.', ''), '0'), '.'),
            ), 422);
        }

        // Only a genuine return needs a site — resolving one for a write-off would
        // fail a tenant with no warehouse, for a call that touches no stock.
        $movementType = self::RETURN_CONDITIONS[$condition];
        $warehouseId  = $movementType
            ? $this->resolveWarehouseId((int) $issue->tenant_id, $this->issuedFromWarehouseId($issue))
            : null;

        return DB::transaction(function () use ($issue, $qty, $condition, $data, $actor, $movementType, $warehouseId) {
            if ($movementType && $issue->inventory_item_id) {
                $this->stock->record([
                    'product_id'     => $issue->inventory_item_id,
                    'type'           => $movementType,
                    'quantity'       => $qty,
                    'warehouse_id'   => $warehouseId,
                    'reason'         => 'PPE '.$condition,
                    'notes'          => $data['notes'] ?? null,
                    'reference_type' => 'purchase_ppe_issue',
                    'reference_id'   => $issue->id,
                ], (int) $issue->tenant_id, $actor?->id);
            }

            $returned = round((float) $issue->returned_qty + $qty, 3);

            $issue->update([
                'returned_qty' => $returned,
                'returned_at'  => now(),
                'returned_by'  => $actor?->id,
                'return_notes' => $data['notes'] ?? $issue->return_notes,
                // Fully accounted for → the issue closes under its outcome.
                'status'       => $returned >= (float) $issue->qty ? $condition : 'issued',
            ]);

            if ($issue->worker) {
                $this->syncWorkerPpeState($issue->worker);
            }

            return $issue->fresh(['product']);
        });
    }

    /**
     * Step 4 is "PPE issued", derived from the issues themselves rather than set
     * by hand, so the badge gate cannot claim PPE was handed out when no stock
     * ever moved. The pointer never falls back below a step already reached.
     */
    public function syncWorkerPpeState(PurchaseWorker $worker): void
    {
        $holds = $this->heldBy($worker)->isNotEmpty();

        if ($holds) {
            $worker->forceFill(['current_step' => max((int) $worker->current_step, 4)])->save();
        }
    }

    /* ── Warehouse ──────────────────────────────────────────────────── */

    /**
     * Which warehouse a movement comes out of (or goes back into).
     *
     * A requested id is validated against the tenant before use, so an id from
     * another tenant reads as absent rather than as permission to touch it. The
     * PORTAL never supplies one — callers there pass null and take the tenant
     * default, which is what stops a vendor moving stock between sites.
     */
    private function resolveWarehouseId(int $tenantId, mixed $requested = null): int
    {
        if ($requested !== null && $requested !== '') {
            $owned = Warehouse::forTenant($tenantId)->whereKey((int) $requested)->value('id');

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

    /** The site this issue physically left, read off its own outward movement. */
    private function issuedFromWarehouseId(PurchaseWorkerPpeIssue $issue): ?int
    {
        return Movement::query()
            ->where('tenant_id', $issue->tenant_id)
            ->where('reference_type', 'purchase_ppe_issue')
            ->where('reference_id', $issue->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->value('from_warehouse_id');
    }
}
