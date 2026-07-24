<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\VoucherItem;
use Illuminate\Support\Facades\DB;

/**
 * Goods receiving and inspection (§13).
 *
 * Receiving is three numbers that routinely disagree — ordered, received,
 * accepted — and the gap between the last two is the entire reason this class
 * exists. Damaged or out-of-spec goods are physically in the building but must
 * never become sellable stock, or the next customer order picks a broken unit.
 *
 * So: inspection is recorded BEFORE posting, posting moves only what was
 * accepted, and the rejected balance stays on the document as something to send
 * back rather than quietly disappearing.
 */
class ReceivingService
{
    public const QC = ['pending', 'passed', 'failed', 'partial'];

    public function __construct(private InventoryNotifier $notifier)
    {
    }

    /**
     * Record the inspection for a receipt.
     *
     * @param  array  $lines  [['id' => voucherItemId, 'received_qty' => n,
     *                          'accepted_qty' => n, 'rejection_reason' => ?string], ...]
     */
    public function inspect(int $voucherId, array $lines, int $tenantId, int $userId): Voucher
    {
        $voucher = $this->findReceipt($voucherId, $tenantId);

        if (in_array($voucher->status, ['posted', 'cancelled'], true)) {
            throw new BusinessException(
                'This receipt is already '.$voucher->status.'. Inspection happens before the goods go on the shelf.',
                422
            );
        }

        $items = VoucherItem::forTenant($tenantId)->where('voucher_id', $voucher->id)->get()->keyBy('id');
        if ($items->isEmpty()) {
            throw new BusinessException('This receipt has no lines to inspect.', 422);
        }

        $anyRejected = false;

        DB::transaction(function () use ($lines, $items, &$anyRejected) {
            foreach ($lines as $row) {
                $item = $items->get((int) ($row['id'] ?? 0));
                if (! $item) {
                    throw new BusinessException('One of those lines does not belong to this receipt.', 422);
                }

                $ordered = (float) $item->quantity;
                $received = round((float) ($row['received_qty'] ?? $ordered), 3);
                $accepted = round((float) ($row['accepted_qty'] ?? $received), 3);

                if ($received < 0 || $accepted < 0) {
                    throw new BusinessException('Quantities cannot be negative.', 422);
                }
                // You cannot accept more than turned up. Catching this here is
                // what stops an inspection inventing stock that never arrived.
                if ($accepted > $received) {
                    throw new BusinessException(
                        "You cannot accept more than was received on {$item->product_id} ({$accepted} of {$received}).",
                        422
                    );
                }

                $rejected = round($received - $accepted, 3);
                $reason = trim((string) ($row['rejection_reason'] ?? ''));

                if ($rejected > 0 && $reason === '') {
                    throw new BusinessException(
                        'Say why the goods were rejected — the supplier needs a reason, not just a number.',
                        422
                    );
                }
                if ($rejected > 0) {
                    $anyRejected = true;
                }

                $item->forceFill([
                    'ordered_qty'      => $ordered,
                    'received_qty'     => $received,
                    'accepted_qty'     => $accepted,
                    'rejected_qty'     => $rejected,
                    'qc_status'        => $this->qcFor($received, $accepted),
                    'rejection_reason' => $rejected > 0 ? mb_substr($reason, 0, 500) : null,
                ])->save();
            }
        });

        $voucher->forceFill(['inspected_at' => now(), 'inspected_by' => $userId])->save();

        if ($anyRejected) {
            // A rejection is a supplier problem someone has to chase, and it is
            // not visible anywhere else until the document is opened.
            $this->notifier->goodsRejected($voucher->fresh(), $userId);
        }

        return $this->reload($voucher->id, $tenantId);
    }

    /** Short/over/exact and pass/fail, as a headline for the document. */
    public function summary(Voucher $voucher): array
    {
        $items = $voucher->relationLoaded('items') ? $voucher->items : $voucher->items()->get();

        $ordered = $received = $accepted = $rejected = 0.0;
        foreach ($items as $i) {
            $ordered  += (float) ($i->ordered_qty ?? $i->quantity);
            $received += (float) ($i->received_qty ?? $i->quantity);
            $accepted += (float) $i->postableQuantity();
            $rejected += (float) ($i->rejected_qty ?? 0);
        }

        return [
            'inspected'   => $voucher->inspected_at !== null,
            'ordered'     => round($ordered, 3),
            'received'    => round($received, 3),
            'accepted'    => round($accepted, 3),
            'rejected'    => round($rejected, 3),
            // Under-delivery is its own problem: the money is committed but the
            // goods are not here, and nobody finds out unless it is named.
            'short'       => round(max(0, $ordered - $received), 3),
            'qc_status'   => $this->qcFor($received, $accepted),
            'return_due'  => $rejected > 0 && $voucher->vendor_returned_at === null,
        ];
    }

    /**
     * Everything the supplier has to take back, ready to email. Marking it here
     * is what stops the same rejection being chased twice.
     */
    public function vendorReturn(int $voucherId, int $tenantId, int $userId): array
    {
        $voucher = $this->findReceipt($voucherId, $tenantId);

        $lines = VoucherItem::forTenant($tenantId)
            ->where('voucher_id', $voucher->id)
            ->where('rejected_qty', '>', 0)
            ->with('product:id,sku,name')
            ->get();

        if ($lines->isEmpty()) {
            throw new BusinessException('Nothing on this receipt was rejected, so there is nothing to return.', 422);
        }

        $voucher->forceFill(['vendor_returned_at' => now()])->save();

        return [
            'voucher'  => $voucher->fresh(),
            'supplier' => $voucher->supplier_name,
            'lines'    => $lines->map(fn ($l) => [
                'product' => $l->product?->name,
                'sku'     => $l->product?->sku,
                'qty'     => (float) $l->rejected_qty,
                'reason'  => $l->rejection_reason,
            ])->all(),
        ];
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function qcFor(float $received, float $accepted): string
    {
        if ($received <= 0) {
            return 'pending';
        }

        return $accepted >= $received ? 'passed' : ($accepted <= 0 ? 'failed' : 'partial');
    }

    private function findReceipt(int $id, int $tenantId): Voucher
    {
        $v = Voucher::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That document does not exist.', 404);

        if ($v->type !== 'receipt') {
            throw new BusinessException('Only a receiving voucher can be inspected.', 422);
        }

        return $v;
    }

    private function reload(int $id, int $tenantId): Voucher
    {
        return Voucher::forTenant($tenantId)
            ->with(['items.product:id,sku,name,base_unit', 'creator:id,name', 'warehouse:id,name'])
            ->findOrFail($id);
    }
}
