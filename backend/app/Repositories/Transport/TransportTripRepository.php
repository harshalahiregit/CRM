<?php

namespace App\Repositories\Transport;

use App\Models\Transport\TransportTrip;
use App\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query layer for Trips.
 */
class TransportTripRepository extends BaseRepository
{
    protected string $modelClass = TransportTrip::class;

    /**
     * @param  array{status?:string,order_id?:int,customer_id?:int,open?:bool,search?:string,per_page?:int}  $filters
     */
    public function filtered(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        $query = TransportTrip::forTenant($tenantId)
            ->with(['customer:id,company', 'order:id,order_number,service_type'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['order_id'] ?? null, fn ($q, $o) => $q->where('order_id', $o))
            ->when($filters['customer_id'] ?? null, fn ($q, $c) => $q->where('customer_id', $c))
            ->when(($filters['open'] ?? false), fn ($q) => $q->open())
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('trip_number', 'like', '%'.$term.'%')
                        ->orWhere('route', 'like', '%'.$term.'%');
                });
            });

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findForTenant(int $id, int $tenantId): ?TransportTrip
    {
        return TransportTrip::forTenant($tenantId)->find($id);
    }

    /**
     * Does this order already have a live trip?
     *
     * TRP-P0-001: "no duplicate active trip against same shipment". A closed
     * trip does not block a replacement; an open one does.
     */
    public function hasOpenTripForOrder(int $tenantId, int $orderId): bool
    {
        return TransportTrip::forTenant($tenantId)
            ->forOrder($orderId)
            ->open()
            ->exists();
    }

    public function statusCounts(int $tenantId): array
    {
        return TransportTrip::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }
}
