<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Location;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;

/**
 * Bin occupancy and the warehouse layout (§16).
 *
 * Everywhere else in the module a warehouse is a name on a document. Here it is
 * a building: zones holding racks holding shelves holding bins, each with a
 * limit and a load. That matters for two ordinary questions nothing else in the
 * app could answer — "where do I put this?" and "what is about to overflow?"
 *
 * Three rules this is careful about, because a layout screen is very easy to
 * make confidently wrong:
 *
 *  1. A CAPACITY WITHOUT A UNIT IS NOT A CAPACITY. 500 washers and 500 engines
 *     do not fit the same shelf. Every location says what its number counts
 *     (units, volume or weight), and mixing them up is refused rather than
 *     averaged.
 *
 *  2. MISSING PRODUCT DATA IS REPORTED, NOT ASSUMED ZERO. Measuring a shelf by
 *     weight when half the catalogue has no weight recorded would show it
 *     comfortably empty. Coverage comes back with every figure, and a partial
 *     one is labelled partial.
 *
 *  3. NO CAPACITY SET IS NOT INFINITE CAPACITY. A bin nobody has measured is
 *     reported as unassessable, never as having room.
 */
class LayoutService
{
    public function __construct(private InventoryNotifier $notifier)
    {
    }

    /* ── The layout ─────────────────────────────────────────────── */

