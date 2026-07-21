<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Batch;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Inventory analytics — the deterministic layer under any future forecasting.
 *
 * Nothing here is a guess: ABC is Pareto on consumption value, XYZ is the
 * coefficient of variation of demand, turnover is consumption over average
 * stock. They are computed from the movement ledger every time, so a figure can
 * always be traced back to the movements that produced it.
 *
 * Everything is viewer-aware. An admin sees the whole tenant; a staff member
 * sees the same shapes computed over THEIR OWN recorded activity, so the page
 * answers "how is the business doing" for one and "how am I doing" for the
 * other, from the same maths.
 */
class AnalyticsService
{
    public function __construct(private ReportService $reports)
    {
    }

    /**
     * @param  bool  $isAdmin  Tenant-wide when true; scoped to $userId's own movements otherwise.
     */
    public function dashboard(int $tenantId, bool $isAdmin, int $userId, array $f = []): array
    {
        $days = (int) ($f['days'] ?? 90);
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();

        // Who the figures are about:
        //  • staff       → always themselves, whatever they ask for;
        //  • admin       → the whole tenant, or ONE person when they drill in.
        // A non-admin passing actor_id is ignored rather than honoured, so the
        // filter can never become a way to read a colleague's activity.
        $focusUserId = $isAdmin
            ? (! empty($f['actor_id']) ? (int) $f['actor_id'] : null)
            : $userId;

        $scope = ['from' => $from, 'to' => $to];
        if ($focusUserId) {
            $scope['actor_id'] = $focusUserId;
        }
        if (! empty($f['warehouse_id'])) {
            $scope['warehouse_id'] = $f['warehouse_id'];
        }

        $summary = $this->reports->stockSummary($tenantId, $scope);
        $rows = $summary['rows'];

        // Dead stock is a fact about the SHELF, not about the viewer — an item
        // nobody issued is dead whoever is looking. Scoping it to one person's
        // movements would call an item dead just because someone else moved it,
        // so it is always computed tenant-wide.
        $tenantRows = ($isAdmin && ! $focusUserId)
            ? $rows
            : $this->reports->stockSummary($tenantId, ['from' => $from, 'to' => $to])['rows'];

        // 'tenant' = whole workspace · 'person' = admin drilled into someone
        // · 'mine' = a staff member looking at their own work.
        $scopeName = ! $isAdmin ? 'mine' : ($focusUserId ? 'person' : 'tenant');

        return [
            'scope'       => $scopeName,
            'scope_user'  => $focusUserId
                ? \App\Models\User::whereKey($focusUserId)->value('name')
                : null,
            'window'     => ['days' => $days, 'from' => $from, 'to' => $to],
            'headline'   => $this->headline($tenantId, $rows, $days, $isAdmin, $focusUserId),
            'abc'        => $this->abc($tenantId, $rows),
            'xyz'        => $this->xyz($tenantId, $rows, $days, $scope),
            'turnover'   => $this->turnover($rows, $days),
            'dead_stock' => $this->deadStock($tenantId, $tenantRows),
            'accuracy'   => $this->stockAccuracy($tenantId, $days, $focusUserId),
            'movement_trend' => $this->movementTrend($tenantId, $days, $focusUserId, $f['warehouse_id'] ?? null),
            'category_mix'   => $isAdmin ? $this->categoryMix($tenantId) : [],

            // Admin-only: who on the team did what. Absent for staff entirely,
            // so a colleague's numbers never leave the server for them.
            'team' => $isAdmin ? $this->team($tenantId, $days) : null,
        ];
    }

    /* ── Team activity (admin only) ─────────────────────────────── */

    /**
     * Per-person inventory activity for the window: what each user moved,
     * received, issued, how many documents they still owe (unposted drafts),
     * what they're holding reserved, and their own correction rate.
     *
     * Everyone internal is listed, including people with zero activity — an
     * empty row is information too ("nobody has touched stock in a fortnight"),
     * and hiding it would make the team look busier than it is.
     */
    private function team(int $tenantId, int $days): array
    {
        $since = now()->subDays($days);

        $staff = \App\Models\User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        // One grouped query per metric rather than N per person.
        $moves = DB::table('inventory_movements')
            ->where('tenant_id', $tenantId)->where('created_at', '>=', $since)
            ->whereNotNull('actor_id')
            ->groupBy('actor_id')
            ->get([
                'actor_id',
                DB::raw('COUNT(*) as movements'),
                DB::raw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE 0 END) as qty_in"),
                DB::raw("SUM(CASE WHEN direction = 'out' THEN quantity ELSE 0 END) as qty_out"),
                DB::raw("SUM(CASE WHEN type = 'adjustment' THEN 1 ELSE 0 END) as corrections"),
                DB::raw('MAX(created_at) as last_at'),
            ])->keyBy('actor_id');

