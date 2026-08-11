<?php

namespace App\Repositories\Vendor;

use App\Models\Vendor\Vendor;
use App\Repositories\BaseRepository;

class VendorRepository extends BaseRepository
{
    protected string $modelClass = Vendor::class;

    /**
     * Columns a client may sort by.
     *
     * A whitelist, not the request string: `orderBy` interpolates its column
     * into the SQL, so accepting whatever arrives would be an injection point.
     * Anything not listed falls back to the default ordering rather than
     * erroring — a bad sort key is not worth failing a page load over.
     */
    private const SORTABLE = [
        'id', 'vendor_code', 'company_name', 'email', 'phone',
        'vendor_type', 'registration_type', 'status', 'created_at',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * Pagination is OPT-IN, keyed on `per_page` being present. Six callers
     * already read this endpoint expecting a bare array — the task-form vendor
     * picker destructures it directly — so switching everyone to a paginated
     * envelope would break them. Callers that want pages ask for them.
     */
    public function filtered(int $tenantId, array $filters)
    {
        $query = Vendor::forTenant($tenantId)->with(['primaryContact', 'accountManager:id,name', 'user:id,name,email,status']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_type']) && $filters['vendor_type'] !== 'All') {
            $query->where('vendor_type', $filters['vendor_type']);
        }
        if (! empty($filters['category']) && $filters['category'] !== 'All') {
            $query->where('category', $filters['category']);
        }
        // 'purchase' | 'tpv' — which module's vendor list is being viewed.
        if (! empty($filters['engagement'])) {
            $query->forEngagement($filters['engagement']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', '%'.$search.'%')
                  ->orWhere('vendor_code', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%')
                  ->orWhere('gst_number', 'like', '%'.$search.'%')
                  ->orWhere('pan_number', 'like', '%'.$search.'%');
            });
        }

        // Sorting. `latest()` stays the default so an unsorted request returns
        // exactly the order it always has.
        $column = $filters['sort_column'] ?? null;
        if ($column && in_array($column, self::SORTABLE, true)) {
            $direction = strtolower($filters['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($column, $direction);
        } else {
            $query->latest();
        }

        if (! empty($filters['per_page'])) {
            // Bounded: an unbounded per_page lets one request pull the whole
            // table and undoes the point of paginating.
            $perPage = min(max((int) $filters['per_page'], 1), 200);

            return $query->paginate($perPage)->appends($filters);
        }

        return $query->get();
    }
}
