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

    public function __construct(private VoucherService $vouchers)
    {
    }

    public function index(Request $request, string $type)
    {
        $this->denyExternal($request);
        $this->vouchers->assertType($type);
        $filters = $request->only(['status', 'search', 'from', 'to', 'created_by']);

        return $this->success($this->vouchers->list($type, $request->user()->tenant_id, $filters), 'Vouchers retrieved');
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