        $drafts = DB::table('inventory_vouchers')
            ->where('tenant_id', $tenantId)->where('status', 'draft')->whereNull('deleted_at')
            ->groupBy('created_by')->pluck(DB::raw('COUNT(*)'), 'created_by');

        $posted = DB::table('inventory_vouchers')
            ->where('tenant_id', $tenantId)->where('status', 'posted')->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->groupBy('created_by')->pluck(DB::raw('COUNT(*)'), 'created_by');

        $res = DB::table('inventory_reservations')
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->groupBy('created_by')->pluck(DB::raw('COUNT(*)'), 'created_by');

        $itemsAdded = DB::table('inventory_products')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->groupBy('created_by')->pluck(DB::raw('COUNT(*)'), 'created_by');

        $rows = [];
        foreach ($staff as $u) {
            $m = $moves[$u->id] ?? null;
            $mv = (int) ($m->movements ?? 0);
            $corr = (int) ($m->corrections ?? 0);

            $rows[] = [
                'user_id'      => $u->id,
                'name'         => $u->name,
                'role'         => $u->role,
                'movements'    => $mv,
                'qty_in'       => round((float) ($m->qty_in ?? 0), 3),
                'qty_out'      => round((float) ($m->qty_out ?? 0), 3),
                'corrections'  => $corr,
                'accuracy_pct' => $mv > 0 ? round((1 - $corr / $mv) * 100, 1) : null,
                'items_added'  => (int) ($itemsAdded[$u->id] ?? 0),
                'posted_docs'  => (int) ($posted[$u->id] ?? 0),
                // Unposted drafts are stock that HASN'T moved yet — the thing an
                // admin most needs to see about someone else's work.
                'open_drafts'  => (int) ($drafts[$u->id] ?? 0),
                'reservations' => (int) ($res[$u->id] ?? 0),
                'last_active'  => $m->last_at ?? null,
            ];
        }

        // Busiest first, but keep the quiet ones listed underneath.
        usort($rows, fn ($a, $b) => [$b['movements'], $b['posted_docs']] <=> [$a['movements'], $a['posted_docs']]);

