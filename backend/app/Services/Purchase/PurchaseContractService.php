<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseContract;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Repositories\Purchase\PurchaseContractRepository;
use App\Support\Purchase\PurchaseContractStatus as Status;
use App\Support\Purchase\PurchaseContractType as Type;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseContractService
{
    private const DISK = 'contract_docs';

    public function __construct(private PurchaseContractRepository $contractRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->contractRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseContract
    {
        $items = $data['items'] ?? [];
        unset($data['items']);
        $tenantId = $actor->tenant_id;
        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $tenantId);

        $contract = DB::transaction(function () use ($data, $items, $tenantId, $actor) {
            $contract = PurchaseContract::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $actor->id,
                'status'     => Status::DRAFT,
            ]);
            $this->syncItems($contract, $items);

            return $contract;
        });

        $contract->recordAudit('Contract Created', $actor, null, ['contract_number' => $contract->contract_number]);
        Log::channel('purchase')->info('Purchase contract created', ['purchase_contract_id' => $contract->id, 'tenant_id' => $tenantId]);

        return $contract->fresh(['items', 'vendor']);
    }

    public function update(PurchaseContract $contract, array $data, User $actor): PurchaseContract
    {
        // Metadata (notes) may be corrected at any time; the substance — rates,
        // dates, type, ceiling — freezes once the contract leaves the editable
        // states, because a live contract's terms must not shift underfoot.
        $touchesSubstance = array_intersect(array_keys($data), ['items', 'start_date', 'end_date', 'spend_ceiling', 'type', 'currency', 'terms', 'title']);
        if ($touchesSubstance && ! $contract->isEditable()) {
            throw new BusinessException(
                "A {$contract->status_label} contract's terms cannot be changed. Clone it into a new draft to renegotiate."
            );
        }

        $items = $data['items'] ?? null;
        unset($data['items']);
        $this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $contract->tenant_id);

        DB::transaction(function () use ($contract, $data, $items) {
            $contract->update($data);
            if ($items !== null) {
                $contract->items()->delete();
                $this->syncItems($contract, $items);
            }
        });

        $contract->recordAudit('Contract Updated', $actor);

        return $contract->fresh(['items', 'vendor']);
    }

    /** Draft → Under_Review. */
    public function submit(PurchaseContract $contract, User $actor): PurchaseContract
    {
        if ($contract->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft contract can be submitted for review.');
        }
        $this->assertActivationShape($contract);

        $contract->update(['status' => Status::UNDER_REVIEW]);
        $contract->recordAudit('Contract Submitted', $actor, null, ['to' => Status::UNDER_REVIEW]);

        return $contract->fresh(['items', 'vendor']);
    }

    /** Under_Review → Active. Admin-only (route-gated) — this makes it binding. */
    public function activate(PurchaseContract $contract, User $actor): PurchaseContract
    {
        if (! Status::canTransition($contract->status, Status::ACTIVE)) {
            throw new BusinessException("A {$contract->status_label} contract cannot be activated.");
        }
        $this->assertActivationShape($contract);
        $this->assertVendorEngageable($contract->purchase_vendor_id, $contract->tenant_id);

        $contract->update(['status' => Status::ACTIVE, 'approved_at' => now(), 'approved_by' => $actor->id]);
        $contract->recordAudit('Contract Activated', $actor, null, ['from' => Status::UNDER_REVIEW, 'to' => Status::ACTIVE]);
        Log::channel('purchase')->info('Purchase contract activated', ['purchase_contract_id' => $contract->id, 'actor_id' => $actor->id]);

        return $contract->fresh(['items', 'vendor']);
    }

    /** Back to Draft to correct a submitted contract. */
    public function returnToDraft(PurchaseContract $contract, User $actor): PurchaseContract
    {
        if (! Status::canTransition($contract->status, Status::DRAFT)) {
            throw new BusinessException("A {$contract->status_label} contract cannot be returned to draft.");
        }
        $contract->update(['status' => Status::DRAFT]);
        $contract->recordAudit('Contract Returned to Draft', $actor);

        return $contract->fresh(['items', 'vendor']);
    }

    /** End early. Admin-only. */
    public function terminate(PurchaseContract $contract, User $actor, ?string $reason = null): PurchaseContract
    {
        if (! Status::canTransition($contract->status, Status::TERMINATED)) {
            throw new BusinessException("A {$contract->status_label} contract cannot be terminated.");
        }
        $contract->update(['status' => Status::TERMINATED, 'notes' => $reason ?? $contract->notes]);
        $contract->recordAudit('Contract Terminated', $actor, $reason, ['to' => Status::TERMINATED]);

        return $contract->fresh();
    }

    /** Attach / replace the signed agreement document. */
    public function uploadDocument(PurchaseContract $contract, UploadedFile $file, User $actor): PurchaseContract
    {
        if ($contract->document_path && Storage::disk(self::DISK)->exists($contract->document_path)) {
            Storage::disk(self::DISK)->delete($contract->document_path);
        }
        $name = 'contract-'.Str::random(12).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs("tenant-{$contract->tenant_id}", $name, self::DISK);

        $contract->update(['document_path' => $path]);
        $contract->recordAudit('Contract Document Uploaded', $actor);

        return $contract->fresh();
    }

    public function resolveDownload(PurchaseContract $contract): array
    {
        if (! $contract->document_path || ! Storage::disk(self::DISK)->exists($contract->document_path)) {
            throw new BusinessException('No document on this contract.', 404);
        }
        $path = Storage::disk(self::DISK)->path($contract->document_path);

        return ['path' => $path, 'filename' => 'Contract-'.$contract->contract_number.'.'.pathinfo($path, PATHINFO_EXTENSION), 'mime' => mime_content_type($path) ?: 'application/octet-stream'];
    }

    public function delete(PurchaseContract $contract): void
    {
        if ($contract->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft contract can be deleted. Terminate an active one instead.');
        }
        $contract->delete();
    }

    /** Active, in-window rate contracts for a vendor — powers a PO's contract picker. */
    public function referenceableForVendor(int $tenantId, int $vendorId): array
    {
        return PurchaseContract::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->referenceable()
            ->with('items')
            ->get()->all();
    }

    /**
     * Pick the single Active, in-window contract a PO/PR should draw rates from,
     * and expose its per-catalog-item rate card. A document draws from at most one
     * contract (keeps ceiling attribution unambiguous); when a vendor has several,
     * the one covering the most of the document's catalog lines wins, tie-broken by
     * the soonest to expire (use the scarcer agreement first).
     *
     * $lines: the raw line payload (each may carry catalog_item_id + qty).
     * Returns ['contract' => PurchaseContract, 'rate_map' => [catalog_item_id => [rate,tax,min_qty,max_qty]]]
     * or null when nothing matches.
     */
    public function resolveDocumentContract(int $tenantId, ?int $vendorId, array $lines): ?array
    {
        if (! $vendorId) {
            return null;
        }

        $catalogIds = collect($lines)
            ->pluck('catalog_item_id')->filter()->map(fn ($x) => (int) $x)->unique()->all();
        if (empty($catalogIds)) {
            return null;
        }

        $best = null;
        $bestCover = 0;
        foreach ($this->referenceableForVendor($tenantId, $vendorId) as $contract) {
            $cover = $contract->items
                ->filter(fn ($ci) => $ci->catalog_item_id && in_array((int) $ci->catalog_item_id, $catalogIds, true))
                ->count();
            if ($cover === 0) {
                continue;
            }
            // More coverage wins; tie → the contract expiring soonest (null end = last).
            $better = $cover > $bestCover
                || ($cover === $bestCover && $best && $this->expiresSooner($contract, $best));
            if ($better) {
                $best = $contract;
                $bestCover = $cover;
            }
        }

        if (! $best) {
            return null;
        }

        $rateMap = [];
        foreach ($best->items as $ci) {
            if (! $ci->catalog_item_id) {
                continue;
            }
            $rateMap[(int) $ci->catalog_item_id] = [
                'rate'    => (float) $ci->rate,
                'tax'     => (float) $ci->tax,
                'min_qty' => $ci->min_qty !== null ? (float) $ci->min_qty : null,
                'max_qty' => $ci->max_qty !== null ? (float) $ci->max_qty : null,
            ];
        }

        return ['contract' => $best, 'rate_map' => $rateMap];
    }

    /**
     * Whether a rate-map entry applies to a line of the given quantity — the
     * locked rate only holds inside the contract's optional min/max band.
     */
    public function rateAppliesToQty(array $entry, float $qty): bool
    {
        if ($entry['min_qty'] !== null && $qty < $entry['min_qty']) {
            return false;
        }
        if ($entry['max_qty'] !== null && $qty > $entry['max_qty']) {
            return false;
        }

        return true;
    }

    /**
     * Book PO spend against a contract's ceiling inside the caller's transaction.
     * Locks the contract row, hard-blocks a breach of a capped ceiling, then
     * increments consumed_amount. No-op for an uncapped contract (tracks nothing).
     * Caller must run this inside DB::transaction().
     */
    public function bookConsumption(int $contractId, int $tenantId, float $amount, User $actor, string $ref): PurchaseContract
    {
        $contract = PurchaseContract::forTenant($tenantId)->lockForUpdate()->findOrFail($contractId);

        // The contract must still be genuinely in force at issue time.
        if (! $contract->isReferenceable()) {
            throw new BusinessException("Contract {$contract->contract_number} is {$contract->status_label} and can no longer be drawn on.");
        }

        if ($contract->spend_ceiling !== null) {
            $remaining = (float) $contract->spend_ceiling - (float) $contract->consumed_amount;
            if ($amount > $remaining + 0.01) {
                throw new BusinessException(sprintf(
                    'Issuing would exceed contract %s ceiling. Remaining %s, this order %s.',
                    $contract->contract_number,
                    number_format($remaining, 2),
                    number_format($amount, 2),
                ));
            }
        }

        $contract->update(['consumed_amount' => (float) $contract->consumed_amount + $amount]);
        $contract->recordAudit('Contract Consumption Booked', $actor, null, [
            'amount' => round($amount, 2), 'ref' => $ref, 'consumed_after' => (float) $contract->consumed_amount,
        ]);

        return $contract;
    }

    /**
     * Release a previously-booked hold when an issued PO is cancelled/closed.
     * Floors at zero so a manual adjustment can't drive consumption negative.
     * Caller must run this inside DB::transaction().
     */
    public function releaseConsumption(int $contractId, int $tenantId, float $amount, User $actor, string $ref): void
    {
        $contract = PurchaseContract::forTenant($tenantId)->lockForUpdate()->find($contractId);
        if (! $contract) {
            return;
        }
        $after = max(0, (float) $contract->consumed_amount - $amount);
        $contract->update(['consumed_amount' => $after]);
        $contract->recordAudit('Contract Consumption Released', $actor, null, [
            'amount' => round($amount, 2), 'ref' => $ref, 'consumed_after' => $after,
        ]);
    }

    private function expiresSooner(PurchaseContract $a, PurchaseContract $b): bool
    {
        if ($a->end_date === null) {
            return false;
        }
        if ($b->end_date === null) {
            return true;
        }

        return $a->end_date->lt($b->end_date);
    }

    /**
     * Persist Active → Expired for contracts past their end date. Idempotent —
     * safe to run from cron nightly. Returns how many it swept.
     */
    public function sweepExpired(?int $tenantId = null): int
    {
        $query = PurchaseContract::query()
            ->where('status', Status::ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now());
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $count = 0;
        foreach ($query->get() as $contract) {
            $contract->update(['status' => Status::EXPIRED]);
            $contract->recordAudit('Contract Expired', null, 'Automatically expired past end date');
            $count++;
        }

        return $count;
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseContract::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'draft'        => $base()->where('status', Status::DRAFT)->count(),
            'under_review' => $base()->where('status', Status::UNDER_REVIEW)->count(),
            'active'       => $base()->where('status', Status::ACTIVE)->count(),
            'expired'      => $base()->where('status', Status::EXPIRED)->count(),
            // Active contracts within 30 days of expiry — the renewal watch list.
            'expiring_soon' => $base()->where('status', Status::ACTIVE)
                                    ->whereNotNull('end_date')
                                    ->whereDate('end_date', '>=', now())
                                    ->whereDate('end_date', '<=', now()->addDays(30))->count(),
        ];
    }

    private function syncItems(PurchaseContract $contract, array $items): void
    {
        foreach ($items as $i => $item) {
            $contract->items()->create([
                'tenant_id'   => $contract->tenant_id,
                'description' => $item['description'] ?? 'Item',
                'unit'        => $item['unit'] ?? null,
                'rate'        => $item['rate'] ?? 0,
                'tax'         => $item['tax'] ?? 0,
                'min_qty'     => $item['min_qty'] ?? null,
                'max_qty'     => $item['max_qty'] ?? null,
                'sort_order'  => $item['sort_order'] ?? $i,
            ]);
        }
    }

    /** Shared validity gate for submit + activate. */
    private function assertActivationShape(PurchaseContract $contract): void
    {
        if (! $contract->start_date || ! $contract->end_date) {
            throw new BusinessException('Set both a start and end date before submitting the contract.');
        }
        if (Carbon::parse($contract->end_date)->lte(Carbon::parse($contract->start_date))) {
            throw new BusinessException('The end date must be after the start date.');
        }
        if (Type::requiresRateLines($contract->type) && $contract->items()->count() === 0) {
            throw new BusinessException('A rate contract needs at least one rate line. Add lines or use the MSA type.');
        }
    }

    /** Mirrors the other purchase services — cross-tenant + non-active vendor guard. */
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
            throw new BusinessException("Vendor {$vendor->purchase_vendor_code} is {$vendor->status_label} and cannot hold a contract.");
        }
    }
}
