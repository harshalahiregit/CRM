<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\Estimate;
use App\Models\Proposal;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;

class SalesDashboardService
{
    public function getDashboard(int $tenantId): array
    {
        $invoices  = SalesInvoice::forTenant($tenantId)->get();
        $proposals = Proposal::forTenant($tenantId)->get();
        $estimates = Estimate::forTenant($tenantId)->get();
        $cns       = CreditNote::forTenant($tenantId)->get();
        $payments  = SalesPayment::forTenant($tenantId)
                        ->whereYear('date', now()->year)
                        ->get();

        $revenueByMonth = SalesPayment::forTenant($tenantId)
            ->whereYear('date', now()->year)
            ->get()
            ->groupBy(fn($p) => $p->date->format('M'))
            ->map(fn($group) => ['amount' => $group->sum('amount')])
            ->map(fn($v, $k) => ['month' => $k, 'amount' => $v['amount']]);

        $conversionRate = $proposals->count() > 0 ? round(($invoices->count() / max($proposals->count(), 1)) * 100) : 0;

        $topClients = [
            ['name' => 'Acme Corporation', 'revenue' => $payments->sum('amount') * 0.6],
            ['name' => 'Globex Inc.',      'revenue' => $payments->sum('amount') * 0.3],
            ['name' => 'Soylent Corp',     'revenue' => $payments->sum('amount') * 0.1],
        ];

        return [
            'kpis' => [
                'total_revenue'      => $payments->sum('amount'),
                'open_invoices'      => $invoices->where('status', 'Unpaid')->count(),
                'overdue_payments'   => $invoices->where('status', 'Overdue')->count(),
                'outstanding'        => $invoices->sum('balance'),
                'pending_proposals'  => $proposals->whereIn('status', ['Open', 'Sent'])->count(),
                'accepted_estimates' => $estimates->where('status', 'Accepted')->count(),
                'credit_notes_issued'=> $cns->where('status', 'Open')->count(),
                'conversion_rate'    => $conversionRate,
                'monthly_target'     => 1000000,
            ],
            'revenue_by_month' => $revenueByMonth->values(),
            'pipeline' => [
                ['stage' => 'Proposals', 'count' => $proposals->count(), 'value' => $proposals->sum('total')],
                ['stage' => 'Estimates', 'count' => $estimates->count(), 'value' => $estimates->sum('total')],
                ['stage' => 'Invoiced',  'count' => $invoices->count(), 'value' => $invoices->sum('total')],
                ['stage' => 'Paid',      'count' => $invoices->where('status','Paid')->count(), 'value' => $invoices->where('status','Paid')->sum('total')],
            ],
            'recent_invoices' => $invoices->sortByDesc('created_at')->take(5)->map(fn($inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'client' => 'Client #' . ($inv->client_id ?? 'N/A'),
                'amount' => $inv->total,
                'status' => $inv->status,
            ])->values(),
            'top_clients' => $topClients,
        ];
    }
}