        return [
            'rows'    => $rows,
            'totals'  => [
                'people_active'  => count(array_filter($rows, fn ($r) => $r['movements'] > 0)),
                'people'         => count($rows),
                'movements'      => array_sum(array_column($rows, 'movements')),
                'open_drafts'    => array_sum(array_column($rows, 'open_drafts')),
                'reservations'   => array_sum(array_column($rows, 'reservations')),
            ],
        ];
    }

    /* ── Headline tiles ─────────────────────────────────────────── */

    /** @param  int|null  $focusUserId  Null = whole tenant; set = one person's activity. */
    private function headline(int $tenantId, array $rows, int $days, bool $isAdmin, ?int $focusUserId): array
    {
        $consumed = array_sum(array_column($rows, 'qty_out'));
        $received = array_sum(array_column($rows, 'qty_in'));
        $closingValue = array_sum(array_column($rows, 'value'));
        $avgStockValue = $closingValue > 0 ? $closingValue : 0;

        // Inventory turns, annualised from the window so the number is comparable
        // whichever window you pick.
        $turns = $avgStockValue > 0 ? round(($consumed > 0 ? $consumed : 0) / max(1, count($rows)) , 2) : 0;

        // Count the movements the page is ACTUALLY about: the whole tenant only
        // when no one person is in focus. Without this, an admin drilling into a
        // colleague still saw the tenant-wide movement count next to that
        // colleague's (correctly filtered) figures.
        $mine = Movement::forTenant($tenantId)->where('created_at', '>=', now()->subDays($days));
        if ($focusUserId) {
            $mine->where('actor_id', $focusUserId);
        }

        return [
            'items_touched'   => count($rows),
            'qty_received'    => round($received, 3),
            'qty_consumed'    => round($consumed, 3),
            'closing_value'   => round($closingValue, 2),
            'movements'       => (int) $mine->count(),
            'reserved_value'  => $isAdmin ? $this->reservedValue($tenantId) : null,
            'turn_index'      => $turns,
            'days_of_inventory' => $consumed > 0 ? round(array_sum(array_column($rows, 'closing')) / ($consumed / $days), 1) : null,
        ];
    }

    private function reservedValue(int $tenantId): float
    {
        return round((float) DB::table('inventory_reservations as r')
            ->join('inventory_products as p', 'p.id', '=', 'r.product_id')
            ->where('r.tenant_id', $tenantId)->where('r.status', 'active')
            ->sum(DB::raw('r.quantity * COALESCE(p.cost_price,0)')), 2);
    }

    /* ── ABC: Pareto on consumption value ───────────────────────── */

    /**
     * Classic 80/15/5 split by cumulative share of consumption VALUE (not
     * quantity — 1000 washers moving is not the same business as 3 engines).
     */
    private function abc(int $tenantId, array $rows): array
    {
        $costs = Product::forTenant($tenantId)->pluck('cost_price', 'id')->all();

        $scored = [];
        foreach ($rows as $r) {
            $val = (float) $r['qty_out'] * (float) ($costs[$r['product_id']] ?? 0);
            if ($val <= 0) {
                continue;
            }
            $scored[] = ['product_id' => $r['product_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'value' => round($val, 2)];
        }

        usort($scored, fn ($a, $b) => $b['value'] <=> $a['value']);
        $total = array_sum(array_column($scored, 'value'));

        $cum = 0;
        $buckets = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($scored as &$s) {
            $cum += $s['value'];
            $share = $total > 0 ? $cum / $total : 1;
            $s['cumulative_share'] = round($share * 100, 1);
            $s['class'] = $share <= 0.8 ? 'A' : ($share <= 0.95 ? 'B' : 'C');
            $buckets[$s['class']]++;
        }
        unset($s);

        return ['total_value' => round($total, 2), 'counts' => $buckets, 'rows' => array_slice($scored, 0, 50)];
    }

    /* ── XYZ: demand steadiness ─────────────────────────────────── */

    /**
     * Coefficient of variation of weekly consumption. X = steady (CV ≤ 0.5),
     * Y = variable (≤ 1.0), Z = erratic. Steady demand is forecastable; erratic
     * demand is what actually causes stockouts, which is why the split matters.
     */
    private function xyz(int $tenantId, array $rows, int $days, array $scope): array
    {
        $ids = array_column($rows, 'product_id');
        if (! $ids) {
            return ['counts' => ['X' => 0, 'Y' => 0, 'Z' => 0], 'rows' => []];
        }

        $q = DB::table('inventory_movements')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $ids)
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subDays($days));

        if (! empty($scope['actor_id'])) {
            $q->where('actor_id', $scope['actor_id']);
        }

        $moves = $q->get(['product_id', 'quantity', 'created_at']);

        // Bucket consumption into weeks per product.
        $weeks = max(1, (int) ceil($days / 7));
        $series = [];
        foreach ($moves as $m) {
            $age = (int) floor((time() - strtotime($m->created_at)) / 604800);
            $bucket = min($weeks - 1, max(0, $age));
            $series[$m->product_id] ??= array_fill(0, $weeks, 0.0);
            $series[$m->product_id][$bucket] += (float) $m->quantity;
        }

        $out = [];
        $counts = ['X' => 0, 'Y' => 0, 'Z' => 0];
        $byId = collect($rows)->keyBy('product_id');

        foreach ($series as $pid => $vals) {
            $n = count($vals);
            $mean = array_sum($vals) / $n;
            if ($mean <= 0) {
                continue;
            }
            $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / $n;
            $cv = sqrt($var) / $mean;

            $class = $cv <= 0.5 ? 'X' : ($cv <= 1.0 ? 'Y' : 'Z');
            $counts[$class]++;

            $out[] = [
                'product_id' => $pid,
                'sku'        => $byId[$pid]['sku'] ?? '',
                'name'       => $byId[$pid]['name'] ?? '',
                'cv'         => round($cv, 2),
                'class'      => $class,
                'weekly_avg' => round($mean, 3),
            ];
        }

        usort($out, fn ($a, $b) => $a['cv'] <=> $b['cv']);

        return ['counts' => $counts, 'rows' => array_slice($out, 0, 50)];
    }

    /* ── Turnover ───────────────────────────────────────────────── */

    private function turnover(array $rows, int $days): array
    {
        $out = [];
        foreach ($rows as $r) {
            $avg = ((float) $r['opening'] + (float) $r['closing']) / 2;
            if ($avg <= 0) {
                continue;
            }
            $turns = (float) $r['qty_out'] / $avg;
            $out[] = [
                'product_id' => $r['product_id'], 'sku' => $r['sku'], 'name' => $r['name'],
                'turnover'   => round($turns, 2),
                // Annualised so a 30-day and a 90-day window are comparable.
                'annualised' => round($turns * (365 / max(1, $days)), 2),
                'avg_stock'  => round($avg, 3),
            ];
        }

        usort($out, fn ($a, $b) => $b['turnover'] <=> $a['turnover']);

        return ['fastest' => array_slice($out, 0, 10), 'slowest' => array_slice(array_reverse($out), 0, 10)];
    }

    /* ── Dead stock ─────────────────────────────────────────────── */

    /**
     * Stock sitting on the shelf that hasn't moved out in the window. Reported
     * with its carrying value because "17 items are dead" is not actionable but
     * "₹2.4L is dead" is.
     */
    private function deadStock(int $tenantId, array $rows): array
    {
        $movedOut = [];
        foreach ($rows as $r) {
            if ((float) $r['qty_out'] > 0) {
                $movedOut[$r['product_id']] = true;
            }
        }

        $held = DB::table('inventory_stock as s')
            ->join('inventory_products as p', 'p.id', '=', 's.product_id')
            ->where('s.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.without_checking_warehouse', false)
            ->groupBy('s.product_id', 'p.sku', 'p.name', 'p.cost_price')
            ->havingRaw('SUM(s.quantity) > 0')
            ->get(['s.product_id', 'p.sku', 'p.name', 'p.cost_price', DB::raw('SUM(s.quantity) as qty')]);

        $dead = [];
        foreach ($held as $h) {
            if (isset($movedOut[$h->product_id])) {
                continue;
            }
            $dead[] = [
                'product_id' => (int) $h->product_id,
                'sku'        => $h->sku,
                'name'       => $h->name,
                'quantity'   => round((float) $h->qty, 3),
                'value'      => round((float) $h->qty * (float) ($h->cost_price ?? 0), 2),
            ];
        }

        usort($dead, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'count' => count($dead),
            'value' => round(array_sum(array_column($dead, 'value')), 2),
            'rows'  => array_slice($dead, 0, 25),
        ];
    }

    /* ── Stock accuracy ─────────────────────────────────────────── */

    /**
     * How often a physical count agreed with the book. Every correction is an
     * 'adjustment' movement, so accuracy = 1 - (corrections / all movements).
     * A warehouse that never adjusts scores 100%; one that constantly does is
     * telling you its numbers can't be trusted.
     */
    private function stockAccuracy(int $tenantId, int $days, ?int $userId): array
    {
        $base = Movement::forTenant($tenantId)->where('created_at', '>=', now()->subDays($days));
        if ($userId) {
            $base->where('actor_id', $userId);
        }

        $total = (clone $base)->count();
        $corrections = (clone $base)->where('type', 'adjustment')->count();
        $correctedQty = (float) (clone $base)->where('type', 'adjustment')->sum('quantity');

        return [
            'movements'    => $total,
            'corrections'  => $corrections,
            'corrected_qty' => round($correctedQty, 3),
            'accuracy_pct' => $total > 0 ? round((1 - $corrections / $total) * 100, 1) : 100.0,
        ];
    }

    /* ── Trend + mix (chart data) ───────────────────────────────── */

    /** Daily in/out for the window — the chart the dashboard draws. */
    private function movementTrend(int $tenantId, int $days, ?int $userId, $warehouseId): array
    {
        $q = DB::table('inventory_movements')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays($days)->startOfDay());

        if ($userId) {
            $q->where('actor_id', $userId);
        }
        if ($warehouseId) {
            $q->where(fn ($w) => $w->where('from_warehouse_id', $warehouseId)->orWhere('to_warehouse_id', $warehouseId));
        }

        $rows = $q->get(['direction', 'quantity', 'created_at']);

        // Bucket by day in PHP — portable across sqlite/mysql date functions.
        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $buckets[now()->subDays($i)->toDateString()] = ['date' => now()->subDays($i)->toDateString(), 'in' => 0.0, 'out' => 0.0];
        }

        foreach ($rows as $r) {
            $day = substr((string) $r->created_at, 0, 10);
            if (! isset($buckets[$day])) {
                continue;
            }
            if ($r->direction === 'in') {
                $buckets[$day]['in'] += (float) $r->quantity;
            } elseif ($r->direction === 'out') {
                $buckets[$day]['out'] += (float) $r->quantity;
            }
        }

        return array_values(array_map(
            fn ($b) => ['date' => $b['date'], 'in' => round($b['in'], 3), 'out' => round($b['out'], 3)],
            $buckets
        ));
    }

    /** Stock value split by category — the admin-only mix chart. */
    private function categoryMix(int $tenantId): array
    {
        return DB::table('inventory_stock as s')
            ->join('inventory_products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('inventory_categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->groupBy('c.id', 'c.name')
            ->get([
                DB::raw("COALESCE(c.name,'Uncategorised') as name"),
                DB::raw('SUM(s.quantity * COALESCE(p.cost_price,0)) as value'),
                DB::raw('SUM(s.quantity) as qty'),
            ])
            ->map(fn ($r) => ['name' => $r->name, 'value' => round((float) $r->value, 2), 'qty' => round((float) $r->qty, 3)])
            ->filter(fn ($r) => $r['value'] > 0)
            ->sortByDesc('value')->values()->all();
    }
}
