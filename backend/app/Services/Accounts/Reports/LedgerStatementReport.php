<?php

namespace App\Services\Accounts\Reports;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Ledger statement — the running balance of one ledger's posted lines over a
 * period, opening from its balance brought forward (opening balance + all
 * movement before the period start). Balances are shown debit-positive with a
 * Dr/Cr indicator.
 */
class LedgerStatementReport extends ReportService
{
    public function generate(int $tenantId, int $ledgerId, array $filters): array
    {
        $ledger = Ledger::forTenant($tenantId)->with('group:id,name,nature')->find($ledgerId);
        if (! $ledger) {
            throw new BusinessException('Ledger not found.');
        }
        if ($ledger->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }

        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        // Opening = ledger opening balance (signed) + movement strictly before $from.
        $openingSigned = (float) $ledger->opening_balance * ($ledger->opening_balance_type === 'dr' ? 1 : -1);

        if ($from) {
            $prior = DB::table('acc_voucher_lines as vl')
                ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
                ->where('vl.tenant_id', $tenantId)
                ->where('vl.ledger_id', $ledgerId)
                ->where('v.status', 'posted')
                ->whereNull('v.deleted_at')
                ->whereDate('v.date', '<', $from)
                ->selectRaw('COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')
                ->value('net');
            $openingSigned += (float) $prior;
        }

        $lines = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->join('acc_voucher_types as vt', 'vt.id', '=', 'v.voucher_type_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('vl.ledger_id', $ledgerId)
            ->where('v.status', 'posted')
            ->whereNull('v.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('v.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('v.date', '<=', $to))
            ->orderBy('v.date')
            ->orderBy('v.id')
            ->select('v.id as voucher_id', 'v.number', 'v.date', 'v.narration', 'vt.name as voucher_type',
                'vl.debit', 'vl.credit', 'vl.line_narration')
            ->get();

        $running = round($openingSigned, 2);
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $l) {
            $running += (float) $l->debit - (float) $l->credit;
            $totalDebit += (float) $l->debit;
            $totalCredit += (float) $l->credit;

            $rows[] = [
                'voucher_id'   => $l->voucher_id,
                'number'       => $l->number,
                'date'         => $l->date,
                'voucher_type' => $l->voucher_type,
                'narration'    => $l->line_narration ?: $l->narration,
                'debit'        => round((float) $l->debit, 2),
                'credit'       => round((float) $l->credit, 2),
                'balance'      => round(abs($running), 2),
                'balance_type' => $running >= 0 ? 'Dr' : 'Cr',
            ];
        }

        return [
            'ledger' => [
                'id' => $ledger->id, 'name' => $ledger->name,
                'group' => $ledger->group->name ?? null, 'nature' => $ledger->group->nature ?? null,
            ],
            'from' => $from,
            'to'   => $to,
            'opening' => ['balance' => round(abs($openingSigned), 2), 'balance_type' => $openingSigned >= 0 ? 'Dr' : 'Cr'],
            'rows' => $rows,
            'totals' => ['debit' => round($totalDebit, 2), 'credit' => round($totalCredit, 2)],
            'closing' => ['balance' => round(abs($running), 2), 'balance_type' => $running >= 0 ? 'Dr' : 'Cr'],
        ];
    }
}
