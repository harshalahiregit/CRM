<?php

namespace App\Services\Accounts\Reports;

use Illuminate\Support\Facades\DB;

/**
 * General Ledger — every ledger's opening balance, in-period postings with a
 * running balance, and closing balance, grouped by ledger. Derived entirely from
 * posted voucher_lines (spec §5.6). Optionally filtered to one group.
 */
class GeneralLedgerReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;
        $groupId = $filters['group_id'] ?? null;

        $ledgers = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->when($groupId, fn ($q) => $q->where('l.group_id', $groupId))
            ->orderBy('g.name')->orderBy('l.name')
            ->get(['l.id', 'l.name', 'l.opening_balance', 'l.opening_balance_type', 'g.name as group_name', 'g.nature']);

        // Signed (debit−credit) movement per ledger strictly before `from`.
        $openingByLedger = $this->openingMovement($tenantId, $from);

        $rows = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->join('acc_voucher_types as vt', 'vt.id', '=', 'v.voucher_type_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('v.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('v.date', '<=', $to))
            ->orderBy('vl.ledger_id')->orderBy('v.date')->orderBy('v.id')
            ->get(['vl.ledger_id', 'v.number', 'v.date', 'v.narration', 'vt.name as voucher_type', 'vl.debit', 'vl.credit', 'vl.line_narration']);

        $linesByLedger = $rows->groupBy('ledger_id');

        $out = [];
        foreach ($ledgers as $l) {
            $lines = $linesByLedger->get($l->id, collect());
            $openingSigned = (float) $l->opening_balance * ($l->opening_balance_type === 'dr' ? 1 : -1)
                + (float) ($openingByLedger[$l->id] ?? 0);

            if ($lines->isEmpty() && abs($openingSigned) < 0.005) {
                continue; // skip dormant ledgers
            }

            $running = $openingSigned;
            $rowsOut = [];
            $td = 0.0; $tc = 0.0;
            foreach ($lines as $ln) {
                $running += (float) $ln->debit - (float) $ln->credit;
                $td += (float) $ln->debit; $tc += (float) $ln->credit;
                $rowsOut[] = [
                    'number'       => $ln->number, 'date' => $ln->date, 'voucher_type' => $ln->voucher_type,
                    'narration'    => $ln->line_narration ?: $ln->narration,
                    'debit'        => round((float) $ln->debit, 2), 'credit' => round((float) $ln->credit, 2),
                    'balance'      => round(abs($running), 2), 'balance_type' => $running >= 0 ? 'Dr' : 'Cr',
                ];
            }

            $out[] = [
                'ledger'  => $l->name, 'group' => $l->group_name, 'nature' => $l->nature,
                'opening' => ['balance' => round(abs($openingSigned), 2), 'balance_type' => $openingSigned >= 0 ? 'Dr' : 'Cr'],
                'rows'    => $rowsOut,
                'totals'  => ['debit' => round($td, 2), 'credit' => round($tc, 2)],
                'closing' => ['balance' => round(abs($running), 2), 'balance_type' => $running >= 0 ? 'Dr' : 'Cr'],
            ];
        }

        return ['from' => $from, 'to' => $to, 'ledgers' => $out];
    }

    /** Signed (debit−credit) movement per ledger id from postings strictly before `from`. */
    private function openingMovement(int $tenantId, ?string $from): array
    {
        if (! $from) {
            return [];
        }

        return DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->whereDate('v.date', '<', $from)
            ->groupBy('vl.ledger_id')
            ->selectRaw('vl.ledger_id as lid, COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')
            ->get()
            ->pluck('net', 'lid')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
