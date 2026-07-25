<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseRfq;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Purchase\PurchaseRfqRepository;
use App\Support\Purchase\PurchaseQuotationStatus as QuoteStatus;
use App\Support\Purchase\PurchaseRfqStatus as Status;
use App\Support\Purchase\RfqVendorStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRfqService
{
    public function __construct(
        private PurchaseRfqRepository $rfqRepository,
        private PurchaseCatalogService $catalogService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->rfqRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseRfq
    {
        $items   = $data['items'] ?? [];
        $vendors = $data['vendor_ids'] ?? [];
        unset($data['items'], $data['vendor_ids']);
        $tenantId = $actor->tenant_id;

        $rfq = DB::transaction(function () use ($data, $items, $vendors, $tenantId, $actor) {
            $rfq = PurchaseRfq::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $actor->id,
                'status'     => Status::DRAFT,
            ]);
            $this->syncItems($rfq, $items);
            $this->syncVendors($rfq, $vendors, $tenantId);

            return $rfq;
        });

        $rfq->recordAudit('RFQ Created', $actor, null, ['rfq_number' => $rfq->rfq_number]);
        Log::channel('purchase')->info('RFQ created', ['purchase_rfq_id' => $rfq->id, 'tenant_id' => $tenantId]);

        return $rfq->fresh(['items', 'rfqVendors.vendor']);
    }

    public function update(PurchaseRfq $rfq, array $data, User $actor): PurchaseRfq
    {
        if (! $rfq->isEditable()) {
            throw new BusinessException('Only a Draft RFQ can be edited.');
        }

        $items   = $data['items'] ?? null;
        $vendors = $data['vendor_ids'] ?? null;
        unset($data['items'], $data['vendor_ids']);

        DB::transaction(function () use ($rfq, $data, $items, $vendors) {
            $rfq->update($data);
            if ($items !== null) {
                $rfq->items()->delete();
                $this->syncItems($rfq, $items);
            }
            if ($vendors !== null) {
                $rfq->rfqVendors()->delete();
                $this->syncVendors($rfq, $vendors, $rfq->tenant_id);
            }
        });

        $rfq->recordAudit('RFQ Updated', $actor);

        return $rfq->fresh(['items', 'rfqVendors.vendor']);
    }

    /**
     * Draft → Sent. Requires at least one line and one vendor — an RFQ with
     * nobody to ask, or nothing to ask for, is not a real request.
     */
    public function send(PurchaseRfq $rfq, User $actor): PurchaseRfq
    {
        if ($rfq->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft RFQ can be sent.');
        }
        if ($rfq->items()->count() === 0) {
            throw new BusinessException('Add at least one line item before sending.');
        }
        if ($rfq->rfqVendors()->count() === 0) {
            throw new BusinessException('Add at least one vendor before sending.');
        }

        $rfq->update(['status' => Status::SENT, 'sent_at' => now()]);
        $rfq->recordAudit('RFQ Sent', $actor, null, ['vendors' => $rfq->rfqVendors()->count()]);
        Log::channel('purchase')->info('RFQ sent', ['purchase_rfq_id' => $rfq->id, 'actor_id' => $actor->id]);

        return $rfq->fresh(['items', 'rfqVendors.vendor']);
    }

    public function cancel(PurchaseRfq $rfq, User $actor, ?string $remarks = null): PurchaseRfq
    {
        if ($rfq->status === Status::AWARDED) {
            throw new BusinessException('An awarded RFQ cannot be cancelled.');
        }
        if ($rfq->status === Status::CANCELLED) {
            throw new BusinessException('This RFQ is already cancelled.');
        }

        $rfq->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $rfq->notes]);
        $rfq->recordAudit('RFQ Cancelled', $actor, $remarks, ['to' => Status::CANCELLED]);

        return $rfq->fresh();
    }

    public function destroy(PurchaseRfq $rfq): void
    {
        if ($rfq->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft RFQ can be deleted.');
        }

        $rfq->delete();
        Log::channel('purchase')->info('RFQ deleted', ['purchase_rfq_id' => $rfq->id, 'tenant_id' => $rfq->tenant_id]);
    }

    /**
     * The comparison matrix: each RFQ line across each in-contention quotation,
     * with the lowest rate per line flagged. This is the read behind the compare
     * view — it computes, it does not mutate (there is no "Compared" state).
     */
    public function comparison(PurchaseRfq $rfq): array
    {
        $rfq->loadMissing([
            'items',
            'quotations' => fn ($q) => $q->whereIn('status', [QuoteStatus::RECEIVED, QuoteStatus::SHORTLISTED, QuoteStatus::AWARDED])
                                          ->with(['vendor:id,vendor_code,company_name', 'items']),
        ]);

        $quotes = $rfq->quotations;

        // Index quote lines by rfq_item_id for O(1) lookup per cell.
        $byRfqItem = [];
        foreach ($quotes as $q) {
            foreach ($q->items as $qi) {
                if ($qi->purchase_rfq_item_id) {
                    $byRfqItem[$qi->purchase_rfq_item_id][$q->id] = $qi;
                }
            }
        }

        $rows = $rfq->items->map(function ($rfqItem) use ($quotes, $byRfqItem) {
            $cells = [];
            $lowest = null;
            foreach ($quotes as $q) {
                $qi = $byRfqItem[$rfqItem->id][$q->id] ?? null;
                $rate = $qi ? (float) $qi->rate : null;
                $cells[$q->id] = $rate === null ? null : ['rate' => $rate, 'amount' => (float) $qi->amount];
                if ($rate !== null && ($lowest === null || $rate < $lowest)) {
                    $lowest = $rate;
                }
            }

            return [
                'rfq_item_id' => $rfqItem->id,
                'description' => $rfqItem->description,
                'qty'         => (float) $rfqItem->qty,
                'unit'        => $rfqItem->unit,
                'target_rate' => $rfqItem->target_rate !== null ? (float) $rfqItem->target_rate : null,
                'lowest_rate' => $lowest,
                'cells'       => $cells,   // keyed by quotation id
            ];
        })->all();

        return [
            'rfq' => ['id' => $rfq->id, 'rfq_number' => $rfq->rfq_number, 'title' => $rfq->title, 'currency' => $rfq->currency],
            'quotations' => $quotes->map(fn ($q) => [
                'id' => $q->id, 'quotation_number' => $q->quotation_number,
                'vendor' => $q->vendor?->company_name, 'vendor_code' => $q->vendor?->vendor_code,
                'total' => (float) $q->total, 'status' => $q->status, 'valid_until' => $q->valid_until,
            ])->all(),
            'rows' => $rows,
            // The cheapest overall total, so the UI can flag the leading quote.
            'lowest_total_quotation_id' => $quotes->sortBy('total')->first()?->id,
        ];
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseRfq::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'draft'        => $base()->where('status', Status::DRAFT)->count(),
            'sent'         => $base()->where('status', Status::SENT)->count(),
            'under_review' => $base()->where('status', Status::UNDER_REVIEW)->count(),
            'awarded'      => $base()->where('status', Status::AWARDED)->count(),
        ];
    }

    private function syncItems(PurchaseRfq $rfq, array $items): void
    {
        foreach ($items as $i => $item) {
            // A catalog pick snapshots the item's values into the line (and
            // validates it's an Active item in-tenant). Explicit line values win
            // over the snapshot, so a buyer can still tweak qty/target after
            // picking; the catalog_item_id stays as a soft link for traceability.
            $snap = $this->catalogService->snapshotForLine($item['catalog_item_id'] ?? null, $rfq->tenant_id);

            $rfq->items()->create([
                'tenant_id'       => $rfq->tenant_id,
                'catalog_item_id' => $snap['catalog_item_id'] ?? null,
                'description'     => $item['description'] ?? $snap['description'] ?? 'Item',
                'qty'             => $item['qty'] ?? 1,
                'unit'            => $item['unit'] ?? $snap['unit'] ?? null,
                'target_rate'     => $item['target_rate'] ?? $snap['rate'] ?? null,
                'tax'             => $item['tax'] ?? $snap['tax'] ?? 0,
                'sort_order'      => $item['sort_order'] ?? $i,
            ]);
        }
    }

    /** Attach recipient vendors — each verified in-tenant and engageable. */
    private function syncVendors(PurchaseRfq $rfq, array $vendorIds, int $tenantId): void
    {
        foreach (array_unique($vendorIds) as $vendorId) {
            $vendor = Vendor::forTenant($tenantId)->find($vendorId);
            if (! $vendor) {
                throw new BusinessException("Vendor #{$vendorId} not found.", 404);
            }
            if (! $vendor->isEngageable()) {
                throw new BusinessException("Vendor {$vendor->vendor_code} is {$vendor->status_label} and cannot be invited.");
            }
            $rfq->rfqVendors()->create([
                'tenant_id' => $tenantId,
                'vendor_id' => $vendorId,
                'status'    => RfqVendorStatus::INVITED,
            ]);
        }
    }
}
