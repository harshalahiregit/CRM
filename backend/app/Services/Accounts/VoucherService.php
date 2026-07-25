<?php

namespace App\Services\Accounts;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Voucher;

/**
 * Read-side for vouchers (list + show). All posting/cancellation goes through
 * PostingService / ReversalService — this service never writes to the ledger.
 */
class VoucherService
{
    public function list(int $tenantId, array $filters)
    {
        $query = Voucher::forTenant($tenantId)
            ->with('voucherType:id,code,name')
            ->withCount('lines')
            ->withCount('reversedBy');   // >0 ⇒ this voucher has been reversed

        if (! empty($filters['type'])) {
            $query->whereHas('voucherType', fn ($q) => $q->where('code', $filters['type']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(fn ($q) => $q->where('number', 'like', "%{$term}%")
                ->orWhere('narration', 'like', "%{$term}%")
                ->orWhere('reference_no', 'like', "%{$term}%"));
        }

        return $query->orderByDesc('date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function show(Voucher $voucher, int $tenantId): Voucher
    {
        if ($voucher->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }

        return $voucher->load(['lines.ledger:id,name', 'voucherType:id,code,name', 'financialYear', 'creator:id,name', 'reversedVoucher:id,number', 'reversedBy:id,number,reversed_voucher_id']);
    }
}