    /**
     * The whole site as a tree, each node carrying its own load and the load of
     * everything under it.
     */
    public function forWarehouse(int $warehouseId, int $tenantId, string $measure = 'units'): array
    {
        $this->assertMeasure($measure);

        $warehouse = Warehouse::forTenant($tenantId)->find($warehouseId)
            ?? throw new BusinessException('That warehouse does not exist.', 404);

        $locations = Location::forTenant($tenantId)->where('warehouse_id', $warehouseId)
            ->orderBy('type')->orderBy('name')->get();

        $load = $this->loadByLocation($warehouseId, $tenantId, $measure);

        $byParent = $locations->groupBy(fn (Location $l) => $l->parent_id ?: 0);
        $tree = $this->build($byParent, 0, $load, $measure, 0);

        // Stock the warehouse holds but no location claims. Nothing else in the
        // module surfaces this, and it is the most useful thing on the page: the
        // building says it owns these units and nobody can say which shelf.
        $unlocated = $load['__unlocated__'] ?? ['qty' => 0, 'measured' => 0, 'skus' => 0, 'missing_data' => 0];

        return [
            'warehouse' => $warehouse->only(['id', 'name', 'code', 'type']),
            'measure'   => $measure,
            'tree'      => $tree,
            'totals'    => $this->totalsOf($tree, $unlocated),
            'unlocated' => $unlocated,
            'problems'  => $this->problems($warehouseId, $tenantId, $measure, $tree, $unlocated),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection  $byParent
     * @return array<int,array>
     */
    private function build($byParent, int $parentId, array $load, string $measure, int $depth): array
    {
        $out = [];

        foreach ($byParent->get($parentId, collect()) as $l) {
            $children = $this->build($byParent, $l->id, $load, $measure, $depth + 1);

            $own = $load[$l->id] ?? ['qty' => 0, 'measured' => 0, 'skus' => 0, 'missing_data' => 0, 'products' => []];

            // A parent's load is its own plus everything beneath it. Stock is
            // normally held against the leaf, but nothing stops a rack holding
            // a pallet directly, so both are counted.
            $used = $own['measured'] + array_sum(array_column($children, 'used'));
            $qty = $own['qty'] + array_sum(array_column($children, 'quantity'));
            $missing = $own['missing_data'] + array_sum(array_column($children, 'missing_data'));

            // Own capacity wins where it is set: a rack beam is the constraint
            // whatever its shelves say. Only when nobody measured the parent do
            // we fall back to adding up what is underneath it.
            $childCap = array_sum(array_map(fn ($c) => $c['capacity'] ?? 0, $children));
            $capacity = $l->capacity !== null ? (float) $l->capacity : ($childCap ?: null);
            $capUom = $l->capacity !== null ? $l->capacity_uom : $measure;

            // A capacity counted in a different unit from the one being viewed
            // cannot be compared to this load. Saying so beats inventing a
            // conversion factor between kilograms and cubic metres.
            $comparable = $capacity !== null && $capUom === $measure;

            $out[] = [
                'id'           => $l->id,
                'name'         => $l->name,
                'code'         => $l->code,
                'type'         => $l->type,
                'depth'        => $depth,
                'quantity'     => round($qty, 3),
                'used'         => round($used, 3),
                'capacity'     => $capacity !== null ? round($capacity, 3) : null,
                'capacity_uom' => $capUom,
                'comparable'   => $comparable,
                'utilisation'  => $comparable && $capacity > 0 ? round(($used / $capacity) * 100, 1) : null,
                'over'         => $comparable && $capacity > 0 && $used > $capacity + 0.0005,
                'free'         => $comparable && $capacity > 0 ? round(max($capacity - $used, 0), 3) : null,
                'skus'         => $own['skus'] + array_sum(array_column($children, 'skus')),
                // How much of this load could NOT be measured because the
                // product has no volume/weight recorded.
                'missing_data' => round($missing, 3),
                'products'     => $own['products'] ?? [],
                'children'     => $children,
            ];
        }

        return $out;
    }

    /**
     * How much of each measure sits at each location.
     *
     * @return array<int|string,array{qty:float,measured:float,skus:int,missing_data:float,products:array}>
     */
    private function loadByLocation(int $warehouseId, int $tenantId, string $measure): array
    {
        $rows = Stock::forTenant($tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '!=', 0)
            ->with('product:id,sku,name,base_unit,weight,volume')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = $row->location_id ?: '__unlocated__';
            $qty = (float) $row->quantity;

            $out[$key] ??= ['qty' => 0, 'measured' => 0, 'skus' => 0, 'missing_data' => 0, 'products' => []];

            $factor = match ($measure) {
                'volume' => $row->product?->volume,
                'weight' => $row->product?->weight,
                default  => 1,
            };

            $out[$key]['qty'] += $qty;
            $out[$key]['skus']++;

            if ($factor === null || (float) $factor <= 0) {
                // Deliberately NOT counted as zero. It is stock whose size we do
                // not know, and a utilisation figure that pretends otherwise is
                // the kind of confident wrong number people plan around.
                $out[$key]['missing_data'] += $qty;
            } else {
                $out[$key]['measured'] += $qty * (float) $factor;
            }

            if (count($out[$key]['products']) < 8 && $row->product) {
                $out[$key]['products'][] = [
                    'id' => $row->product->id, 'sku' => $row->product->sku,
                    'name' => $row->product->name, 'quantity' => $qty,
                ];
            }
        }

        return $out;
    }

    private function totalsOf(array $tree, array $unlocated): array
    {
        $bins = $over = $empty = $unmeasured = 0;
        $used = $capacity = 0.0;

        $walk = function (array $nodes) use (&$walk, &$bins, &$over, &$empty, &$unmeasured, &$used, &$capacity) {
            foreach ($nodes as $n) {
                if (! $n['children']) {          // only leaves are storage places
                    $bins++;
                    if ($n['over']) {
                        $over++;
                    }
                    if ($n['quantity'] <= 0) {
                        $empty++;
                    }
                    if (! $n['comparable']) {
                        $unmeasured++;
                    } else {
                        $used += $n['used'];
                        $capacity += $n['capacity'];
                    }
                }
                $walk($n['children']);
            }
        };
        $walk($tree);

        return [
            'bins'          => $bins,
            'over_capacity' => $over,
            'empty'         => $empty,
            // Bins nobody has measured. Reported as their own number rather than
            // folded into the average, which would drag utilisation toward a
            // figure built out of unknowns.
            'unmeasured'    => $unmeasured,
            'used'          => round($used, 3),
            'capacity'      => round($capacity, 3),
            'utilisation'   => $capacity > 0 ? round(($used / $capacity) * 100, 1) : null,
            'unlocated_qty' => round($unlocated['qty'] ?? 0, 3),
        ];
    }

    /* ── What is wrong with this warehouse ──────────────────────── */

    /**
     * The housekeeping list. Ordered by what actually costs somebody time:
     * stock nobody can find, then bins that physically cannot hold what the app
     * says they hold, then rooms for improvement.
     */
    private function problems(int $warehouseId, int $tenantId, string $measure, array $tree, array $unlocated): array
    {
        $problems = [];

        if (($unlocated['qty'] ?? 0) > 0) {
            $problems[] = [
                'kind'   => 'unlocated',
                'count'  => 1,
                'detail' => $this->fmt($unlocated['qty']).' unit(s) across '.$unlocated['skus']
                    .' item(s) are at this warehouse with no bin recorded — the building says it has them and nobody can say where.',
            ];
        }

        $over = $mixed = [];
        $walk = function (array $nodes) use (&$walk, &$over, &$mixed) {
            foreach ($nodes as $n) {
                if (! $n['children']) {
                    if ($n['over']) {
                        $over[] = ($n['code'] ?: $n['name']).' — '.$this->fmt($n['used']).' of '.$this->fmt($n['capacity']);
                    }
                    // More than one SKU in one bin is not wrong, but it is where
                    // pick errors come from, so it is worth being able to see.
                    if ($n['skus'] > 1) {
                        $mixed[] = ($n['code'] ?: $n['name']).' — '.$n['skus'].' items';
                    }
                }
                $walk($n['children']);
            }
        };
        $walk($tree);

        if ($over) {
            $problems[] = [
                'kind'   => 'over_capacity',
                'count'  => count($over),
                'detail' => 'Holding more than they can take: '.implode('; ', array_slice($over, 0, 6))
                    .(count($over) > 6 ? ' …and '.(count($over) - 6).' more' : ''),
            ];
        }

        if ($mixed) {
            $problems[] = [
                'kind'   => 'mixed_bins',
                'count'  => count($mixed),
                'detail' => 'More than one item in one bin: '.implode('; ', array_slice($mixed, 0, 6))
                    .(count($mixed) > 6 ? ' …and '.(count($mixed) - 6).' more' : ''),
            ];
        }

        if ($measure !== 'units') {
            $missing = Product::forTenant($tenantId)->where('status', 'active')
                ->where('without_checking_warehouse', false)
                ->where(fn ($q) => $q->whereNull($measure)->orWhere($measure, '<=', 0))
                ->count();

            if ($missing > 0) {
                $problems[] = [
                    'kind'   => 'missing_product_data',
                    'count'  => $missing,
                    'detail' => "{$missing} item(s) have no {$measure} recorded, so their share of every shelf is unknown. "
                        .'Those quantities are excluded from the figures rather than counted as nothing.',
                ];
            }
        }

        return $problems;
    }

    /* ── Put-away ───────────────────────────────────────────────── */

    /**
     * Where should this go?
     *
     * Every suggestion says WHICH rule produced it, because a put-away hint
     * somebody cannot explain is one they will stop trusting the first time it
     * looks odd — and "same item, has room" and "emptiest bin" are decisions a
     * storekeeper would happily argue with if they knew which was in play.
     */
    public function suggestPutaway(int $productId, float $quantity, int $warehouseId, int $tenantId, string $measure = 'units'): array
    {
        $this->assertMeasure($measure);

        $product = Product::forTenant($tenantId)->find($productId)
            ?? throw new BusinessException('That item does not exist.', 404);

        if ($product->without_checking_warehouse) {
            throw new BusinessException('This item is set to not update inventory numbers, so it does not go on a shelf.', 422);
        }
        if ($quantity <= 0) {
            throw new BusinessException('Say how much is being put away.', 422);
        }

        $factor = match ($measure) {
            'volume' => (float) ($product->volume ?? 0),
            'weight' => (float) ($product->weight ?? 0),
            default  => 1.0,
        };
        $needs = $factor > 0 ? $quantity * $factor : null;

        $load = $this->loadByLocation($warehouseId, $tenantId, $measure);
        $locations = Location::forTenant($tenantId)->where('warehouse_id', $warehouseId)->get();
        $hasChildren = $locations->pluck('parent_id')->filter()->unique()->all();

        $options = [];

        foreach ($locations as $l) {
            // Only leaves are places you can physically put something.
            if (in_array($l->id, $hasChildren, true)) {
                continue;
            }

            $own = $load[$l->id] ?? ['qty' => 0, 'measured' => 0, 'products' => []];
            $holdsSame = collect($own['products'] ?? [])->contains(fn ($p) => (int) $p['id'] === $productId);

            $capacity = $l->capacity !== null ? (float) $l->capacity : null;
            $comparable = $capacity !== null && $l->capacity_uom === $measure;
            $free = $comparable ? $capacity - $own['measured'] : null;

            // A bin that provably cannot take it is not a suggestion.
            if ($needs !== null && $free !== null && $free + 0.0005 < $needs) {
                continue;
            }

            $options[] = [
                'location_id' => $l->id,
                'code'        => $l->code,
                'name'        => $l->name,
                'type'        => $l->type,
                'free'        => $free !== null ? round($free, 3) : null,
                'holds_same'  => $holdsSame,
                'empty'       => ($own['qty'] ?? 0) <= 0,
                'reason'      => $holdsSame
                    ? 'Already holds this item, and has room — one item in one place stays pickable'
                    : (($own['qty'] ?? 0) <= 0
                        ? 'Empty, so nothing gets mixed'
                        : ($free !== null ? 'Has room' : 'No capacity recorded, so this is a guess — measure the bin to be sure')),
                // Ranking, best first: consolidate with the same item, then an
                // empty bin, then the roomiest, and unmeasured bins last because
                // recommending one is really an admission we do not know.
                '_rank'       => [$holdsSame ? 0 : 1, ($own['qty'] ?? 0) <= 0 ? 0 : 1, $free === null ? 1 : 0, -($free ?? 0)],
            ];
        }

        usort($options, fn ($a, $b) => $a['_rank'] <=> $b['_rank']);
        $options = array_map(fn ($o) => array_diff_key($o, ['_rank' => null]), array_slice($options, 0, 8));

        return [
            'product'  => $product->only(['id', 'sku', 'name', 'base_unit']),
            'quantity' => $quantity,
            'measure'  => $measure,
            // Honest when it cannot do the arithmetic at all.
            'measurable' => $needs !== null,
            'note'     => $needs === null && $measure !== 'units'
                ? "This item has no {$measure} recorded, so nothing here can be checked against a bin's limit — these are suggestions by location only."
                : null,
            'options'  => $options,
        ];
    }

    /* ── The daily housekeeping alert ───────────────────────────── */

    /**
     * Bins over their limit, across every warehouse in a tenant.
     *
     * Daily rather than edge-triggered, unlike low stock: a full shelf is a
     * housekeeping job, not an incident, and recomputing every bin's load on
     * every movement would cost far more than the warning is worth.
     */
    public function capacityAlerts(int $tenantId): array
    {
        $out = [];

        foreach (Warehouse::forTenant($tenantId)->where('status', 'active')->get() as $wh) {
            if ($wh->type === 'transit') {
                continue;   // nothing is stored there; it is a state, not a room
            }

            $layout = $this->forWarehouse($wh->id, $tenantId, 'units');
            $over = [];

            $walk = function (array $nodes) use (&$walk, &$over) {
                foreach ($nodes as $n) {
                    if (! $n['children'] && $n['over']) {
                        $over[] = [
                            'code'        => $n['code'] ?: $n['name'],
                            'used'        => $n['used'],
                            'capacity'    => $n['capacity'],
                            'utilisation' => $n['utilisation'],
                        ];
                    }
                    $walk($n['children']);
                }
            };
            $walk($layout['tree']);

            if ($over || $layout['totals']['unlocated_qty'] > 0) {
                $out[] = [
                    'warehouse' => $wh->only(['id', 'name', 'code']),
                    'over'      => $over,
                    'unlocated' => $layout['totals']['unlocated_qty'],
                ];
            }
        }

        return $out;
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function assertMeasure(string $measure): void
    {
        if (! in_array($measure, Location::CAPACITY_UOMS, true)) {
            throw new BusinessException('A bin can be measured by units, volume or weight.', 422);
        }
    }

    private function fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
    }
}
