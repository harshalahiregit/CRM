<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Services\Accounts\Reports\AgeingReport;
use App\Services\Accounts\Reports\BalanceSheetReport;
use App\Services\Accounts\Reports\CashFlowReport;
use App\Services\Accounts\Reports\DayBookReport;
use App\Services\Accounts\Reports\GeneralLedgerReport;
use App\Services\Accounts\Reports\Gstr1Report;
use App\Services\Accounts\Reports\Gstr3bReport;
use App\Services\Accounts\Reports\LedgerStatementReport;
use App\Services\Accounts\Reports\TdsReport;
use App\Services\Accounts\Reports\ProfitAndLossReport;
use App\Services\Accounts\Reports\TrialBalanceReport;
use Illuminate\Http\Request;

/**
 * Read-only report surface. Every figure is derived live from posted
 * voucher_lines — nothing is cached or separately stored.
 */
class ReportController extends Controller
{
    public function trialBalance(Request $request, TrialBalanceReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['to'])));
    }

    public function profitLoss(Request $request, ProfitAndLossReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }

    public function balanceSheet(Request $request, BalanceSheetReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['to'])));
    }

    public function ledgerStatement(Request $request, LedgerStatementReport $report)
    {
        $data = $request->validate([
            'ledger_id' => 'required|integer',
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
        ]);

        return response()->json($report->generate(
            $request->user()->tenant_id,
            (int) $data['ledger_id'],
            $request->only(['from', 'to']),
        ));
    }

    public function generalLedger(Request $request, GeneralLedgerReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to', 'group_id'])));
    }

    public function dayBook(Request $request, DayBookReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }

    public function cashFlow(Request $request, CashFlowReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }

    public function ageing(Request $request, AgeingReport $report)
    {
        $type = $request->query('type') === 'payable' ? 'payable' : 'receivable';
        return response()->json($report->generate($request->user()->tenant_id, $type, $request->only(['to'])));
    }

    public function gstr1(Request $request, Gstr1Report $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }

    public function gstr3b(Request $request, Gstr3bReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }

    public function tds(Request $request, TdsReport $report)
    {
        return response()->json($report->generate($request->user()->tenant_id, $request->only(['from', 'to'])));
    }
}
