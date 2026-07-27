<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseRequest;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Repositories\Purchase\PurchaseRequestRepository;
use App\Support\Purchase\PurchaseRequestStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRequestService
{
    public function __construct(
        private PurchaseRequestRepository $purchaseRequestRepository,
        private PurchaseCatalogService $catalogService,
        private PurchaseContractService $contractService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->purchaseRequestRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseRequest
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantId = $actor->tenant_id;
        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $tenantId);

        $pr = DB::transaction(function () use ($data, $items, $tenantId, $actor) {
            $pr = PurchaseRequest::create([
                ...$data,
                'tenant_id'    => $tenantId,
                'requested_by' => $actor->id,
                'status'       => Status::DRAFT,
            ]);
            $this->syncItems($pr, $items);
            $pr->recalcTotals();

            return $pr;
        });

        $pr->recordAudit('Purchase Request Created', $actor, null, ['pr_number' => $pr->pr_number]);

        Log::channel('purchase')->info('Purchase request created', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $tenantId,
        ]);

        // fresh(), not load() — column defaults (currency, priority) are applied by
        // the DB and never reach the in-memory instance, so without this the create
        // response omits fields that a subsequent GET returns.
        return $pr->fresh(['items']);
    }

    public function update(PurchaseRequest $pr, array $data, User $actor): PurchaseRequest
    {
        if (! $pr->isEditable()) {
            throw new BusinessException('Only a Draft purchase request can be edited.');
        }

        $items = $data['items'] ?? null;
        unset($data['items']);

        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $pr->tenant_id);

        DB::transaction(function () use ($pr, $data, $items) {
            $pr->update($data);
            if ($items !== null) {
                $pr->items()->delete();
                $this->syncItems($pr, $items);
            }
            $pr->load('items');
            $pr->recalcTotals();
        });

        $pr->recordAudit('Purchase Request Updated', $actor);

        Log::channel('purchase')->info('Purchase request updated', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id,
        ]);

        return $pr->fresh(['items']);
    }

    /** Draft → Submitted. Locks line items pending approval. */
    public function submit(PurchaseRequest $pr, User $actor): PurchaseRequest
    {
        if ($pr->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft purchase request can be submitted.');
        }
        if ($pr->items()->count() === 0) {
            throw new BusinessException('Add at least one line item before submitting.');
        }

        $pr->update(['status' => Status::SUBMITTED, 'submitted_at' => now()]);
        $pr->recordAudit('Purchase Request Submitted', $actor, null, ['from' => Status::DRAFT, 'to' => Status::SUBMITTED]);

        Log::channel('purchase')->info('Purchase request submitted', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id,
        ]);

        return $pr;
    }

    /** Submitted → Approved. */
    public function approve(PurchaseRequest $pr, User $actor, ?string $remarks = null): PurchaseRequest
    {
        if ($pr->status !== Status::SUBMITTED) {
            throw new BusinessException('Only a Submitted purchase request can be approved.');
        }

        $pr->update([
            'status'      => Status::APPROVED,
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'remarks'     => $remarks ?? $pr->remarks,
        ]);
        $pr->recordAudit('Purchase Request Approved', $actor, $remarks, ['from' => Status::SUBMITTED, 'to' => Status::APPROVED]);

        Log::channel('purchase')->info('Purchase request approved', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $pr;
    }

    /** Submitted → Rejected. */
    public function reject(PurchaseRequest $pr, User $actor, ?string $remarks = null): PurchaseRequest
    {
        if ($pr->status !== Status::SUBMITTED) {
            throw new BusinessException('Only a Submitted purchase request can be rejected.');
        }

        $pr->update(['status' => Status::REJECTED, 'remarks' => $remarks ?? $pr->remarks]);
        $pr->recordAudit('Purchase Request Rejected', $actor, $remarks, ['from' => Status::SUBMITTED, 'to' => Status::REJECTED]);

        Log::channel('purchase')->info('Purchase request rejected', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $pr;
    }

    public function destroy(PurchaseRequest $pr): void
    {
        $pr->delete();

        Log::channel('purchase')->info('Purchase request deleted', [
            'purchase_request_id' => $pr->id, 'tenant_id' => $pr->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'     => PurchaseRequest::forTenant($tenantId)->count(),
            'draft'     => PurchaseRequest::forTenant($tenantId)->where('status', Status::DRAFT)->count(),
            'submitted' => PurchaseRequest::forTenant($tenantId)->where('status', Status::SUBMITTED)->count(),
            'approved'  => PurchaseRequest::forTenant($tenantId)->where('status', Status::APPROVED)->count(),
            'rejected'  => PurchaseRequest::forTenant($tenantId)->where('status', Status::REJECTED)->count(),
            'value'     => PurchaseRequest::forTenant($tenantId)->whereIn('status', [Status::APPROVED, Status::CONVERTED])->sum('total'),
        ];
    }

    /**
     * Build the PR's lines with catalog snapshotting and contract rate-pull. Same
     * precedence as a PO (catalog fills blanks; an Active in-window contract rate
     * is authoritative and flags the line) — but a PR is a request, not a
     * commitment, so nothing is booked against the ceiling and no contract link is
     * stamped. The pulled rate + flag simply carry forward when it converts to a PO.
     */
    private function syncItems(PurchaseRequest $pr, array $items): void
    {
        $tenantId = $pr->tenant_id;
        $resolution = $this->contractService->resolveDocumentContract($tenantId, $pr->purchase_vendor_id, $items);
        $rateMap = $resolution['rate_map'] ?? [];

        foreach ($items as $i => $item) {
            $catalogId = $item['catalog_item_id'] ?? null;
            $snapshot  = $this->catalogService->snapshotForLine($catalogId ? (int) $catalogId : null, $tenantId);

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
                $line['rate'] = $entry['rate'];
                $line['tax']  = $entry['tax'];
                $line['contract_rate_applied'] = true;
            }

            $pr->items()->create($line);
        }
    }

    /** Return the line's own value for a key when present & non-empty, else the fallback. */
    private function pick(array $item, string $key, $fallback)
    {
        return array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== ''
            ? $item[$key]
            : $fallback;
    }

    /**
     * A PR may only name a vendor from the caller's own tenant that is Active.
     * Guards both cross-tenant reference and transacting with a blacklisted vendor.
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
