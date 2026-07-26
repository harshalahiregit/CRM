<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\CountLine;
use App\Models\Inventory\CountSession;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Cycle counting and physical verification (§12).
 *
 * This is the only place in the module where the app is corrected BY the
 * building rather than by another screen. Everything else is bookkeeping about
 * bookkeeping; a count is the one moment somebody walks to a shelf and says what
 * is actually on it.
 *
 * Four rules the rest of the module leans on:
 *
 *  1. NOTHING MOVES UNTIL A SECOND PERSON AGREES. Counting writes to the count
 *     sheet, never to stock. Approval is what posts the corrections, and it goes
 *     through StockService like every other adjustment — so the ledger keeps its
 *     single rule and "why is this 7?" answers with a count code and a photo.
 *
 *  2. THE VARIANCE IS MEASURED AT COUNT TIME, not at raise time. The ledger
 *     figure is re-snapshotted the instant a counter types their number
 *     (`system_at_count`). A delivery posted legitimately between raising the
 *     sheet and walking it is then not mistaken for a counting error, and a
 *     movement posted AFTER the count provably happened after — so it survives
 *     the correction instead of being flattened by it.
 *
 *  3. THE CORRECTION IS A DELTA, NOT AN OVERWRITE. Approval posts
 *     `current + (counted − system_at_count)`, so anything that legitimately
 *     moved while the sheet sat waiting for a signature is preserved.
 *
 *  4. THE COUNTER CANNOT APPROVE THEIR OWN COUNT. Same reasoning as the voucher
 *     approval gate: a verification you sign off yourself has verified nothing.
 */
class CountService
{
    /** Anything smaller than this is float noise, not a variance. */
    private const EPSILON = 0.0005;

    public function __construct(
        private StockService $stock,
        private InventoryNotifier $notifier,
    ) {
    }

    /* ── Reading ────────────────────────────────────────────────── */

    public function list(int $tenantId, array $f = []): array
    {
        $q = CountSession::forTenant($tenantId)
            ->with(['warehouse:id,name', 'counter:id,name', 'creator:id,name', 'approver:id,name'])
            ->withCount([
                'lines',
                'lines as counted_lines_count' => fn ($w) => $w->whereNotNull('counted_qty'),
                'lines as variance_lines_count' => fn ($w) => $w->where('status', 'variance'),
            ]);

        foreach (['status', 'warehouse_id', 'scope', 'assigned_to'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }

        // A counter's own queue: sheets on their name that still need walking.
        if (! empty($f['mine'])) {
            $q->where('assigned_to', $f['mine'])->whereNotIn('status', CountSession::CLOSED);
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)->orWhere('name', 'like', $s));
        }

