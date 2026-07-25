<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\VoucherLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Account Registers — the per-ledger passbook view.
 *
 * Old-CRM parity: the "Registers" section shows every active ledger with its
 * current balance; clicking one opens a date-filterable, paginated list of
 * every voucher line posted to that ledger (debit, credit, running balance).
 * This is purely a read-only derived view over acc_voucher_lines — no writes
 * happen here (use the Voucher flow for that).
 *
 * Tenant isolation: every query is scoped to the authenticated user's
 * tenant_id via the BelongsToTenant scope that Ledger already carries.
 */
class RegisterController extends Controller
{
    /**
     * List all active ledgers with their current balance and last-activity date.
     *
     * GET /accounts/registers
     * Query params:
     *   search      – filter by ledger name (optional)
     *   nature      – asset|liability|equity|income|expense (optional)
     *   per_page    – default 100
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $search   = $request->query('search');
        $nature   = $request->query('nature');
        $perPage  = (int) ($request->query('per_page', 100));
        $perPage  = min(max($perPage, 1), 500);

        $rows = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('l.tenant_id', $tenantId)
            ->where('l.is_active', true)
            ->when($search, fn ($q) => $q->where('l.name', 'like', "%{$search}%"))
            ->when($nature, fn ($q) => $q->where('g.nature', $nature))
            ->leftJoinSub(
                DB::table('acc_voucher_lines as vl')
                    ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
                    ->where('vl.tenant_id', $tenantId)
                    ->where('v.status', 'posted')
                    ->selectRaw('
                        vl.ledger_id,
                        COALESCE(SUM(vl.debit), 0)  - COALESCE(SUM(vl.credit), 0) AS net_posted,
                        MAX(v.date) AS last_activity
                    ')
                    ->groupBy('vl.ledger_id'),
                'bal',
                'bal.ledger_id',
                '=',
                'l.id'
            )
            ->selectRaw("
                l.id,
                l.name,
                l.code,
                l.opening_balance,
                l.opening_balance_type,
                l.is_bank,
                l.is_cash,
                l.is_party,
                g.id   AS group_id,
                g.name AS group_name,
                g.nature,
                COALESCE(bal.net_posted, 0)    AS net_posted,
                COALESCE(bal.last_activity, '') AS last_activity
            ")
            ->orderBy('g.nature')
            ->orderBy('l.name')
            ->paginate($perPage);

        // Compute true running balance = opening ± net_posted respecting normal-side
        $rows->through(function ($row) {
            $ob     = (float) $row->opening_balance;
            $obType = $row->opening_balance_type; // 'dr' | 'cr'
            $net    = (float) $row->net_posted;   // debit − credit of posted lines

            // Balance: opening (Dr side positive) + net movements
            $obDr   = $obType === 'dr' ? $ob : -$ob;
            $balance = round($obDr + $net, 2);

            $row->balance      = abs($balance);
            $row->balance_type = $balance >= 0 ? 'dr' : 'cr';

            return $row;
        });

        return response()->json($rows);
    }

    /**
     * Per-ledger statement: paginated, date-filterable, with running balance.
     *
     * GET /accounts/registers/{ledger}
     * Query params:
     *   from      – date (Y-m-d, optional)
     *   to        – date (Y-m-d, optional)
     *   per_page  – default 50
     */
    public function show(Request $request, int $ledger): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $from     = $request->query('from');
        $to       = $request->query('to');
        $perPage  = (int) ($request->query('per_page', 50));
        $perPage  = min(max($perPage, 1), 200);

        // Verify the ledger belongs to this tenant
        $ledgerRow = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('l.id', $ledger)
            ->where('l.tenant_id', $tenantId)
            ->selectRaw('l.id, l.name, l.code, l.opening_balance, l.opening_balance_type, g.name as group_name, g.nature')
            ->first();

        if (! $ledgerRow) {
            return response()->json(['message' => 'Ledger not found.'], 404);
        }

        // Opening balance before the 'from' date (so we can show correct carry-forward)
        $openingCarry = 0.0;
        if ($from) {
            $openingCarry = (float) DB::table('acc_voucher_lines as vl')
                ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
                ->where('vl.tenant_id', $tenantId)
                ->where('vl.ledger_id', $ledger)
                ->where('v.status', 'posted')
                ->where('v.date', '<', $from)
                ->selectRaw('COALESCE(SUM(vl.debit), 0) - COALESCE(SUM(vl.credit), 0) as net')
                ->value('net');
        }

        $ob     = (float) $ledgerRow->opening_balance;
        $obDr   = $ledgerRow->opening_balance_type === 'dr' ? $ob : -$ob;
        $openingBalance = round($obDr + $openingCarry, 2);

        // Paginated lines in the requested period
        $lines = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v',      'v.id',  '=', 'vl.voucher_id')
            ->join('acc_voucher_types as vt', 'vt.id', '=', 'v.voucher_type_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('vl.ledger_id', $ledger)
            ->where('v.status', 'posted')
            ->when($from, fn ($q) => $q->where('v.date', '>=', $from))
            ->when($to,   fn ($q) => $q->where('v.date', '<=', $to))
            ->selectRaw("
                vl.id,
                v.id         AS voucher_id,
                v.number     AS voucher_number,
                v.date,
                vt.code      AS voucher_type_code,
                vt.name      AS voucher_type_name,
                v.narration,
                vl.line_narration,
                COALESCE(vl.debit,  0) AS debit,
                COALESCE(vl.credit, 0) AS credit
            ")
            ->orderBy('v.date')
            ->orderBy('v.id')
            ->orderBy('vl.id')
            ->paginate($perPage);

        // Compute running balance per page (starts from opening balance for page 1)
        $running = $openingBalance;
        $lines->through(function ($row) use (&$running) {
            $running += (float) $row->debit - (float) $row->credit;
            $row->running_balance      = abs(round($running, 2));
            $row->running_balance_type = $running >= 0 ? 'dr' : 'cr';
            return $row;
        });

        return response()->json([
            'ledger'          => $ledgerRow,
            'opening_balance' => abs($openingBalance),
            'opening_type'    => $openingBalance >= 0 ? 'dr' : 'cr',
            'lines'           => $lines,
        ]);
    }
}
