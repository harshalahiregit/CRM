<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Inventory\StoreVoucherRequest;
use App\Services\Inventory\VoucherService;
use Illuminate\Http\Request;

/**
 * Stock documents (§3–§6). One controller for all four types — {type} comes off
 * the route, so /inventory/vouchers/receipt and /inventory/vouchers/internal
 * share every code path except the type-specific validation.
 *
 * Warehouse staff raise and post vouchers (that's the job); deleting a document
 * is admin-only, and a posted one can't be deleted at all — only cancelled.
 */
class VoucherController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(
        private VoucherService $vouchers,
        private \App\Services\Inventory\ApprovalService $approvals,
        private \App\Services\Inventory\ReceivingService $receiving,
    ) {
    }

    public function index(Request $request, string $type)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);
        $filters = $request->only(['status', 'search', 'from', 'to', 'created_by']);

        return $this->success(
            $this->vouchers->list($type, $request->user()->tenant_id, $filters, [
                'user_id'       => $request->user()->id,
                'is_admin'      => $this->isAdmin($request),
                'warehouse_ids' => $this->managedWarehouseIds($request),
            ]),
            'Vouchers retrieved'
        );
    }

    public function show(Request $request, string $type, int $id)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);

        return $this->success($this->vouchers->show($id, $request->user()->tenant_id), 'Voucher retrieved');
    }

    public function store(StoreVoucherRequest $request, string $type)
    {
        $this->denyExternal($request);
        $voucher = $this->vouchers->create($type, $request->validated(), $request->user()->tenant_id, $request->user()->id);

        return $this->success($voucher, 'Voucher saved', 201);
    }

    public function update(StoreVoucherRequest $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'edit this document');
        $this->vouchers->assertType($type);

        return $this->success($this->vouchers->update($id, $request->validated(), $request->user()->tenant_id), 'Voucher updated');
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'delete this document');
        $this->vouchers->assertType($type);
        $this->vouchers->delete($id, $request->user()->tenant_id);

        return $this->success(null, 'Voucher deleted');
    }

    /** Post: turn the document into ledger movements. */
    public function post(Request $request, string $type, int $id)
    {
        // Posting is the act that MOVES stock — same bar as editing it.
        $this->guardVoucherManage($request, $id, 'post this document');
        $this->vouchers->assertType($type);

        return $this->success(
            $this->vouchers->post($id, $request->user()->tenant_id, $request->user()->id),
            'Voucher posted — stock updated'
        );
    }

    /* ── Receiving inspection (§13) ─────────────────────────────── */

    /**
     * Record what actually turned up and what passed inspection. Same bar as
     * posting, because this decides what reaches the shelf.
     */
    public function inspect(Request $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'inspect this delivery');
        $this->vouchers->assertType($type);

        $data = $request->validate([
            'lines'                    => 'required|array|min:1',
            'lines.*.id'               => 'required|integer',
            'lines.*.received_qty'     => 'nullable|numeric|min:0',
            'lines.*.accepted_qty'     => 'nullable|numeric|min:0',
            'lines.*.rejection_reason' => 'nullable|string|max:500',
        ]);

        return $this->success(
            $this->receiving->inspect($id, $data['lines'], $request->user()->tenant_id, $request->user()->id),
            'Inspection saved'
        );
    }

    /** What the supplier has to take back. */
    public function vendorReturn(Request $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'raise a vendor return');
        $this->vouchers->assertType($type);

        return $this->success(
            $this->receiving->vendorReturn($id, $request->user()->tenant_id, $request->user()->id),
            'Vendor return raised'
        );
    }

    /* ── Approval gate ──────────────────────────────────────────── */

    /** Send a draft for approval. Same bar as editing — it's your document. */
    public function submit(Request $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'send this document for approval');
        $this->vouchers->assertType($type);

        return $this->success(
            $this->approvals->submit($id, $request->user()->tenant_id, $request->user()->id),
            'Sent for approval'
        );
    }

    /**
     * Approve / reject.
     *
     * Deliberately a HIGHER bar than posting: an approver must be an admin or
     * the manager of the warehouse involved. guardVoucherManage would also let
     * the document's own creator through, and someone approving their own work
     * is exactly what this gate exists to prevent (ApprovalService refuses it
     * again on the way past, so the rule holds even if this guard is loosened).
     */
    public function approve(Request $request, string $type, int $id)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);
        $this->assertApprover($request, $id);

        return $this->success(
            $this->approvals->approve($id, $request->user()->tenant_id, $request->user()->id),
            'Approved — it can be posted now'
        );
    }

    public function reject(Request $request, string $type, int $id)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);
        $this->assertApprover($request, $id);

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->success(
            $this->approvals->reject($id, $request->user()->tenant_id, $request->user()->id, $data['reason']),
            'Sent back to the requester'
        );
    }

    /** Everything waiting on this user's decision, across all four types. */
    public function pending(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->approvals->pendingFor(
                $request->user()->tenant_id,
                $request->user()->id,
                $this->isAdmin($request),
                $this->managedWarehouseIds($request),
            ),
            'Pending approvals retrieved'
        );
    }

    private function assertApprover(Request $request, int $voucherId): void
    {
        if ($this->isAdmin($request)) {
            return;
        }

        $warehouseId = \App\Models\Inventory\Voucher::forTenant($request->user()->tenant_id)
            ->whereKey($voucherId)->value('warehouse_id');

        abort_unless(
            $warehouseId && in_array((int) $warehouseId, $this->managedWarehouseIds($request), true),
            403,
            'Only an admin or the manager of that warehouse can approve this document.'
        );
    }

    /** Cancel: reverses the movements if it was already posted. */
    public function cancel(Request $request, string $type, int $id)
    {
        $this->guardVoucherManage($request, $id, 'cancel this document');
        $this->vouchers->assertType($type);

        return $this->success(
            $this->vouchers->cancel($id, $request->user()->tenant_id, $request->user()->id),
            'Voucher cancelled'
        );
    }

    /**
     * Email the document to a supplier/customer (blueprint §2 "send received
     * note"). Queued, so a slow mail server never holds up the response — the
     * same pattern every other outbound mail in the app follows.
     */
    public function send(Request $request, string $type, int $id)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);

        $data = $request->validate([
            'to'      => 'required|array|min:1|max:10',
            'to.*'    => 'email',
            'subject' => 'nullable|string|max:200',
            'body'    => 'nullable|string|max:5000',
        ]);

        $voucher = $this->vouchers->show($id, $request->user()->tenant_id);
        $subject = $data['subject'] ?: "{$voucher->type_label} {$voucher->code}";

        foreach ($data['to'] as $address) {
            \Illuminate\Support\Facades\Mail::to($address)->send(
                new \App\Mail\Inventory\VoucherNoteMail($voucher, $subject, $data['body'] ?? '')
            );
        }

        return $this->success(['sent' => count($data['to'])], 'Document sent');
    }
}
