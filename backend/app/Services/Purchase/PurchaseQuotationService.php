<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseRfq;
use App\Models\User;
use App\Repositories\Purchase\PurchaseQuotationRepository;
use App\Support\Purchase\PurchaseQuotationStatus as Status;
use App\Support\Purchase\PurchaseRfqStatus as RfqStatus;
use App\Support\Purchase\RfqVendorStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseQuotationService
{
    public function __construct(
        private PurchaseQuotationRepository $quotationRepository,
        private PurchaseOrderService $purchaseOrderService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->quotationRepository->filtered($tenantId, $filters);
    }

    /**
     * Record a vendor's quotation against an open RFQ (staff-entered).
     *
     * Created directly as Received — staff are logging a quote that has already
     * arrived. Doing so marks that vendor Responded on the recipient list and
     * moves the RFQ from Sent → Under_Review (quotes are now in).
     */
    public function record(PurchaseRfq $rfq, array $data, User $actor): PurchaseQuotation
    {
        if (! $rfq->isOpenForQuotes()) {
            throw new BusinessException("Quotes can only be recorded on a Sent or Under Review RFQ; this one is {$rfq->status_label}.");
        }

        $vendorId = (int) ($data['purchase_vendor_id'] ?? 0);
        $invited  = $rfq->rfqVendors()->where('purchase_vendor_id', $vendorId)->first();
        if (! $invited) {
            throw new BusinessException('That vendor is not on this RFQ’s recipient list.');
        }

        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new BusinessException('A quotation needs at least one line item.');
        }

        $quotation = DB::transaction(function () use ($rfq, $data, $items, $vendorId, $invited, $actor) {
            $quotation = PurchaseQuotation::create([
                'tenant_id'       => $rfq->tenant_id,
                'purchase_rfq_id' => $rfq->id,
                'purchase_vendor_id'       => $vendorId,
                'created_by'      => $actor->id,
                'currency'        => $data['currency'] ?? $rfq->currency,
                'status'          => Status::RECEIVED,
                'valid_until'     => $data['valid_until'] ?? null,
                'received_at'     => now(),
                'notes'           => $data['notes'] ?? null,
            ]);
            $this->syncItems($quotation, $items);
            $quotation->recalcTotals();

            // Recipient responded; RFQ advances to Under_Review once quotes land.
            $invited->update(['status' => RfqVendorStatus::RESPONDED, 'responded_at' => now()]);
            if ($rfq->status === RfqStatus::SENT) {
                $rfq->update(['status' => RfqStatus::UNDER_REVIEW]);
            }

            return $quotation;
        });

        $quotation->recordAudit('Quotation Recorded', $actor, null, [
            'quotation_number' => $quotation->quotation_number, 'rfq' => $rfq->rfq_number, 'total' => $quotation->total,
        ]);
        Log::channel('purchase')->info('Quotation recorded', [
            'purchase_quotation_id' => $quotation->id, 'purchase_rfq_id' => $rfq->id, 'tenant_id' => $rfq->tenant_id,
        ]);

        return $quotation->fresh(['items', 'vendor']);
    }

    /**
     * A vendor submits its OWN quotation against an open RFQ, from the portal.
     *
     * Unlike record() (staff-entered, with a User actor), the actor here is the
     * PurchaseVendor itself: created_by is null and the audit is attributed by
     * label. One submission per vendor per RFQ — a vendor that has already
     * responded is blocked (they cannot edit a submitted quote from the portal).
     */
    public function submitByVendor(PurchaseRfq $rfq, \App\Models\Purchase\PurchaseVendor $vendor, array $data): PurchaseQuotation
    {
        if (! $rfq->isOpenForQuotes()) {
            throw new BusinessException("This RFQ is not open for quotes (status: {$rfq->status_label}).");
        }

        $invited = $rfq->rfqVendors()->where('purchase_vendor_id', $vendor->id)->first();
        if (! $invited) {
            throw new BusinessException('You are not on this RFQ’s recipient list.');
        }
        if ($invited->status === RfqVendorStatus::RESPONDED) {
            throw new BusinessException('You have already submitted a quotation for this RFQ.');
        }

        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new BusinessException('A quotation needs at least one line item.');
        }

        $quotation = DB::transaction(function () use ($rfq, $data, $items, $vendor, $invited) {
            $quotation = PurchaseQuotation::create([
                'tenant_id'          => $rfq->tenant_id,
                'purchase_rfq_id'    => $rfq->id,
                'purchase_vendor_id' => $vendor->id,
                'created_by'         => null,   // submitted by the vendor, not a staff user
                'currency'           => $data['currency'] ?? $rfq->currency,
                'status'             => Status::RECEIVED,
                'valid_until'        => $data['valid_until'] ?? null,
                'received_at'        => now(),
                'notes'              => $data['notes'] ?? null,
            ]);
            $this->syncItems($quotation, $items);
            $quotation->recalcTotals();

            $invited->update(['status' => RfqVendorStatus::RESPONDED, 'responded_at' => now()]);
            if ($rfq->status === RfqStatus::SENT) {
                $rfq->update(['status' => RfqStatus::UNDER_REVIEW]);
            }

            return $quotation;
        });

        $quotation->recordAudit('Quotation Submitted (portal)', null, null, [
            'quotation_number' => $quotation->quotation_number, 'rfq' => $rfq->rfq_number, 'total' => $quotation->total,
        ], $vendor->company_name);
        Log::channel('purchase')->info('Quotation submitted by vendor (portal)', [
            'purchase_quotation_id' => $quotation->id, 'purchase_rfq_id' => $rfq->id,
            'purchase_vendor_id' => $vendor->id, 'tenant_id' => $rfq->tenant_id,
        ]);

        return $quotation->fresh(['items']);
    }

    public function update(PurchaseQuotation $quotation, array $data, User $actor): PurchaseQuotation
    {
        $this->guardMutable($quotation);

        $items = $data['items'] ?? null;
        unset($data['items'], $data['purchase_vendor_id']);   // vendor is fixed once recorded

        DB::transaction(function () use ($quotation, $data, $items) {
            $quotation->update($data);
            if ($items !== null) {
                $quotation->items()->delete();
                $this->syncItems($quotation, $items);
                $quotation->load('items');
                $quotation->recalcTotals();
            }
        });

        $quotation->recordAudit('Quotation Updated', $actor);

        return $quotation->fresh(['items', 'vendor']);
    }

    /** Flag / unflag a quote as a contender. */
    public function shortlist(PurchaseQuotation $quotation, bool $on, User $actor): PurchaseQuotation
    {
        $this->guardMutable($quotation);
        $quotation->update(['status' => $on ? Status::SHORTLISTED : Status::RECEIVED]);
        $quotation->recordAudit($on ? 'Quotation Shortlisted' : 'Quotation Un-shortlisted', $actor);

        return $quotation->fresh();
    }

    public function reject(PurchaseQuotation $quotation, User $actor, ?string $remarks = null): PurchaseQuotation
    {
        if (! Status::isAwardable($quotation->status)) {
            throw new BusinessException("A {$quotation->status_label} quotation cannot be rejected.");
        }
        $quotation->update(['status' => Status::REJECTED]);
        $quotation->recordAudit('Quotation Rejected', $actor, $remarks);

        return $quotation->fresh();
    }

    /**
     * Award a quotation — the conversion to a PO.
     *
     * One transaction: build the Draft PO from the quote, mark the quote Awarded,
     * reject the sibling quotes still in contention, and move the RFQ to Awarded.
     * Admin-only (enforced on the route) — awarding commits spend.
     */
    public function award(PurchaseQuotation $quotation, User $actor): PurchaseOrder
    {
        if (! Status::isAwardable($quotation->status)) {
            throw new BusinessException("Only a Received or Shortlisted quotation can be awarded; this one is {$quotation->status_label}.");
        }

        $rfq = $quotation->rfq;
        if (! $rfq || ! RfqStatus::canTransition($rfq->status, RfqStatus::AWARDED)) {
            throw new BusinessException('This RFQ is not in a state that can be awarded.');
        }

        $po = DB::transaction(function () use ($quotation, $rfq, $actor) {
            $po = $this->purchaseOrderService->createFromQuotation($quotation, $actor);

            $quotation->update(['status' => Status::AWARDED]);

            // Every other in-contention quote on this RFQ loses.
            $rfq->quotations()
                ->whereKeyNot($quotation->id)
                ->whereIn('status', Status::IN_CONTENTION)
                ->update(['status' => Status::REJECTED]);

            $rfq->update(['status' => RfqStatus::AWARDED]);
            $rfq->recordAudit('RFQ Awarded', $actor, null, [
                'quotation' => $quotation->quotation_number, 'purchase_vendor_id' => $quotation->purchase_vendor_id, 'po_number' => $po->po_number,
            ]);
            $quotation->recordAudit('Quotation Awarded', $actor, null, ['po_number' => $po->po_number]);

            return $po;
        });

        Log::channel('purchase')->info('RFQ quotation awarded', [
            'purchase_quotation_id' => $quotation->id, 'purchase_rfq_id' => $rfq->id,
            'purchase_order_id' => $po->id, 'actor_id' => $actor->id, 'tenant_id' => $quotation->tenant_id,
        ]);

        return $po->fresh(['items']);
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function guardMutable(PurchaseQuotation $quotation): void
    {
        if (in_array($quotation->status, [Status::AWARDED, Status::REJECTED], true)) {
            throw new BusinessException("A {$quotation->status_label} quotation can no longer be changed.");
        }
    }

    private function syncItems(PurchaseQuotation $quotation, array $items): void
    {
        foreach ($items as $i => $item) {
            $quotation->items()->create([
                'tenant_id'            => $quotation->tenant_id,
                'purchase_rfq_item_id' => $item['purchase_rfq_item_id'] ?? null,
                'description'          => $item['description'] ?? 'Item',
                'qty'                  => $item['qty'] ?? 1,
                'unit'                 => $item['unit'] ?? null,
                'rate'                 => $item['rate'] ?? 0,
                'tax'                  => $item['tax'] ?? 0,
                'sort_order'           => $item['sort_order'] ?? $i,
            ]);
        }
    }
}
