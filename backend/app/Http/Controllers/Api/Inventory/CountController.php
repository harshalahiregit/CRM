<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\CountLine;
use App\Models\Inventory\CountSession;
use App\Services\Inventory\CountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Cycle counting and physical verification (§12).
 *
 * Three different bars, because the three jobs carry different weight:
 *
 *  • RAISING a sheet decides what gets checked, so it is a supervisor's act —
 *    admin or the manager of the warehouse being counted.
 *  • COUNTING is warehouse work. The assigned counter does it, and so may an
 *    admin or the warehouse's manager; nobody else can scribble on a sheet
 *    they were not given.
 *  • APPROVING writes corrections to the ledger, so it takes the same bar as
 *    posting a document — and never the person who did the counting.
 */
class CountController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private CountService $counts)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        $f = $request->only(['status', 'warehouse_id', 'scope', 'assigned_to', 'search']);
        // Resolved from the token, so it can never be pointed at a colleague.
        if ($request->boolean('mine')) {
            $f['mine'] = $request->user()->id;
        }

        return $this->success($this->counts->list($request->user()->tenant_id, $f), 'Counts retrieved');
    }

    public function show(Request $request, int $id)
    {
        $this->denyExternal($request);

        $session = $this->counts->show($id, $request->user()->tenant_id);
        $variance = $this->counts->variance($session);

        $canApprove = $this->canApproveCount($request, $session);

        return $this->success([
            // Blindness is applied AFTER the variance is computed, so the
            // summary the supervisor sees is unaffected by what the counter
            // is allowed to see.
            'count'    => $this->counts->applyBlindness($session, $request->user()->id),
            // A counter must not be handed the variance either — it would tell
            // them the expected figure by subtraction.
            'variance' => $canApprove || ! $session->blind || (int) $session->assigned_to !== $request->user()->id
                ? $variance
                : null,
            // The server decides what this viewer may do, so the UI never
            // offers a button that would come back 403.
            'can_work'    => $this->canWorkCount($request, $session),
            'can_approve' => $canApprove,
        ], 'Count retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'warehouse_id'  => 'required|integer|min:1',
            'name'          => 'nullable|string|max:160',
            'scope'         => ['nullable', Rule::in(CountSession::SCOPES)],
            'location_ids'  => 'nullable|array',
            'category_ids'  => 'nullable|array',
            'product_ids'   => 'nullable|array',
            'abc_class'     => 'nullable|string|max:1',
            'sample_size'   => 'nullable|integer|min:1|max:200',
            'blind'         => 'nullable|boolean',
            'assigned_to'   => 'nullable|integer|exists:users,id',
            'note'          => 'nullable|string|max:1000',
        ]);

        $this->guardWarehouse($request, (int) $data['warehouse_id'], 'raise a count at this warehouse');

        return $this->success(
            $this->counts->create($data, $request->user()->tenant_id, $request->user()->id),
            'Count sheet raised', 201
        );
    }

    public function assign(Request $request, int $id)
    {
        $session = $this->counts->find($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $session->warehouse_id, 'assign this count');

        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);

        return $this->success(
            $this->counts->assign($id, $data['assigned_to'] ?? null, $request->user()->tenant_id, $request->user()->id),
            'Count assigned'
        );
    }

    /** A counter found something the sheet doesn't list. */
    public function addLine(Request $request, int $id)
    {
        $this->guardWork($request, $id);

        $data = $request->validate([
            'product_id'  => 'required|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'note'        => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->counts->addLine($id, $data, $request->user()->tenant_id, $request->user()->id),
            'Added to the count sheet', 201
        );
    }

    public function count(Request $request, int $id)
    {
        $this->guardWork($request, $id);

        $data = $request->validate([
            'lines'                => 'required|array|min:1',
            'lines.*.id'           => 'required|integer',
            'lines.*.counted_qty'  => 'required|numeric|min:0',
            'lines.*.note'         => 'nullable|string|max:255',
            // Evidence, when the device offers it. Never required — a warehouse
            // with no smartphones still has to count.
            'lines.*.gps_lat'      => 'nullable|numeric|between:-90,90',
            'lines.*.gps_lng'      => 'nullable|numeric|between:-180,180',
            'lines.*.gps_accuracy' => 'nullable|numeric|min:0',
            'lines.*.device'       => 'nullable|string|max:120',
        ]);

        return $this->success(
            $this->counts->count($id, $data['lines'], $request->user()->tenant_id, $request->user()->id),
            'Count saved'
        );
    }

    public function recount(Request $request, int $id)
    {
        $this->guardWork($request, $id);

        $data = $request->validate([
            'lines'                => 'required|array|min:1',
            'lines.*.id'           => 'required|integer',
            'lines.*.recount_qty'  => 'required|numeric|min:0',
            'lines.*.note'         => 'nullable|string|max:255',
            'lines.*.gps_lat'      => 'nullable|numeric|between:-90,90',
            'lines.*.gps_lng'      => 'nullable|numeric|between:-180,180',
            'lines.*.gps_accuracy' => 'nullable|numeric|min:0',
            'lines.*.device'       => 'nullable|string|max:120',
        ]);

        return $this->success(
            $this->counts->recount($id, $data['lines'], $request->user()->tenant_id, $request->user()->id),
            'Recount saved'
        );
    }

    public function submit(Request $request, int $id)
    {
        $this->guardWork($request, $id);

        return $this->success(
            $this->counts->submit($id, $request->user()->tenant_id, $request->user()->id),
            'Sent for approval — nothing is corrected until it is signed off'
        );
    }

    public function approve(Request $request, int $id)
    {
        $session = $this->counts->find($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $session->warehouse_id, 'approve a count at this warehouse');

        return $this->success(
            $this->counts->approve($id, $request->user()->tenant_id, $request->user()->id),
            'Count approved — the corrections are in the ledger'
        );
    }

    public function reject(Request $request, int $id)
    {
        $session = $this->counts->find($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $session->warehouse_id, 'decide on a count at this warehouse');

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->success(
            $this->counts->reject($id, $request->user()->tenant_id, $request->user()->id, $data['reason']),
            'Sent back to be counted again'
        );
    }

    public function cancel(Request $request, int $id)
    {
        $session = $this->counts->find($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $session->warehouse_id, 'cancel a count at this warehouse');

        return $this->success(
            $this->counts->cancel($id, $request->user()->tenant_id, $request->user()->id),
            'Count cancelled'
        );
    }

    /* ── Evidence ───────────────────────────────────────────────── */

    /**
     * The photo behind a variance. Private disk, authenticated download — same
     * rule as every other attachment in the module.
     */
    public function uploadPhoto(Request $request, int $id, int $line)
    {
        $this->guardWork($request, $id);

        $request->validate(['photo' => 'required|image|max:8192']);

        $tenantId = $request->user()->tenant_id;
        $row = CountLine::forTenant($tenantId)->where('count_session_id', $id)->findOrFail($line);

        // Replacing evidence removes the old file rather than orphaning it.
        if ($row->photo_path) {
            Storage::disk('local')->delete($row->photo_path);
        }

        $row->forceFill([
            'photo_path' => $request->file('photo')->store("inventory/counts/{$tenantId}/{$id}", 'local'),
        ])->save();

        return $this->success($row->fresh(), 'Photo attached');
    }

    public function photo(Request $request, int $id, int $line)
    {
        $this->denyExternal($request);

        $row = CountLine::forTenant($request->user()->tenant_id)
            ->where('count_session_id', $id)->findOrFail($line);

        abort_unless($row->photo_path && Storage::disk('local')->exists($row->photo_path), 404, 'No photo on that line.');

        return Storage::disk('local')->download($row->photo_path, "count-{$id}-line-{$line}.jpg");
    }

    /* ── Barriers ───────────────────────────────────────────────── */

    /** Raising, assigning and deciding: admin, or the warehouse's manager. */
    private function guardWarehouse(Request $request, int $warehouseId, string $what): void
    {
        $this->denyExternal($request);

        abort_unless(
            $this->isAdmin($request) || in_array($warehouseId, $this->managedWarehouseIds($request), true),
            403,
            "Only an admin or the warehouse manager can {$what}."
        );
    }

    /** Walking the sheet: the person it was given to, or a supervisor. */
    private function guardWork(Request $request, int $id): void
    {
        $this->denyExternal($request);

        $session = $this->counts->find($id, $request->user()->tenant_id);

        abort_unless(
            $this->canWorkCount($request, $session),
            403,
            'This count was given to somebody else.'
        );
    }

    private function canWorkCount(Request $request, CountSession $session): bool
    {
        $uid = (int) $request->user()->id;

        return $this->isAdmin($request)
            || (int) $session->assigned_to === $uid
            || (int) $session->created_by === $uid
            || in_array((int) $session->warehouse_id, $this->managedWarehouseIds($request), true);
    }

    /**
     * A supervisor who did NOT do the counting. The service enforces this too —
     * this is only so the UI knows whether to draw the button.
     */
    private function canApproveCount(Request $request, CountSession $session): bool
    {
        $uid = (int) $request->user()->id;

        if ((int) $session->assigned_to === $uid) {
            return false;
        }
        if ($session->lines->contains(fn (CountLine $l) => (int) $l->counted_by === $uid)) {
            return false;
        }

        return $this->isAdmin($request)
            || in_array((int) $session->warehouse_id, $this->managedWarehouseIds($request), true);
    }
}
