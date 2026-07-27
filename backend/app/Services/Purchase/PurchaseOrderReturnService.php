<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOrderReturn;
use App\Models\Purchase\PurchaseOrderReturnItem;
use App\Models\User;
use App\Repositories\Purchase\PurchaseOrderReturnRepository;
use App\Support\Purchase\PurchaseOrderReturnStatus as Status;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Order Returns engine — goods returned to a Purchase Vendor.
 * Owns its own OR-#### series and line-level discounts. It does NOT touch
 * purchase_debit_notes; the two documents are independent.
 */
class PurchaseOrderReturnService
{
    public function __construct(private PurchaseOrderReturnRepository $repo)
    {
    }

    public function list(int $tenantId, array $filters): LengthAwarePaginator
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    public function find(int $id, int $tenantId): PurchaseOrderReturn
    {
        $return = $this->repo->findForTenant($id, $tenantId);
        if (! $return) {
            throw new BusinessException('Order return not found.', 404);
        }

        return $return;
    }

    public function create(array $data, User $actor): PurchaseOrderReturn
    {
        $tenantId = $actor->tenant_id;
        $lines    = $data['items'] ?? [];
        unset($data['items']);

        $return = DB::transaction(function () use ($data, $lines, $tenantId, $actor) {
            $return = PurchaseOrderReturn::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'or_number'  => $data['or_number'] ?? $this->repo->nextNumber($tenantId),
                'status'     => $data['status'] ?? Status::DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->syncItems($return, $lines);

            return $return;
        });

        $return->recordAudit('Order Return Created', $actor, null, [
            'or_number' => $return->or_number, 'purchase_vendor_id' => $return->purchase_vendor_id,
        ]);
        Log::channel('purchase')->info('Purchase order return created', [
            'order_return_id' => $return->id, 'tenant_id' => $tenantId,
        ]);

        return $this->find($return->id, $tenantId);
    }

    public function update(PurchaseOrderReturn $return, array $data, User $actor): PurchaseOrderReturn
    {
        if (! $return->isEditable()) {
            throw new BusinessException('Only a Draft order return can be edited.');
        }

        $lines = $data['items'] ?? null;
        unset($data['items'], $data['or_number'], $data['status']);

        DB::transaction(function () use ($return, $data, $lines) {
            $return->update($data);
            if ($lines !== null) {
                $this->syncItems($return, $lines);
            }
        });

        $return->recordAudit('Order Return Updated', $actor, null, ['or_number' => $return->or_number]);

        return $this->find($return->id, $return->tenant_id);
    }

    /** Draft → Issued: the return is now sent to the vendor. */
    public function issue(PurchaseOrderReturn $return, User $actor): PurchaseOrderReturn
    {
        if ($return->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft order return can be issued.');
        }
        if ($return->items()->count() === 0) {
            throw new BusinessException('Add at least one returned line before issuing.');
        }

        $return->update(['status' => Status::ISSUED, 'issued_at' => now(), 'issued_by' => $actor->id]);
        $return->recordAudit('Order Return Issued', $actor, null, ['or_number' => $return->or_number]);

        return $this->find($return->id, $return->tenant_id);
    }

    /** Issued → Completed: the vendor has accepted / settled the return. */
    public function complete(PurchaseOrderReturn $return, User $actor): PurchaseOrderReturn
    {
        if ($return->status !== Status::ISSUED) {
            throw new BusinessException('Only an Issued order return can be completed.');
        }

        $return->update(['status' => Status::COMPLETED]);
        $return->recordAudit('Order Return Completed', $actor, null, ['or_number' => $return->or_number]);

        return $this->find($return->id, $return->tenant_id);
    }

    public function cancel(PurchaseOrderReturn $return, User $actor, ?string $remarks = null): PurchaseOrderReturn
    {
        if ($return->status === Status::COMPLETED) {
            throw new BusinessException('A completed order return cannot be cancelled.');
        }

        $return->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $return->notes]);
        $return->recordAudit('Order Return Cancelled', $actor, $remarks, ['or_number' => $return->or_number]);

        return $this->find($return->id, $return->tenant_id);
    }

    public function delete(PurchaseOrderReturn $return, User $actor): void
    {
        if (! $return->isEditable()) {
            throw new BusinessException('Only a Draft order return can be deleted.');
        }

        $return->recordAudit('Order Return Deleted', $actor, null, ['or_number' => $return->or_number]);
        $return->items()->delete();
        $return->delete();
    }

    /** Replace the return's lines, then recompute subtotal/discount/total. */
    private function syncItems(PurchaseOrderReturn $return, array $lines): void
    {
        $return->items()->delete();

        foreach (array_values($lines) as $i => $line) {
            PurchaseOrderReturnItem::create([
                'tenant_id'                => $return->tenant_id,
                'purchase_order_return_id' => $return->id,
                'purchase_order_item_id'   => $line['purchase_order_item_id'] ?? null,
                'description'              => $line['description'],
                'qty'                      => $line['qty'] ?? 0,
                'unit'                     => $line['unit'] ?? null,
                'rate'                     => $line['rate'] ?? 0,
                'discount'                 => $line['discount'] ?? 0,
                'tax'                      => $line['tax'] ?? 0,
                'amount'                   => PurchaseOrderReturnItem::computeAmount($line),
                'sort_order'               => $i,
            ]);
        }

        $return->recalculate();
    }
}
