<?php

namespace App\Repositories\Transport;

use App\Models\Transport\TransportOrder;
use App\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query layer for Transport Orders.
 *
 * Every method takes $tenantId and opens with ->forTenant(). Nothing here reads
 * auth(), so the repository stays usable from console commands, queued jobs and
 * tests where there is no authenticated user.
 */
class TransportOrderRepository extends BaseRepository
{
    protected string $modelClass = TransportOrder::class;

    /**
     * @param  array{status?:string,priority?:string,customer_id?:int,search?:string,per_page?:int}  $filters
     */
    public function filtered(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        $query = TransportOrder::forTenant($tenantId)
            ->with('customer:id,company')
            ->withCount('trips')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('order_status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['customer_id'] ?? null, fn ($q, $c) => $q->where('customer_id', $c))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('order_number', 'like', '%'.$term.'%')
                        ->orWhere('customer_reference', 'like', '%'.$term.'%')
                        ->orWhere('service_type', 'like', '%'.$term.'%')
                        ->orWhere('route', 'like', '%'.$term.'%');
                });
            });

        // Clamped so a client cannot request an unbounded page — the same guard
        // EmployeeRepository carries, for the same reason.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /** Tenant-scoped AT the point of lookup, so a cross-tenant id never resolves. */
    public function findForTenant(int $id, int $tenantId): ?TransportOrder
    {
        return TransportOrder::forTenant($tenantId)->find($id);
    }

    /** Counts per status, for the list's filter chips. */
    public function statusCounts(int $tenantId): array
    {
        return TransportOrder::forTenant($tenantId)
            ->selectRaw('order_status, COUNT(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status')
            ->toArray();
    }
}
