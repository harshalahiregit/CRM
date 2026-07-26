<?php

namespace App\Services\Accounts\Reports;

use App\Models\Accounts\Voucher;

/**
 * Day Book — all vouchers in a date range, chronologically, with their legs.
 * A plain window onto the journal.
 */
class DayBookReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $vouchers = Voucher::forTenant($tenantId)
            ->posted()
            ->with(['lines.ledger:id,name', 'voucherType:id,name'])
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->orderBy('date')->orderBy('id')
            ->get();

        $total = 0.0;
        $rows = $vouchers->map(function ($v) use (&$total) {
            $total += (float) $v->total_amount;
            return [
                'id'        => $v->id,
                'number'    => $v->number,
                'date'      => $v->date,
                'type'      => $v->voucherType->name ?? null,
                'narration' => $v->narration,
                'amount'    => round((float) $v->total_amount, 2),
                'lines'     => $v->lines->map(fn ($l) => [
                    'ledger' => $l->ledger->name ?? '—',
                    'debit'  => round((float) $l->debit, 2),
                    'credit' => round((float) $l->credit, 2),
                ])->all(),
            ];
        })->all();

        return ['from' => $from, 'to' => $to, 'vouchers' => $rows, 'total' => round($total, 2)];
    }
}
