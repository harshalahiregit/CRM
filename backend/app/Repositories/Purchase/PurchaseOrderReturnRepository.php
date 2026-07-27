<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseOrderReturn;
use App\Repositories\BaseRepository;
use App\Support\Purchase\PurchaseOrderReturnStatus as Status;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderReturnRepository extends BaseRepository
{
    protected string $modelClass = PurchaseOrderReturn::class;

    /**
     * Tenant-scoped list for the Order Returns screen. Filters mirror the UI:
     * From/To date, Vendor, Status, free-text search — plus page size.
     */
    public function filtered(int $tenantId, array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrderReturn::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'order:id,po_number']);

        if (! empty($filters['purchase_vendor_id']) && $filters['purchase_vendor_id'] !== 'All') {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        // The screen's From/To range filters on the return date, falling back to
        // the created date for rows entered without an explicit return date.
        if (! empty($filters['from_date'])) {
            $query->whereDate(DB::raw('COALESCE(return_date, created_at)'), '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate(DB::raw('COALESCE(return_date, created_at)'), '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('or_number', 'like', "%{$s}%")
                  ->orWhere('reason', 'like', "%{$s}%")
                  ->orWhere('notes', 'like', "%{$s}%")
                  ->orWhereHas('vendor', fn ($v) => $v->where('company_name', 'like', "%{$s}%")
                      ->orWhere('purchase_vendor_code', 'like', "%{$s}%"));
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = $perPage > 0 ? min($perPage, 200) : 25;

        return $query->latest()->paginate($perPage);
    }

    public function findForTenant(int $id, int $tenantId): ?PurchaseOrderReturn
    {
        return PurchaseOrderReturn::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'order:id,po_number', 'items', 'creator:id,name'])
            ->find($id);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseOrderReturn::forTenant($tenantId);

        return [
            'total'     => $base()->count(),
            'draft'     => $base()->where('status', Status::DRAFT)->count(),
            'issued'    => $base()->where('status', Status::ISSUED)->count(),
            'completed' => $base()->where('status', Status::COMPLETED)->count(),
            'value'     => (float) $base()->whereIn('status', [Status::ISSUED, Status::COMPLETED])->sum('total'),
        ];
    }

    /**
     * Next order-return number. Follows the Settings prefix/next-number once the
     * tenant has configured them; otherwise keeps the original OR-YYYY-#### format.
     */
    public function nextNumber(int $tenantId): string
    {
        return \App\Support\Purchase\PurchaseNumbering::next(
            $tenantId,
            'pur_order_return_number_prefix',
            'next_pur_order_return_number',
            function () use ($tenantId) {
                $year = date('Y');
                $seq = PurchaseOrderReturn::withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->whereYear('created_at', $year)
                    ->count() + 1;

                return 'OR-'.$year.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            },
        );
    }
}
