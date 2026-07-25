<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Budget;
use App\Models\Accounts\BudgetLine;
use App\Models\Accounts\FinancialYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Budgets + Budget-vs-Actual. Targets are stored per ledger per month (an annual
 * figure entered in the UI is spread evenly across the FY's 12 months, remainder
 * on the last month). Actuals are always read live from the ledger, never stored.
 */
class BudgetService
{
    public function list(int $tenantId)
    {
        return Budget::forTenant($tenantId)
            ->with('financialYear:id,start_date,end_date')
            ->withSum('lines as budgeted_total', 'amount')
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data, int $tenantId): Budget
    {
        $fy = FinancialYear::forTenant($tenantId)->find($data['financial_year_id']);
        if (! $fy) {
            throw new BusinessException('Financial year not found.');
        }

        return Budget::create([
            'tenant_id'         => $tenantId,
            'financial_year_id' => $fy->id,
            'name'              => $data['name'],
        ]);
    }

    /** Budget with a per-ledger annual total (the grid the UI edits). */
    public function show(Budget $budget, int $tenantId): array
    {
        $this->assert($budget, $tenantId);

        $lines = DB::table('acc_budget_lines as bl')
            ->join('acc_ledgers as l', 'l.id', '=', 'bl.ledger_id')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('bl.budget_id', $budget->id)
            ->groupBy('bl.ledger_id', 'l.name', 'g.nature', 'g.name')
            ->selectRaw('bl.ledger_id, l.name as ledger, g.nature, g.name as group_name, SUM(bl.amount) as annual')
            ->get()
            ->map(fn ($r) => [
                'ledger_id' => $r->ledger_id, 'ledger' => $r->ledger,
                'nature' => $r->nature, 'group' => $r->group_name,
                'annual_amount' => round((float) $r->annual, 2),
            ])->all();

        return [
            'budget' => $budget->load('financialYear'),
            'lines'  => $lines,
        ];
    }

    /**
     * Replace the budget's lines. Input: [{ledger_id, annual_amount}]. Each annual
     * amount is spread across the 12 months of the FY (remainder on the last month).
     */
    public function saveLines(Budget $budget, array $rows, int $tenantId): array
    {
        $this->assert($budget, $tenantId);
        $fy = $budget->financialYear;
        $months = $this->fyMonths($fy);

        $validLedgerIds = DB::table('acc_ledgers')->where('tenant_id', $tenantId)
            ->whereIn('id', array_column($rows, 'ledger_id'))->pluck('id')->all();

        DB::transaction(function () use ($budget, $rows, $months, $tenantId, $validLedgerIds) {
            $budget->lines()->delete();

            $now = now();
            $bulk = [];
            foreach ($rows as $row) {
                $ledgerId = (int) ($row['ledger_id'] ?? 0);
                $annual = round((float) ($row['annual_amount'] ?? 0), 2);
                if (! in_array($ledgerId, $validLedgerIds, true) || $annual == 0) {
                    continue;
                }

                // Even split in paise so the 12 months sum exactly to the annual figure.
                $totalPaise = (int) round($annual * 100);
                $base = intdiv($totalPaise, 12);
                $remainder = $totalPaise - ($base * 12);

                foreach ($months as $i => [$month, $year]) {
                    $paise = $base + ($i === 11 ? $remainder : 0);
                    if ($paise === 0) {
                        continue;
                    }
                    $bulk[] = [
                        'tenant_id' => $tenantId, 'budget_id' => $budget->id, 'ledger_id' => $ledgerId,
                        'month' => $month, 'year' => $year, 'amount' => $paise / 100,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            if (! empty($bulk)) {
                BudgetLine::insert($bulk);
            }
        });

        Log::channel('accounts')->info('Budget lines saved', ['budget_id' => $budget->id, 'tenant_id' => $tenantId]);

        return $this->show($budget->fresh(), $tenantId);
    }

    public function delete(Budget $budget, int $tenantId): void
    {
        $this->assert($budget, $tenantId);
        DB::transaction(function () use ($budget) {
            $budget->lines()->delete();
            $budget->delete();
        });
    }

    /** Budget vs actual for the budget's FY. Actual is the ledger's natural-direction movement. */
    public function budgetVsActual(Budget $budget, int $tenantId): array
    {
        $this->assert($budget, $tenantId);
        $fy = $budget->financialYear;

        $budgetByLedger = DB::table('acc_budget_lines')->where('budget_id', $budget->id)
            ->groupBy('ledger_id')
            ->selectRaw('ledger_id, SUM(amount) as total')
            ->get()->pluck('total', 'ledger_id');

        // Actual movement per budgeted ledger over the FY, with nature for sign.
        $ledgerIds = $budgetByLedger->keys()->all();
        if (empty($ledgerIds)) {
            return ['budget' => $budget->load('financialYear'), 'rows' => [], 'totals' => ['budget' => 0, 'actual' => 0, 'variance' => 0]];
        }

        $actuals = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->join('acc_ledgers as l', 'l.id', '=', 'vl.ledger_id')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('vl.tenant_id', $tenantId)
            ->whereIn('vl.ledger_id', $ledgerIds)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->whereBetween('v.date', [$fy->start_date->toDateString(), $fy->end_date->toDateString()])
            ->groupBy('vl.ledger_id', 'l.name', 'g.nature')
            ->selectRaw('vl.ledger_id, l.name as ledger, g.nature,
                COALESCE(SUM(vl.debit),0) as dr, COALESCE(SUM(vl.credit),0) as cr')
            ->get()
            ->keyBy('ledger_id');

        $rows = [];
        $tBudget = 0.0; $tActual = 0.0;
        foreach ($budgetByLedger as $ledgerId => $budgetAmt) {
            $budgetAmt = round((float) $budgetAmt, 2);
            $a = $actuals->get($ledgerId);
            // Natural direction: income/liability/equity → credit-positive; asset/expense → debit-positive.
            $actual = 0.0;
            $ledgerName = null; $nature = null;
            if ($a) {
                $nature = $a->nature; $ledgerName = $a->ledger;
                $actual = in_array($a->nature, ['income', 'liability', 'equity'], true)
                    ? (float) $a->cr - (float) $a->dr
                    : (float) $a->dr - (float) $a->cr;
            }
            if (! $ledgerName) {
                $ledgerName = DB::table('acc_ledgers')->where('id', $ledgerId)->value('name');
            }
            $actual = round($actual, 2);

            $rows[] = [
                'ledger_id' => $ledgerId, 'ledger' => $ledgerName, 'nature' => $nature,
                'budget' => $budgetAmt, 'actual' => $actual,
                'variance' => round($budgetAmt - $actual, 2),
                'used_percent' => $budgetAmt != 0 ? round($actual / $budgetAmt * 100, 1) : null,
            ];
            $tBudget += $budgetAmt; $tActual += $actual;
        }

        usort($rows, fn ($a, $b) => strcmp($a['ledger'] ?? '', $b['ledger'] ?? ''));

        return [
            'budget' => $budget->load('financialYear'),
            'rows'   => $rows,
            'totals' => ['budget' => round($tBudget, 2), 'actual' => round($tActual, 2), 'variance' => round($tBudget - $tActual, 2)],
        ];
    }

    /** The 12 [month, year] pairs of a financial year, in order from its start. */
    private function fyMonths(FinancialYear $fy): array
    {
        $months = [];
        $d = $fy->start_date->copy()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $months[] = [(int) $d->month, (int) $d->year];
            $d->addMonth();
        }
        return $months;
    }

    private function assert(Budget $budget, int $tenantId): void
    {
        if ($budget->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
