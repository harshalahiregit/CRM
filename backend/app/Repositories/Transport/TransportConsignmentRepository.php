<?php

namespace App\Repositories\Transport;

use App\Models\Transport\TransportConsignment;
use App\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query layer for Consignments.
 *
 * Every method takes $tenantId and opens with ->forTenant(). Nothing here reads
 * auth(), so the repository stays usable from console commands, queued jobs and
 * tests where there is no authenticated user.
 *
 * ── NO STATUS FILTER, AND THAT IS NOT AN OMISSION ────────────────────────
 * There is no `status` column (D-44) — STOS-CTD §11 puts consignment status in
 * a lifecycle engine that does not exist, and its values span five lifecycles
 * across two owners. So nothing here filters by it. When the engine arrives and
 * writes a derived column, the filter belongs here; until then a status filter
 * would have to be computed in PHP after paging, which silently returns the
 * wrong page of results.
 */
class TransportConsignmentRepository extends BaseRepository
{
    protected string $modelClass = TransportConsignment::class;

    /**
     * @param  array{order_id?:int,customer_id?:int,customer_reference?:string,search?:string,per_page?:int}  $filters
     */
    public function filtered(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        $query = TransportConsignment::forTenant($tenantId)
            ->with(['order:id,order_number,order_status', 'customer:id,company'])
            ->withCount('trips')
            ->when($filters['order_id'] ?? null, fn ($q, $o) => $q->where('order_id', $o))
            ->when($filters['customer_id'] ?? null, fn ($q, $c) => $q->where('customer_id', $c))
            // STOS-CTD §4 makes Customer Reference a search path of its own.
            // Exact, not LIKE: it is an identifier the customer gave us, and a
            // partial match would return somebody else's shipment.
            ->when($filters['customer_reference'] ?? null, fn ($q, $r) => $q->where('customer_reference', $r))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('consignment_number', 'like', '%'.$term.'%')
                        ->orWhere('customer_reference', 'like', '%'.$term.'%')
                        ->orWhere('cargo_description', 'like', '%'.$term.'%')
                        ->orWhere('service_type', 'like', '%'.$term.'%');
                });
            });

        // Clamped so a client cannot request an unbounded page — the same guard
        // TransportOrderRepository carries, for the same reason.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /** Tenant-scoped AT the point of lookup, so a cross-tenant id never resolves. */
    public function findForTenant(int $id, int $tenantId): ?TransportConsignment
    {
        return TransportConsignment::forTenant($tenantId)->find($id);
    }

    /** CTD-003 — every consignment on one order. */
    public function forOrder(int $orderId, int $tenantId)
    {
        return TransportConsignment::forTenant($tenantId)
            ->forOrder($orderId)
            ->orderBy('id')
            ->get();
    }
}
