<?php

namespace App\Services\Accounts\Reports;

use App\Models\Accounts\CompanyProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Party ageing (Receivables / Payables), derived from the ledger by FIFO. Because
 * the pure ledger has no per-invoice due date, open items are the party ledger's
 * debits (receivable) / credits (payable); the opposite side pays them off oldest-
 * first, and each surviving open item is bucketed by its own age vs the as-of date.
 *
 * Buckets: 0-30, 31-60, 61-90, 90+ days.
 */
class AgeingReport extends ReportService
{
    public function generate(int $tenantId, string $type, array $filters): array
    {
        $asOf = Carbon::parse($filters['to'] ?? now()->toDateString());
        $receivable = $type !== 'payable';

        $booksBegin = optional(CompanyProfile::forTenant($tenantId)->first())->books_begin_date;

        $ledgers = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('l.tenant_id', $tenantId)->whereNull('l.deleted_at')
            ->where(function ($q) use ($receivable) {
                if ($receivable) {
                    $q->where('g.name', 'Sundry Debtors')->orWhere(fn ($w) => $w->where('l.is_party', true)->where('g.nature', 'asset'));
                } else {
                    $q->where('g.name', 'Sundry Creditors')->orWhere(fn ($w) => $w->where('l.is_party', true)->where('g.nature', 'liability'));
                }
            })
            ->get(['l.id', 'l.name', 'l.opening_balance', 'l.opening_balance_type']);

        $parties = [];
        $totals = ['b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0, 'total' => 0.0];

        // All party-ledger lines in ONE query, grouped in memory (avoids a query per party).
        $ledgerIds = $ledgers->pluck('id')->all();
        $linesByLedger = empty($ledgerIds) ? collect() : DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)->whereIn('vl.ledger_id', $ledgerIds)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->whereDate('v.date', '<=', $asOf->toDateString())
            ->orderBy('vl.ledger_id')->orderBy('v.date')->orderBy('v.id')
            ->get(['vl.ledger_id', 'v.date', 'vl.debit', 'vl.credit'])
            ->groupBy('ledger_id');

        foreach ($ledgers as $l) {
            $lines = $linesByLedger->get($l->id, collect());

            // FIFO queue of open items (money owed in the party's normal direction).
            $open = [];
            $openAmt = fn ($ln) => $receivable ? (float) $ln->debit : (float) $ln->credit;
            $payAmt  = fn ($ln) => $receivable ? (float) $ln->credit : (float) $ln->debit;

            // Seed opening balance as an open item if it sits on the party's normal side.
            // Date it at books-begin if known, else at the ledger's first posting (so it
            // ages realistically instead of being force-bucketed into 90+).
            $openingSideMatches = $receivable ? ($l->opening_balance_type === 'dr') : ($l->opening_balance_type === 'cr');
            if ($openingSideMatches && (float) $l->opening_balance > 0) {
                $openingDate = $booksBegin
                    ? Carbon::parse($booksBegin)
                    : ($lines->isNotEmpty() ? Carbon::parse($lines->first()->date) : $asOf->copy());
                $open[] = ['date' => $openingDate, 'amount' => (float) $l->opening_balance];
            }

            foreach ($lines as $ln) {
                if ($openAmt($ln) > 0) {
                    $open[] = ['date' => Carbon::parse($ln->date), 'amount' => $openAmt($ln)];
                }
                $pay = $payAmt($ln);
                while ($pay > 0.005 && ! empty($open)) {
                    if ($open[0]['amount'] <= $pay + 0.005) {
                        $pay -= $open[0]['amount'];
                        array_shift($open);
                    } else {
                        $open[0]['amount'] -= $pay;
                        $pay = 0;
                    }
                }
            }

            if (empty($open)) {
                continue;
            }

            $bucket = ['b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0, 'total' => 0.0];
            foreach ($open as $item) {
                $age = $item['date']->diffInDays($asOf, false);
                $key = $age <= 30 ? 'b0_30' : ($age <= 60 ? 'b31_60' : ($age <= 90 ? 'b61_90' : 'b90_plus'));
                $bucket[$key] += $item['amount'];
                $bucket['total'] += $item['amount'];
            }

            foreach ($bucket as $k => $v) {
                $bucket[$k] = round($v, 2);
                $totals[$k] += $v;
            }

            $parties[] = ['ledger' => $l->name] + $bucket;
        }

        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 2);
        }

        return ['type' => $receivable ? 'receivable' : 'payable', 'as_of' => $asOf->toDateString(), 'parties' => $parties, 'totals' => $totals];
    }
}