        return $q->latest('id')->get()->all();
    }

    public function show(int $id, int $tenantId): CountSession
    {
        return CountSession::forTenant($tenantId)
            ->with([
                'lines.product:id,sku,name,base_unit,barcode,cost_price',
                'lines.location:id,name,code,type',
                'lines.counter:id,name',
                'warehouse:id,name', 'counter:id,name', 'creator:id,name', 'approver:id,name',
            ])
            ->find($id) ?? throw new BusinessException('That count does not exist.', 404);
    }

    /**
     * Hide the expected figures from the person doing the walking.
     *
     * The whole value of a blind count is that the counter has nothing to agree
     * with. Shown the system figure, a tired person at the end of a shift
     * confirms it — and a count that only ever matches the ledger has verified
     * nothing at all. Lifted the moment the sheet is submitted, because from
     * then on the figures are locked and the supervisor needs to see both.
     */
    public function applyBlindness(CountSession $session, int $viewerId): CountSession
    {
        $hide = $session->blind
            && in_array($session->status, ['draft', 'counting'], true)
            && (int) $session->assigned_to === $viewerId;

        if (! $hide) {
            return $session;
        }

        $session->setRelation('lines', $session->lines->map(function (CountLine $l) {
            $l->system_qty = null;
            $l->system_at_count = null;
            $l->variance = null;

            return $l;
        }));

        $session->blind_hidden = true;

        return $session;
    }

    /* ── Raising a sheet ────────────────────────────────────────── */

    /**
     * Freeze a scope of stock into lines somebody can walk.
     *
     * One line per place a thing sits, not per product — a counter sent to
     * "8 units of X somewhere in this building" cannot count anything.
     */
    public function create(array $data, int $tenantId, int $userId): CountSession
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if (! $warehouseId || ! Warehouse::forTenant($tenantId)->whereKey($warehouseId)->exists()) {
            throw new BusinessException('Say which warehouse is being counted.', 404);
        }

        $scope = $data['scope'] ?? 'full';
        if (! in_array($scope, CountSession::SCOPES, true)) {
            throw new BusinessException('Unknown count scope.', 422);
        }

        $rows = $this->rowsForScope($scope, $data, $warehouseId, $tenantId);
        if (! $rows) {
            throw new BusinessException('Nothing to count — that scope has no stock at this warehouse.', 422);
        }

        $session = DB::transaction(function () use ($data, $scope, $rows, $warehouseId, $tenantId, $userId) {
            $session = CountSession::create([
                'tenant_id'    => $tenantId,
                'code'         => $this->nextCode($tenantId),
                'name'         => $data['name'] ?? null,
                'warehouse_id' => $warehouseId,
                'scope'        => $scope,
                'scope_ref'    => $this->scopeRef($scope, $data),
                'status'       => 'draft',
                // Blind unless someone deliberately turns it off.
                'blind'        => array_key_exists('blind', $data) ? (bool) $data['blind'] : true,
                'assigned_to'  => $data['assigned_to'] ?? null,
                'note'         => $data['note'] ?? null,
                // 0 is how the scheduler says "no person raised this".
                'created_by'   => $userId ?: null,
            ]);

            foreach ($rows as $row) {
                $session->lines()->create([
                    'tenant_id'   => $tenantId,
                    'product_id'  => $row['product_id'],
                    'location_id' => $row['location_id'],
                    'system_qty'  => $row['quantity'],
                    'status'      => 'pending',
                ]);
            }

            return $session;
        });

        // Assigning at creation is the ordinary path — the person raising the
        // sheet usually knows who is walking it. Telling them only when assign()
        // is called separately would leave the common case silent.
        if ($session->assigned_to) {
            $this->notifier->countAssigned($session->fresh(), (int) $session->assigned_to, $userId);
        }

        return $session;
    }

    public function assign(int $id, ?int $userId, int $tenantId, int $actorId): CountSession
    {
        $session = $this->find($id, $tenantId);
        $this->assertOpen($session);

        $session->forceFill(['assigned_to' => $userId])->save();

        if ($userId) {
            $this->notifier->countAssigned($session->fresh(), $userId, $actorId);
        }

        return $this->show($id, $tenantId);
    }

    /**
     * A counter found something that isn't on the sheet.
     *
     * Without this the whole exercise is one-sided: a full count can only ever
     * discover stock that is MISSING, because a product the ledger thinks is
     * nowhere never gets a line. Found stock joins the sheet at a system figure
     * of zero, which is exactly what the ledger is claiming.
     */
    public function addLine(int $id, array $data, int $tenantId, int $actorId): CountLine
    {
        $session = $this->find($id, $tenantId);
        $this->assertOpen($session);

        $productId = (int) ($data['product_id'] ?? 0);
        $product = Product::forTenant($tenantId)->find($productId)
            ?? throw new BusinessException('That item does not exist.', 404);

        if ($product->without_checking_warehouse) {
            throw new BusinessException('This item is set to not update inventory numbers, so it has no stock to count.', 422);
        }

        $locationId = $data['location_id'] ?? null;

        $exists = $session->lines()
            ->where('product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId), fn ($q) => $q->whereNull('location_id'))
            ->exists();

        if ($exists) {
            throw new BusinessException('That item is already on this count sheet.', 422);
        }

        return $session->lines()->create([
            'tenant_id'   => $tenantId,
            'product_id'  => $productId,
            'location_id' => $locationId,
            'system_qty'  => $this->onHandAt($productId, $session->warehouse_id, $locationId, $tenantId),
            'status'      => 'pending',
            'note'        => $data['note'] ?? 'Added during the count',
        ]);
    }

    /* ── Counting ───────────────────────────────────────────────── */

    /**
     * Record what was physically on the shelf.
     *
     * @param  array  $lines  [['id'=>n,'counted_qty'=>n,'note'=>?,'gps_lat'=>?,'gps_lng'=>?,...], ...]
     */
    public function count(int $id, array $lines, int $tenantId, int $actorId): CountSession
    {
        $session = $this->find($id, $tenantId);
        $this->assertOpen($session);

        $known = $session->lines()->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $session, $tenantId, $actorId) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this count sheet.', 422);

                $counted = round((float) ($row['counted_qty'] ?? 0), 3);
                if ($counted < 0) {
                    throw new BusinessException('A counted quantity cannot be negative — you cannot find less than none.', 422);
                }

                $this->record($session, $line, $counted, $tenantId, $actorId, $row, false);
            }

            if ($session->status === 'draft') {
                $session->forceFill(['status' => 'counting', 'started_at' => now()])->save();
            }
        });

        return $this->show($id, $tenantId);
    }

    /**
     * Go back and look again at a line that disagreed.
     *
     * Most variances are counting mistakes rather than stock mistakes, so the
     * recount is what gets posted — and a variance that survives a second look
     * is the only kind worth putting in front of a supervisor.
     */
    public function recount(int $id, array $lines, int $tenantId, int $actorId): CountSession
    {
        $session = $this->find($id, $tenantId);
        $this->assertOpen($session);

        $known = $session->lines()->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $session, $tenantId, $actorId) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this count sheet.', 422);

                if (! $line->isCounted()) {
                    throw new BusinessException('Count it once before recounting it.', 422);
                }

                $qty = round((float) ($row['recount_qty'] ?? 0), 3);
                if ($qty < 0) {
                    throw new BusinessException('A recounted quantity cannot be negative.', 422);
                }

                $this->record($session, $line, $qty, $tenantId, $actorId, $row, true);
            }
        });

        return $this->show($id, $tenantId);
    }

    /**
     * Write one physical figure onto a line, re-snapshotting the ledger at that
     * instant. Shared by count and recount so the two can never drift apart in
     * how they measure a variance.
     */
    private function record(CountSession $session, CountLine $line, float $qty, int $tenantId, int $actorId, array $row, bool $isRecount): void
    {
        $system = $this->onHandAt((int) $line->product_id, (int) $session->warehouse_id, $line->location_id ? (int) $line->location_id : null, $tenantId);
        $variance = round($qty - $system, 3);
        $matched = abs($variance) < self::EPSILON;

        $line->forceFill([
            ($isRecount ? 'recount_qty' : 'counted_qty') => $qty,
            'system_at_count' => $system,
            'variance'        => $variance,
            'status'          => $matched ? 'matched' : ($isRecount ? 'recounted' : 'variance'),
            'counted_by'      => $actorId,
            'counted_at'      => now(),
            'note'            => $row['note'] ?? $line->note,
            // Evidence, when the device offered it. GPS says the counter was at
            // the site; a photo says what the shelf looked like. Neither is
            // required — a warehouse with no smartphones still has to count.
            'gps_lat'         => $row['gps_lat'] ?? $line->gps_lat,
            'gps_lng'         => $row['gps_lng'] ?? $line->gps_lng,
            'gps_accuracy'    => $row['gps_accuracy'] ?? $line->gps_accuracy,
            'device'          => $row['device'] ?? $line->device,
        ])->save();
    }

    /* ── Submitting and approving ───────────────────────────────── */

    /** Hand the finished sheet to whoever signs it off. */
    public function submit(int $id, int $tenantId, int $actorId): CountSession
    {
        $session = $this->find($id, $tenantId);
        $this->assertOpen($session);

        $pending = $session->lines()->whereNull('counted_qty')->count();
        if ($pending > 0) {
            // A null count is "nobody has been yet", not "there were none".
            // Approving with holes would post those lines as zero and write off
            // stock that is sitting on the shelf untouched.
            throw new BusinessException(
                "{$pending} line(s) have not been counted yet. Count them, or remove them from the sheet.",
                422
            );
        }

        $session->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();

        $fresh = $this->show($id, $tenantId);
        $this->notifier->countSubmitted($fresh, $actorId, $this->variance($fresh));

        return $fresh;
    }

    /**
     * Sign it off. THIS is the moment the corrections reach the ledger.
     *
     * Each variance is posted as an ordinary adjustment through StockService, so
     * a correction is indistinguishable from any other movement in the audit
     * trail except that it points back at the count that produced it.
     */
    public function approve(int $id, int $tenantId, int $userId): CountSession
    {
        $session = $this->find($id, $tenantId);

        if ($session->status !== 'pending_approval') {
            throw new BusinessException('This count is not waiting for approval.', 422);
        }
        $this->assertNotOwnWork($session, $userId);

        $lines = $session->lines()->with('product:id,name,cost_price')->get();
        $value = 0.0;

        DB::transaction(function () use ($session, $lines, $tenantId, $userId, &$value) {
            foreach ($lines as $line) {
                $final = $line->finalQuantity();
                if ($final === null) {
                    continue;
                }

                $system = (float) ($line->system_at_count ?? $line->system_qty);
                $delta = round($final - $system, 3);

                if (abs($delta) < self::EPSILON) {
                    continue;
                }

                // A DELTA, not an overwrite. Whatever legitimately moved while
                // the sheet waited for a signature happened after the counter
                // looked, so it is real and has to survive the correction.
                // Overwriting with the counted figure would silently undo it.
                $current = $this->onHandAt($line->product_id, $session->warehouse_id, $line->location_id, $tenantId);
                $target = round($current + $delta, 3);

                // StockService refuses a negative balance, and it should: if
                // more left than was ever counted, the sheet is wrong and the
                // whole approval rolls back rather than half-posting.
                $movement = $this->stock->adjustTo(
                    (int) $line->product_id,
                    (int) $session->warehouse_id,
                    $target,
                    $tenantId,
                    $userId,
                    "Physical count {$session->code}",
                    $line->location_id ? (int) $line->location_id : null,
                    ['reference_type' => 'count_session', 'reference_id' => $session->id],
                );

                $line->forceFill(['movement_id' => $movement?->id])->save();
                $value += $delta * (float) ($line->product->cost_price ?? 0);
            }

            $session->forceFill([
                'status'         => 'approved',
                'approved_by'    => $userId,
                'approved_at'    => now(),
                // Frozen here. Recomputing it later from today's cost price
                // would quietly rewrite what the variance was worth.
                'variance_value' => round($value, 2),
            ])->save();
        });

        $fresh = $this->show($id, $tenantId);
        $this->notifier->countDecided($fresh, $userId, true, null);

        return $fresh;
    }

    /** Send it back to be walked again, with a reason. */
    public function reject(int $id, int $tenantId, int $userId, string $reason): CountSession
    {
        $session = $this->find($id, $tenantId);

        if ($session->status !== 'pending_approval') {
            throw new BusinessException('This count is not waiting for approval.', 422);
        }
        $this->assertNotOwnWork($session, $userId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('Say why — "recount it" on its own tells the counter nothing.', 422);
        }

        $session->forceFill([
            'status'           => 'counting',
            'rejected_by'      => $userId,
            'rejected_at'      => now(),
            'rejection_reason' => mb_substr($reason, 0, 1000),
            'submitted_at'     => null,
        ])->save();

        $fresh = $this->show($id, $tenantId);
        $this->notifier->countDecided($fresh, $userId, false, $reason);

        return $fresh;
    }

    /** Abandon the sheet. Nothing was posted, so nothing has to be undone. */
    public function cancel(int $id, int $tenantId, int $actorId): CountSession
    {
        $session = $this->find($id, $tenantId);

        if ($session->status === 'approved') {
            throw new BusinessException('This count has been approved and its corrections are in the ledger — it cannot be cancelled.', 422);
        }
        if ($session->status === 'cancelled') {
            throw new BusinessException('This count is already cancelled.', 422);
        }

        $session->forceFill(['status' => 'cancelled'])->save();

        return $this->show($id, $tenantId);
    }

    /* ── Variance ───────────────────────────────────────────────── */

    /**
     * What the sheet found. Accuracy is the number a warehouse is actually
     * judged on: of the places somebody looked, how many agreed with the app.
     */
    public function variance(CountSession $session): array
    {
        $lines = $session->relationLoaded('lines')
            ? $session->lines
            : $session->lines()->with('product:id,sku,name,cost_price')->get();

        $counted = $lines->filter(fn (CountLine $l) => $l->isCounted());
        $off = $counted->filter(fn (CountLine $l) => abs((float) $l->variance) >= self::EPSILON);

        $gain = $loss = $value = 0.0;
        $rows = [];

        foreach ($off as $l) {
            $v = (float) $l->variance;
            $cost = (float) ($l->product->cost_price ?? 0);
            $v > 0 ? $gain += $v : $loss += abs($v);
            $value += $v * $cost;

            $rows[] = [
                'line_id'  => $l->id,
                'product'  => $l->product->name ?? 'Item',
                'sku'      => $l->product->sku ?? null,
                'system'   => (float) ($l->system_at_count ?? $l->system_qty),
                'counted'  => $l->finalQuantity(),
                'variance' => round($v, 3),
                'value'    => round($v * $cost, 2),
                'recounted' => $l->recount_qty !== null,
                'evidence' => (bool) ($l->photo_path || $l->gps_lat),
            ];
        }

        usort($rows, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));

        return [
            'lines'         => $lines->count(),
            'counted'       => $counted->count(),
            'pending'       => $lines->count() - $counted->count(),
            'matched'       => $counted->count() - $off->count(),
            'variances'     => $off->count(),
            // Of the places somebody physically looked, how many agreed.
            'accuracy_pct'  => $counted->count() > 0
                ? round((($counted->count() - $off->count()) / $counted->count()) * 100, 1)
                : null,
            'qty_gain'      => round($gain, 3),
            'qty_loss'      => round($loss, 3),
            'variance_value' => round($value, 2),
            'rows'          => array_slice($rows, 0, 50),
        ];
    }

    /* ── Scope building ─────────────────────────────────────────── */

    /**
     * Turn a scope into the places somebody has to walk to.
     *
     * @return array<int,array{product_id:int,location_id:?int,quantity:float}>
     */
    private function rowsForScope(string $scope, array $data, int $warehouseId, int $tenantId): array
    {
        $q = Stock::forTenant($tenantId)
            ->where('warehouse_id', $warehouseId)
            // Items excluded from stock math have no balance to verify.
            ->whereHas('product', fn ($p) => $p->where('without_checking_warehouse', false)->where('status', 'active'));

        match ($scope) {
            'location' => $q->whereIn('location_id', $this->ids($data, 'location_ids')),
            'category' => $q->whereHas('product', fn ($p) => $p->whereIn('category_id', $this->ids($data, 'category_ids'))),
            'product'  => $q->whereIn('product_id', $this->ids($data, 'product_ids')),
            'abc'      => $q->whereIn('product_id', $this->abcProducts($data['abc_class'] ?? 'A', $tenantId, $warehouseId)),
            default    => null,
        };

        $rows = $q->get(['product_id', 'location_id', 'quantity'])
            ->map(fn (Stock $s) => [
                'product_id'  => (int) $s->product_id,
                'location_id' => $s->location_id ? (int) $s->location_id : null,
                'quantity'    => (float) $s->quantity,
            ])->values()->all();

        // A random sample is the cheapest honest cycle count there is: nobody can
        // predict which shelf gets looked at, so nobody can keep one tidy for the
        // audit and let the rest rot.
        if ($scope === 'random') {
            $size = max(1, min((int) ($data['sample_size'] ?? 20), 200));
            shuffle($rows);
            $rows = array_slice($rows, 0, $size);
        }

        return $rows;
    }

    /**
     * The products worth counting most often, by Pareto on consumption value
     * over the last 90 days — same split the analytics screen uses (A = the
     * items making up the first 80% of value, B to 95%, C the tail).
     *
     * Quantity would be the wrong measure: a thousand washers moving is not the
     * same business as three engines, and it is the engines you count weekly.
     *
     * @return int[]
     */
    private function abcProducts(string $class, int $tenantId, int $warehouseId): array
    {
        $class = strtoupper($class);
        if (! in_array($class, ['A', 'B', 'C'], true)) {
            throw new BusinessException('ABC class must be A, B or C.', 422);
        }

        $costs = Product::forTenant($tenantId)->pluck('cost_price', 'id')->all();

        $out = Movement::forTenant($tenantId)
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subDays(90))
            ->where(fn ($w) => $w->where('from_warehouse_id', $warehouseId)->orWhereNull('from_warehouse_id'))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')->all();

        $scored = [];
        foreach ($out as $pid => $qty) {
            $value = (float) $qty * (float) ($costs[$pid] ?? 0);
            if ($value > 0) {
                $scored[] = ['id' => (int) $pid, 'value' => $value];
            }
        }

        if (! $scored) {
            // No consumption history yet. Refusing is kinder than silently
            // counting nothing and leaving somebody staring at an empty sheet.
            throw new BusinessException('There is no consumption history yet, so items cannot be ranked A/B/C. Use another scope for now.', 422);
        }

        usort($scored, fn ($a, $b) => $b['value'] <=> $a['value']);
        $total = array_sum(array_column($scored, 'value'));

        $ids = [];
        $cum = 0;
        foreach ($scored as $s) {
            $cum += $s['value'];
            $share = $total > 0 ? $cum / $total : 1;
            $band = $share <= 0.8 ? 'A' : ($share <= 0.95 ? 'B' : 'C');
            if ($band === $class) {
                $ids[] = $s['id'];
            }
        }

        return $ids ?: [0];
    }

    private function ids(array $data, string $key): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($data[$key] ?? []))));

        if (! $ids) {
            throw new BusinessException('Choose at least one '.str_replace('_ids', '', $key).' to count.', 422);
        }

        return $ids;
    }

    private function scopeRef(string $scope, array $data): ?array
    {
        return match ($scope) {
            'location' => ['location_ids' => $this->ids($data, 'location_ids')],
            'category' => ['category_ids' => $this->ids($data, 'category_ids')],
            'product'  => ['product_ids'  => $this->ids($data, 'product_ids')],
            'abc'      => ['abc_class'    => strtoupper($data['abc_class'] ?? 'A')],
            'random'   => ['sample_size'  => (int) ($data['sample_size'] ?? 20)],
            default    => null,
        };
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /** What the ledger says is in one place, right now. */
    private function onHandAt(int $productId, int $warehouseId, ?int $locationId, int $tenantId): float
    {
        $q = Stock::forTenant($tenantId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        $q = $locationId ? $q->where('location_id', $locationId) : $q->whereNull('location_id');

        return (float) ($q->value('quantity') ?? 0);
    }

    /**
     * The person who walked the shelves cannot be the person who signs it off.
     * A verification you approve yourself has verified nothing — the same reason
     * a voucher's raiser cannot approve their own document.
     */
    private function assertNotOwnWork(CountSession $session, int $userId): void
    {
        $walkers = $session->lines()->whereNotNull('counted_by')->distinct()->pluck('counted_by')
            ->map(fn ($i) => (int) $i)->all();

        if ((int) $session->assigned_to === $userId || in_array($userId, $walkers, true)) {
            throw new BusinessException(
                'You did the counting, so you cannot approve it. Ask an admin or the warehouse manager.',
                403
            );
        }
    }

    private function assertOpen(CountSession $session): void
    {
        if ($session->isClosed()) {
            throw new BusinessException('This count is '.$session->status.' — it can no longer be changed.', 422);
        }
        if ($session->status === 'pending_approval') {
            throw new BusinessException('This count is waiting for approval. It has to be sent back before it can be changed.', 422);
        }
    }

    public function find(int $id, int $tenantId): CountSession
    {
        return CountSession::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That count does not exist.', 404);
    }

    private function nextCode(int $tenantId): string
    {
        $n = CountSession::withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        do {
            $code = 'COUNT-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (CountSession::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
