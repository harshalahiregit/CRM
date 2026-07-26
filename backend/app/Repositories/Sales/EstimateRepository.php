<?php

namespace App\Repositories\Sales;

use App\Models\Sales\Estimate;
use App\Repositories\BaseRepository;

class EstimateRepository extends BaseRepository
{
    protected string $modelClass = Estimate::class;

    public function filtered(int $tenantId, ?string $status, ?string $type = null)
    {
        $query = Estimate::forTenant($tenantId)->with('lineItems');

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }
        if ($type) {
            $query->where('estimate_type', $type);
        }

        return $query->latest()->get();
    }
}
