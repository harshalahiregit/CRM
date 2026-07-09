<?php

namespace App\Repositories\Sales;

use App\Models\Sales\SalesInvoice;
use App\Repositories\BaseRepository;

class InvoiceRepository extends BaseRepository
{
    protected string $modelClass = SalesInvoice::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = SalesInvoice::forTenant($tenantId)->with(['lineItems', 'payments']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        return $query->latest()->get();
    }
}
