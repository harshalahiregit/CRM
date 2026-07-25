<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Bom;
use App\Models\Inventory\BomLine;
use App\Models\Inventory\BuildOrder;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Manufacturing: bills of materials + build orders.
 *
 * The one interesting method is completeBuild(): it posts real stock movements
 * through StockService, so a finished product coming off the line is accounted
 * for exactly like any other stock change. Components go OUT, finished goods come
 * IN, all in a single transaction — if any component is short, the whole build
 * rolls back and nothing moved. Manufacturing never bypasses the ledger.
 */
class ManufacturingService
{
    public function __construct(private StockService $stock)
    {
    }

    /* ── Bills of materials ─────────────────────────────────────── */

    public function listBoms(int $tenantId, array $f = [])
    {
        $q = Bom::forTenant($tenantId)->with('product:id,sku,name')->withCount('lines');
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['product_id'])) {
            $q->where('product_id', (int) $f['product_id']);
        }

        return $q->orderByDesc('id')->get();
    }

    public function showBom(int $id, int $tenantId): Bom
    {
        return Bom::forTenant($tenantId)->with('product:id,sku,name', 'lines.component:id,sku,name,cost_price')->findOrFail($id);
    }

    public function createBom(array $d, int $tenantId, int $userId): Bom
    {
        $productId = (int) ($d['product_id'] ?? 0);
        if (! Product::forTenant($tenantId)->whereKey($productId)->exists()) {
            throw new BusinessException('Choose the finished-good item this recipe builds.', 422);
        }

        return DB::transaction(function () use ($d, $tenantId, $userId, $productId) {
            $bom = Bom::create([
                'tenant_id'  => $tenantId,
                'product_id' => $productId,
                'name'       => $d['name'] ?? null,
                'output_qty' => max(0.001, (float) ($d['output_qty'] ?? 1)),
                'status'     => $d['status'] ?? 'active',
                'note'       => $d['note'] ?? null,
                'created_by' => $userId,
            ]);
            $this->syncLines($bom, $d['lines'] ?? [], $tenantId);

            return $bom->fresh(['product', 'lines']);
        });
    }

    public function updateBom(int $id, array $d, int $tenantId): Bom
    {
        $bom = Bom::forTenant($tenantId)->findOrFail($id);

        return DB::transaction(function () use ($bom, $d, $tenantId) {
            $bom->fill(array_intersect_key($d, array_flip(['name', 'status', 'note'])));
            if (isset($d['output_qty'])) {
                $bom->output_qty = max(0.001, (float) $d['output_qty']);
            }
            if (! empty($d['product_id'])) {
                $bom->product_id = (int) $d['product_id'];
            }
            $bom->save();

            if (array_key_exists('lines', $d)) {
                $this->syncLines($bom, $d['lines'], $tenantId);
            }

            return $bom->fresh(['product', 'lines']);
        });
    }

    private function syncLines(Bom $bom, array $lines, int $tenantId): void
    {
        BomLine::forTenant($tenantId)->where('bom_id', $bom->id)->delete();
        foreach ($lines as $l) {
            $componentId = (int) ($l['component_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            if ($componentId <= 0 || $qty <= 0 || ! Product::forTenant($tenantId)->whereKey($componentId)->exists()) {
                continue;
            }
            if ($componentId === (int) $bom->product_id) {
                throw new BusinessException('A recipe cannot list its own finished good as a component.', 422);
            }
            BomLine::create([
                'tenant_id'    => $tenantId,
                'bom_id'       => $bom->id,
                'component_id' => $componentId,
                'qty'          => $qty,
                'note'         => $l['note'] ?? null,
            ]);
        }
    }

    public function deleteBom(int $id, int $tenantId): void
    {
        Bom::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /* ── Build orders ───────────────────────────────────────────── */

    public function listBuilds(int $tenantId, array $f = [])
    {
        $q = BuildOrder::forTenant($tenantId)->with('product:id,sku,name', 'warehouse:id,name', 'bom:id,name');
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }

        return $q->orderByDesc('id')->get();
    }

    public function showBuild(int $id, int $tenantId): BuildOrder
    {
        return BuildOrder::forTenant($tenantId)->with('product:id,sku,name', 'warehouse:id,name', 'bom.lines.component:id,sku,name')->findOrFail($id);
    }

    public function createBuild(array $d, int $tenantId, int $userId): BuildOrder
    {
        $bom = Bom::forTenant($tenantId)->findOrFail((int) ($d['bom_id'] ?? 0));
        $qty = (float) ($d['qty'] ?? 0);
        if ($qty <= 0) {
            throw new BusinessException('How many do you want to build?', 422);
        }

        $build = BuildOrder::create([
            'tenant_id'    => $tenantId,
            'bom_id'       => $bom->id,
            'product_id'   => $bom->product_id,
            'warehouse_id' => (int) $d['warehouse_id'],
            'qty'          => $qty,
            'status'       => 'draft',
            'note'         => $d['note'] ?? null,
            'created_by'   => $userId,
        ]);
        $build->code = 'BLD-'.str_pad((string) $build->id, 6, '0', STR_PAD_LEFT);
        $build->save();

        return $build->fresh(['product', 'warehouse', 'bom']);
    }

    public function setBuildStatus(int $id, string $status, int $tenantId, int $userId): BuildOrder
    {
        $build = BuildOrder::forTenant($tenantId)->findOrFail($id);

        if ($status === 'completed') {
            return $this->completeBuild($build, $tenantId, $userId);
        }
        if ($status === 'in_progress' && $build->status !== 'draft') {
            throw new BusinessException('Only a draft build can be started.', 422);
        }
        if ($status === 'cancelled' && $build->status === 'completed') {
            throw new BusinessException('A completed build cannot be cancelled — reverse the stock instead.', 422);
        }
        $build->update(['status' => $status]);

        return $build->fresh();
    }

    /**
     * Run the recipe: issue the components, receive the finished goods. One
     * transaction, so a shortage on any component leaves the ledger untouched.
     */
    private function completeBuild(BuildOrder $build, int $tenantId, int $userId): BuildOrder
    {
        if (! in_array($build->status, ['draft', 'in_progress'], true)) {
            throw new BusinessException('This build is already '.$build->status.'.', 422);
        }

        $bom = Bom::forTenant($tenantId)->with('lines.component:id,name,without_checking_warehouse')->findOrFail($build->bom_id);
        if ($bom->lines->isEmpty()) {
            throw new BusinessException('This recipe has no components to consume.', 422);
        }

        $multiplier = (float) $build->qty / max(0.001, (float) $bom->output_qty);

        DB::transaction(function () use ($build, $bom, $multiplier, $tenantId, $userId) {
            // Consume components (OUT). The ledger's own guard rejects a shortage,
            // rolling the whole build back with a clear message per component.
            foreach ($bom->lines as $line) {
                $consume = round((float) $line->qty * $multiplier, 3);
                if ($consume <= 0) {
                    continue;
                }
                $this->stock->record([
                    'product_id'     => $line->component_id,
                    'type'           => 'issue',
                    'quantity'       => $consume,
                    'warehouse_id'   => $build->warehouse_id,
                    'reason'         => "Consumed by build {$build->code}",
                    'reference_type' => 'build',
                    'reference_id'   => $build->id,
                ], $tenantId, $userId);
            }

            // Produce the finished goods (IN).
            $this->stock->record([
                'product_id'     => $build->product_id,
                'type'           => 'receive',
                'quantity'       => (float) $build->qty,
                'warehouse_id'   => $build->warehouse_id,
                'reason'         => "Produced by build {$build->code}",
                'reference_type' => 'build',
                'reference_id'   => $build->id,
            ], $tenantId, $userId);

            $build->update(['status' => 'completed', 'completed_by' => $userId, 'completed_at' => now()]);
        });

        return $build->fresh(['product', 'warehouse', 'bom']);
    }

    /**
     * Can this build be completed right now? Per-component availability so the UI
     * can warn before a shortage stops the run.
     */
    public function buildAvailability(int $id, int $tenantId): array
    {
        $build = BuildOrder::forTenant($tenantId)->findOrFail($id);
        $bom = Bom::forTenant($tenantId)->with('lines.component:id,sku,name')->findOrFail($build->bom_id);
        $multiplier = (float) $build->qty / max(0.001, (float) $bom->output_qty);

        $rows = [];
        $canBuild = true;
        foreach ($bom->lines as $line) {
            $need = round((float) $line->qty * $multiplier, 3);
            $have = (float) Stock::forTenant($tenantId)->where('product_id', $line->component_id)->where('warehouse_id', $build->warehouse_id)->sum('quantity');
            $ok = $have >= $need;
            $canBuild = $canBuild && $ok;
            $rows[] = [
                'component_id' => $line->component_id,
                'name'         => $line->component->name ?? ('#'.$line->component_id),
                'sku'          => $line->component->sku ?? null,
                'need'         => $need,
                'have'         => $have,
                'ok'           => $ok,
            ];
        }

        return ['can_build' => $canBuild, 'rows' => $rows];
    }

    public function deleteBuild(int $id, int $tenantId): void
    {
        $build = BuildOrder::forTenant($tenantId)->findOrFail($id);
        if ($build->status === 'completed') {
            throw new BusinessException('A completed build cannot be deleted — its stock movements are on the ledger.', 422);
        }
        $build->delete();
    }
}
