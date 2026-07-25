<?php

namespace App\Services\Accounts\Reports;

use App\Models\Accounts\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cash Flow (direct method) — the net movement across cash & bank ledgers over a
 * period, classified operating / investing / financing by the *contra* legs of
 * each voucher (spec §5.6 / §6):
 *   income & expense, debtors/creditors, current assets/liabilities → operating
 *   fixed assets, investments                                        → investing
 *   equity, loans (non-current liabilities)                          → financing
 * Multi-leg vouchers split the cash movement across their contra legs by weight.
 */
class CashFlowReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $cashLedgers = Ledger::forTenant($tenantId)
            ->where(fn ($q) => $q->where('is_cash', true)->orWhere('is_bank', true))
            ->get(['id', 'name', 'opening_balance', 'opening_balance_type']);
        $cashIds = $cashLedgers->pluck('id')->all();

        if (empty($cashIds)) {
            return ['from' => $from, 'to' => $to, 'opening_cash' => 0, 'sections' => [], 'net' => 0, 'closing_cash' => 0];
        }

        $openingCash = $cashLedgers->sum(fn ($l) => (float) $l->opening_balance * ($l->opening_balance_type === 'dr' ? 1 : -1));
        if ($from) {
            $openingCash += (float) DB::table('acc_voucher_lines as vl')
                ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
                ->where('vl.tenant_id', $tenantId)->whereIn('vl.ledger_id', $cashIds)
                ->where('v.status', 'posted')->whereNull('v.deleted_at')
                ->whereDate('v.date', '<', $from)
                ->selectRaw('COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')->value('net');
        }

        // Vouchers that touch a cash/bank ledger in the period.
        $voucherIds = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)->whereIn('vl.ledger_id', $cashIds)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('v.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('v.date', '<=', $to))
            ->distinct()->pluck('v.id')->all();

        $buckets = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
        $detail = ['operating' => [], 'investing' => [], 'financing' => []];

        if (! empty($voucherIds)) {
            $lines = DB::table('acc_voucher_lines as vl')
                ->join('acc_ledgers as l', 'l.id', '=', 'vl.ledger_id')
                ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
                ->whereIn('vl.voucher_id', $voucherIds)
                ->get(['vl.voucher_id', 'vl.ledger_id', 'vl.debit', 'vl.credit', 'l.name as ledger', 'g.nature', 'g.name as group_name'])
                ->groupBy('voucher_id');

            foreach ($lines as $voucherLines) {
                $cashDelta = 0.0;
                $contra = [];
                foreach ($voucherLines as $ln) {
                    $net = (float) $ln->debit - (float) $ln->credit;
                    if (in_array($ln->ledger_id, $cashIds, true)) {
                        $cashDelta += $net;
                    } else {
                        $contra[] = ['ledger' => $ln->ledger, 'weight' => abs($net),
                            'bucket' => $this->classify($ln->nature, $ln->group_name)];
                    }
                }
                if (abs($cashDelta) < 0.005 || empty($contra)) {
                    continue;
                }

                $totalWeight = array_sum(array_column($contra, 'weight')) ?: 1;
                $allocated = 0.0;
                $n = count($contra);
                foreach ($contra as $idx => $c) {
                    // Last leg takes the residual so the split ties exactly to cashDelta (no lost paise).
                    $portion = $idx === $n - 1
                        ? round($cashDelta - $allocated, 2)
                        : round($cashDelta * ($c['weight'] / $totalWeight), 2);
                    $allocated = round($allocated + $portion, 2);
                    $buckets[$c['bucket']] += $portion;
                    $key = $c['ledger'];
                    $detail[$c['bucket']][$key] = round(($detail[$c['bucket']][$key] ?? 0) + $portion, 2);
                }
            }
        }

        $net = round(array_sum($buckets), 2);

        $sections = [];
        foreach (['operating', 'investing', 'financing'] as $b) {
            $sections[] = [
                'key'   => $b,
                'total' => round($buckets[$b], 2),
                'lines' => collect($detail[$b])->map(fn ($amt, $ledger) => ['ledger' => $ledger, 'amount' => $amt])
                    ->filter(fn ($r) => abs($r['amount']) >= 0.005)->values()->all(),
            ];
        }

        return [
            'from' => $from, 'to' => $to,
            'opening_cash' => round($openingCash, 2),
            'sections' => $sections,
            'net' => $net,
            'closing_cash' => round($openingCash + $net, 2),
        ];
    }

    private function classify(string $nature, string $groupName): string
    {
        $g = strtolower($groupName);
        return match ($nature) {
            'income', 'expense' => 'operating',
            'equity' => 'financing',
            'liability' => str_contains($g, 'loan') ? 'financing' : 'operating',
            'asset' => (str_contains($g, 'fixed asset') || str_contains($g, 'investment')) ? 'investing' : 'operating',
            default => 'operating',
        };
    }
}
