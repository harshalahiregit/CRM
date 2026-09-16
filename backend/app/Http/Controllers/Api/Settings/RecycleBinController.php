<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Support\RecycleBin\TrashRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The global recycle bin — deleted records, and the way back.
 *
 * Almost every record in this CRM already soft-deleted, so the rows were never
 * really gone; they were simply unreachable. This bin covered five types, which
 * meant that for every other module "delete" was, from the user's side, final.
 * The set it can restore now lives in TrashRegistry.
 *
 * Admin-only (the route group enforces it) and tenant-scoped on every read and
 * write, so a restore can never resurrect another tenant's row.
 *
 * ── One honest limitation ───────────────────────────────────────────────────
 * Restoring is per row. Deleting a parent often cascades to its children, and
 * bringing the parent back alone returns it without them. Task solves this in
 * its own service by restoring the deleted subtree, and that is the right shape
 * — but it needs a per-type notion of "children", which a generic engine does
 * not have. So this restores exactly what was asked for and says so, rather
 * than half-restoring a tree and calling it done.
 */
class RecycleBinController extends Controller
{
    /** How many rows to pull per type when showing everything at once. */
    private const PER_TYPE = 25;

    /** The most rows the merged, all-types view will return. */
    private const MERGED_CAP = 200;

    /**
     * GET /settings/recycle-bin
     *
     * Optional: `type` (one registry key), `group` (a module), `q` (search the
     * label), `limit`.
     */
    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $group = (string) $request->query('group', '');

        $registry = TrashRegistry::all();

        // Narrow before querying: with sixty-odd types, filtering after the fact
        // would mean sixty pointless queries to show one module.
        $wanted = array_filter(
            $registry,
            fn ($entry, $key) => (! $type || $key === $type) && (! $group || $entry[3] === $group),
            ARRAY_FILTER_USE_BOTH
        );

        $items = [];
        $counts = [];

        foreach ($registry as $key => [$model, $labels, $label, $entryGroup]) {
            $countQuery = $this->trashedQuery($model, $tenantId);
            $counts[$key] = (clone $countQuery)->count();

            if (! isset($wanted[$key]) || $counts[$key] === 0) {
                continue;
            }

            $rows = $countQuery
                ->orderByDesc('deleted_at')
                ->limit($type ? self::MERGED_CAP : self::PER_TYPE)
                ->get();

            foreach ($rows as $row) {
                $rowLabel = TrashRegistry::labelFor($row, $labels);

                // Searching the rendered label, not one column, so "PO-0012"
                // and the order's title both find the same row.
                if ($q !== '' && stripos($rowLabel, $q) === false && stripos($label, $q) === false) {
                    continue;
                }

                $items[] = [
                    'type'       => $key,
                    'type_label' => $label,
                    'group'      => $entryGroup,
                    'id'         => $row->getKey(),
                    'label'      => $rowLabel,
                    'deleted_at' => optional($row->deleted_at)->toIso8601String(),
                ];
            }
        }

        // Most recently deleted first, across every type.
        usort($items, fn ($a, $b) => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']));

        return response()->json([
            'data'   => array_slice($items, 0, self::MERGED_CAP),
            'total'  => array_sum($counts),
            // Only types that actually hold something are offered as filters —
            // sixty empty chips is not a filter, it is a wall.
            'types'  => array_values(array_map(
                fn ($key) => [
                    'value' => $key,
                    'label' => $registry[$key][2],
                    'group' => $registry[$key][3],
                    'count' => $counts[$key],
                ],
                array_keys(array_filter($counts))
            )),
            'groups' => array_values(array_filter(
                TrashRegistry::groups(),
                fn ($g) => collect($registry)->contains(fn ($e, $k) => $e[3] === $g && ($counts[$k] ?? 0) > 0)
            )),
        ]);
    }

    /** POST /settings/recycle-bin/restore — bring one deleted record back. */
    public function restore(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string',
            'id'   => 'required|integer',
        ]);

        abort_unless(TrashRegistry::has($data['type']), 422, 'Unknown item type.');

        $entry = TrashRegistry::get($data['type']);

        $row = $this->trashedQuery($entry['model'], (int) $request->user()->tenant_id)
            ->whereKey($data['id'])
            ->first();

        abort_unless($row, 404, 'That item is not in the recycle bin.');

        $row->restore();

        return response()->json([
            'restored' => true,
            'type'     => $data['type'],
            'id'       => $row->getKey(),
            'label'    => TrashRegistry::labelFor($row, $entry['labels']),
        ]);
    }

    /**
     * Trashed rows of one type, for this tenant only.
     *
     * `withoutGlobalScopes` because most of these models carry a BelongsToTenant
     * scope that would apply anyway — but several also scope by status or
     * ownership, and a global scope that hides a live row would hide its deleted
     * twin too, quietly making part of the bin unreachable. The tenant filter is
     * therefore applied explicitly here rather than inherited.
     *
     * @param  class-string<Model>  $model
     */
    private function trashedQuery(string $model, int $tenantId)
    {
        return $model::query()
            ->withoutGlobalScopes()
            ->onlyTrashed()
            ->where('tenant_id', $tenantId);
    }
}
