<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseRequest;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Repositories\Purchase\PurchaseOrderRepository;
use App\Support\Purchase\PurchaseOrderStatus as Status;
use App\Support\Purchase\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderService
{
    public function __construct(
        private PurchaseOrderRepository $purchaseOrderRepository,
        private PurchaseCatalogService $catalogService,
        private PurchaseContractService $contractService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->purchaseOrderRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseOrder
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantId = $actor->tenant_id;
        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $tenantId);

        $po = DB::transaction(function () use ($data, $items, $tenantId, $actor) {
            $po = PurchaseOrder::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $actor->id,
                'status'     => Status::DRAFT,
            ]);
            $this->applyLines($po, $items);
            $po->recalcTotals();

            return $po;
        });

        $po->recordAudit('Purchase Order Created', $actor, null, ['po_number' => $po->po_number]);

        Log::channel('purchase')->info('Purchase order created', [
            'purchase_order_id' => $po->id, 'tenant_id' => $tenantId,
        ]);

        return $po->fresh(['items']);
    }

    /**
     * Convert an approved Purchase Request into a draft PO — copies the line
     * items and marks the source PR as Converted. This is the "Convert to PO"
     * action from the Purchase Request screen.
     */
    public function createFromRequest(PurchaseRequest $pr, User $actor): PurchaseOrder
    {
        if ($pr->status !== PurchaseRequestStatus::APPROVED) {
            throw new BusinessException('Only an Approved purchase request can be converted to a PO.');
        }

        $po = DB::transaction(function () use ($pr, $actor) {
            $po = PurchaseOrder::create([
                'tenant_id'           => $pr->tenant_id,
                'purchase_request_id' => $pr->id,
                'purchase_vendor_id'           => $pr->purchase_vendor_id,
                'created_by'          => $actor->id,
                'title'               => $pr->title,
                'department'          => $pr->department,
                'currency'            => $pr->currency,
                'order_date'          => now()->toDateString(),
                'status'              => Status::DRAFT,
            ]);

            foreach ($pr->items as $i => $item) {
                $po->items()->create([
                    'tenant_id'             => $pr->tenant_id,
                    'catalog_item_id'       => $item->catalog_item_id,
                    'description'           => $item->description,
                    'qty'                   => $item->qty,
                    'unit'                  => $item->unit,
                    'rate'                  => $item->rate,
                    'tax'                   => $item->tax,
                    'contract_rate_applied' => $item->contract_rate_applied,
                    'sort_order'            => $item->sort_order ?? $i,
                ]);
            }
            $po->recalcTotals();

            // Carry the contract link forward so the converted PO still enforces
            // the ceiling at issue. Rates are preserved from the PR (already pulled);
            // we only re-resolve which contract to charge against.
            if ($pr->items->contains(fn ($it) => $it->contract_rate_applied)) {
                $resolution = $this->contractService->resolveDocumentContract($pr->tenant_id, $pr->purchase_vendor_id, $pr->items->toArray());
                if (! empty($resolution['contract'])) {
                    $po->purchase_contract_id = $resolution['contract']->id;
                    $po->saveQuietly();
                }
            }

            $pr->update(['status' => PurchaseRequestStatus::CONVERTED]);
            $pr->recordAudit('Converted to Purchase Order', $actor, null, ['po_number' => $po->po_number]);

            return $po;
        });

        $po->recordAudit('Purchase Order Created', $actor, null, [
            'po_number' => $po->po_number, 'from_request' => $pr->pr_number,
        ]);

        Log::channel('purchase')->info('Purchase order created from request', [
            'purchase_order_id' => $po->id, 'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id,
        ]);

        return $po->fresh(['items']);
    }

    /**
     * Build a Draft PO from an awarded quotation. Mirrors createFromRequest, but
     * copies the vendor's quoted rates and stamps purchase_quotation_id for
     * traceability. Called inside PurchaseQuotationService::award so the whole
     * award (statuses + PO) is one transaction; the caller owns the audit on the
     * RFQ/quotation side.
     */
    public function createFromQuotation(PurchaseQuotation $quotation, User $actor): PurchaseOrder
    {
        $quotation->loadMissing(['items', 'rfq']);

        $po = PurchaseOrder::create([
            'tenant_id'             => $quotation->tenant_id,
            'purchase_request_id'   => $quotation->rfq?->purchase_request_id,
            'purchase_quotation_id' => $quotation->id,
            'purchase_vendor_id'             => $quotation->purchase_vendor_id,
            'created_by'            => $actor->id,
            'title'                 => $quotation->rfq?->title ?? 'Order from quotation',
            'department'            => $quotation->rfq?->department,
            'currency'              => $quotation->currency,
            'order_date'            => now()->toDateString(),
            'status'                => Status::DRAFT,
        ]);

        foreach ($quotation->items as $i => $item) {
            $po->items()->create([
                'tenant_id'   => $quotation->tenant_id,
                'description' => $item->description,
                'qty'         => $item->qty,
                'unit'        => $item->unit,
                'rate'        => $item->rate,
                'tax'         => $item->tax,
                'sort_order'  => $item->sort_order ?? $i,
            ]);
        }
        $po->recalcTotals();

        $po->recordAudit('Purchase Order Created', $actor, null, [
            'po_number' => $po->po_number, 'from_quotation' => $quotation->quotation_number,
        ]);

        Log::channel('purchase')->info('Purchase order created from quotation', [
            'purchase_order_id' => $po->id, 'purchase_quotation_id' => $quotation->id, 'tenant_id' => $quotation->tenant_id,
        ]);

        return $po;
    }

    public function update(PurchaseOrder $po, array $data, User $actor): PurchaseOrder
    {
        if (! $po->isEditable()) {
            throw new BusinessException('Only a Draft purchase order can be edited.');
        }

        $items = $data['items'] ?? null;
        unset($data['items']);

        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $po->tenant_id);

        DB::transaction(function () use ($po, $data, $items) {
            $po->update($data);
            if ($items !== null) {
                $po->items()->delete();
                $this->applyLines($po, $items);
            }
            $po->load('items');
            $po->recalcTotals();
        });

        $po->recordAudit('Purchase Order Updated', $actor);

        Log::channel('purchase')->info('Purchase order updated', [
            'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);

        return $po->fresh(['items']);
    }

    /** Draft → Issued. Commits the order to the vendor; locks line items. */
    public function issue(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft purchase order can be issued.');
        }
        if (! $po->purchase_vendor_id) {
            throw new BusinessException('Select a vendor before issuing the purchase order.');
        }
        // Re-check at issue time — the vendor may have been put on hold since draft.
        $this->assertVendorEngageable($po->purchase_vendor_id, $po->tenant_id);
        if ($po->items()->count() === 0) {
            throw new BusinessException('Add at least one line item before issuing.');
        }

        // Book spend against the contract ceiling (if any) and flip status in ONE
        // transaction — a ceiling breach throws inside bookConsumption and rolls
        // the whole thing back, so the PO stays Draft and nothing is consumed.
        DB::transaction(function () use ($po, $actor) {
            if ($po->purchase_contract_id) {
                $this->contractService->bookConsumption(
                    $po->purchase_contract_id, $po->tenant_id, (float) $po->total, $actor, $po->po_number
                );
            }
            $po->update(['status' => Status::ISSUED, 'issued_at' => now(), 'issued_by' => $actor->id]);
        });

        $po->recordAudit('Purchase Order Issued', $actor, null, ['from' => Status::DRAFT, 'to' => Status::ISSUED]);

        Log::channel('purchase')->info('Purchase order issued', [
            'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $po;
    }

    /** Manually close a fully/partially received PO (no further receipts). */
    public function close(PurchaseOrder $po, User $actor, ?string $remarks = null): PurchaseOrder
    {
        if (! in_array($po->status, [Status::ISSUED, Status::PARTIALLY_RECEIVED, Status::RECEIVED], true)) {
            throw new BusinessException('Only an issued or received purchase order can be closed.');
        }

        // Closing releases the contract hold (the ceiling tracks in-flight PO
        // exposure, so a settled order frees its slice back to the budget).
        DB::transaction(function () use ($po, $actor, $remarks) {
            $this->releaseContractHold($po, $actor);
            $po->update(['status' => Status::CLOSED, 'notes' => $remarks ?? $po->notes]);
        });
        $po->recordAudit('Purchase Order Closed', $actor, $remarks, ['to' => Status::CLOSED]);

        Log::channel('purchase')->info('Purchase order closed', [
            'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);

        return $po;
    }

    public function cancel(PurchaseOrder $po, User $actor, ?string $remarks = null): PurchaseOrder
    {
        if (in_array($po->status, [Status::RECEIVED, Status::CLOSED, Status::CANCELLED], true)) {
            throw new BusinessException('This purchase order can no longer be cancelled.');
        }

        // If it had already been issued, return its booked spend to the contract.
        DB::transaction(function () use ($po, $actor, $remarks) {
            $this->releaseContractHold($po, $actor);
            $po->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $po->notes]);
        });
        $po->recordAudit('Purchase Order Cancelled', $actor, $remarks, ['to' => Status::CANCELLED]);

        Log::channel('purchase')->info('Purchase order cancelled', [
            'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);

        return $po;
    }

    public function destroy(PurchaseOrder $po): void
    {
        if ($po->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft purchase order can be deleted.');
        }

        $po->delete();

        Log::channel('purchase')->info('Purchase order deleted', [
            'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'     => PurchaseOrder::forTenant($tenantId)->count(),
            'draft'     => PurchaseOrder::forTenant($tenantId)->where('status', Status::DRAFT)->count(),
            'issued'    => PurchaseOrder::forTenant($tenantId)->where('status', Status::ISSUED)->count(),
            'partial'   => PurchaseOrder::forTenant($tenantId)->where('status', Status::PARTIALLY_RECEIVED)->count(),
            'received'  => PurchaseOrder::forTenant($tenantId)->where('status', Status::RECEIVED)->count(),
            'closed'    => PurchaseOrder::forTenant($tenantId)->where('status', Status::CLOSED)->count(),
            'open_value' => PurchaseOrder::forTenant($tenantId)
                ->whereIn('status', [Status::ISSUED, Status::PARTIALLY_RECEIVED])->sum('total'),
        ];
    }

    /**
     * Build the PO's lines with catalog snapshotting and contract rate-pull, then
     * stamp the contract the order draws from. Precedence per line:
     *   1. Catalog snapshot fills description/unit/rate/tax for a picked SKU
     *      (explicit line values still win over the catalog default).
     *   2. If the vendor has an Active, in-window contract covering that SKU (and
     *      the qty sits in its band), the locked contract rate/tax OVERRIDE
     *      everything — it is authoritative — and the line is flagged.
     * A PO draws from at most one contract (chosen in the service); only lines
     * matching that contract are flagged, and the PO links to it for ceiling
     * enforcement at issue time.
     */
    private function applyLines(PurchaseOrder $po, array $items): void
    {
        $tenantId = $po->tenant_id;
        $resolution = $this->contractService->resolveDocumentContract($tenantId, $po->purchase_vendor_id, $items);
        $rateMap  = $resolution['rate_map'] ?? [];
        $contract = $resolution['contract'] ?? null;
        $contractUsed = false;

        foreach ($items as $i => $item) {
            $catalogId = $item['catalog_item_id'] ?? null;
            $snapshot  = $this->catalogService->snapshotForLine($catalogId ? (int) $catalogId : null, $tenantId);

            // Catalog fills blanks; an explicit line value still wins over the default.
            $line = [
                'tenant_id'             => $tenantId,
                'catalog_item_id'       => $catalogId,
                'description'           => $item['description'] ?? $snapshot['description'] ?? null,
                'qty'                   => $item['qty'] ?? 1,
                'unit'                  => $item['unit'] ?? $snapshot['unit'] ?? null,
                'rate'                  => $this->pick($item, 'rate', $snapshot['rate'] ?? 0),
                'tax'                   => $this->pick($item, 'tax', $snapshot['tax'] ?? 0),
                'contract_rate_applied' => false,
                'sort_order'            => $item['sort_order'] ?? $i,
            ];

            $entry = $catalogId ? ($rateMap[(int) $catalogId] ?? null) : null;
            if ($entry && $this->contractService->rateAppliesToQty($entry, (float) $line['qty'])) {
                $line['rate'] = $entry['rate'];   // authoritative — contract price wins
                $line['tax']  = $entry['tax'];
                $line['contract_rate_applied'] = true;
                $contractUsed = true;
            }

            $po->items()->create($line);
        }

        // Only link the contract when at least one line actually drew its rate.
        $po->purchase_contract_id = ($contractUsed && $contract) ? $contract->id : null;
        $po->saveQuietly();
    }

    /** Return the line's own value for a key when present & non-empty, else the fallback. */
    private function pick(array $item, string $key, $fallback)
    {
        return array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== ''
            ? $item[$key]
            : $fallback;
    }

    /**
     * Give back the contract spend a PO booked at issue — but only if it was ever
     * booked. Consumption is taken at issue, so a PO still in Draft never held any;
     * only issued/received orders release. Caller owns the transaction.
     */
    private function releaseContractHold(PurchaseOrder $po, User $actor): void
    {
        $wasConsumed = in_array($po->status, [Status::ISSUED, Status::PARTIALLY_RECEIVED, Status::RECEIVED], true);
        if ($po->purchase_contract_id && $wasConsumed) {
            $this->contractService->releaseConsumption(
                $po->purchase_contract_id, $po->tenant_id, (float) $po->total, $actor, $po->po_number
            );
        }
    }

    /**
     * A PO may only name a vendor from the caller's own tenant that is Active.
     * Mirrors PurchaseRequestService — guards cross-tenant reference and
     * transacting with a non-active vendor.
     */
    private function assertVendorEngageable(?int $vendorId, int $tenantId): void
    {
        if (! $vendorId) {
            return;
        }

        $vendor = PurchaseVendor::forTenant($tenantId)->find($vendorId);

        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }
        if (! $vendor->isEngageable()) {
            throw new BusinessException("Vendor {$vendor->purchase_vendor_code} is {$vendor->status_label} and cannot be transacted with.");
        }
    }
}
