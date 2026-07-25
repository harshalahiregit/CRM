<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\BankAccount;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\Reconciliation;
use App\Models\Accounts\ReconciliationLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bank reconciliation on the clean voucher-line ledger. Mirrors the legacy intent
 * (book side vs statement side, complete when difference = 0) without touching the
 * Phase-1 tables: "cleared" book lines are recorded in acc_reconciliation_lines.
 *
 * A bank account is an asset (debit-normal): a book line's debit is money in,
 * credit is money out. Cleared book balance = opening + Σ(debit − credit of
 * cleared lines); it must equal the entered statement (bank) balance to finish.
 */
class ReconciliationService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    /** Start (or resume) a reconciliation for a bank account. One in-progress at a time. */
    public function start(int $bankAccountId, string $statementDate, float $statementBalance, int $tenantId, ?int $userId): Reconciliation
    {
        $bank = BankAccount::forTenant($tenantId)->find($bankAccountId);
        if (! $bank) {
            throw new BusinessException('Bank account not found.');
        }

        $existing = Reconciliation::forTenant($tenantId)
            ->where('bank_account_id', $bankAccountId)->where('status', 'in_progress')->first();
        if ($existing) {
            throw new BusinessException('A reconciliation is already in progress for this bank account. Finish or cancel it first.');
        }

        $rec = Reconciliation::create([
            'tenant_id'         => $tenantId,
            'bank_account_id'   => $bankAccountId,
            'statement_date'    => $statementDate,
            'opening_balance'   => $this->openingBalance($bank, $tenantId),
            'statement_balance' => $statementBalance,
            'status'            => 'in_progress',
            'created_by'        => $userId,
        ]);

        Log::channel('accounts')->info('Reconciliation started', ['reconciliation_id' => $rec->id, 'bank_account_id' => $bankAccountId]);

        return $rec;
    }

    /** The reconcile workspace: book lines (with cleared flag), statement lines, and the running difference. */
    public function workspace(Reconciliation $rec, int $tenantId): array
    {
        $this->assert($rec, $tenantId);
        $bank = BankAccount::forTenant($tenantId)->findOrFail($rec->bank_account_id);

        $clearedLineIds = ReconciliationLine::where('reconciliation_id', $rec->id)->pluck('voucher_line_id')->all();
        $reconciledElsewhere = $this->reconciledLineIds($tenantId, $bank->id, exceptRec: $rec->id);

        $bookLines = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('vl.ledger_id', $bank->ledger_id)
            ->where('v.status', 'posted')
            ->whereNull('v.deleted_at')
            ->whereNotIn('vl.id', $reconciledElsewhere ?: [0])
            ->orderBy('v.date')->orderBy('vl.id')
            ->select('vl.id', 'v.number', 'v.date', 'v.narration', 'vl.debit', 'vl.credit')
            ->get()
            ->map(fn ($l) => [
                'voucher_line_id' => $l->id,
                'number'          => $l->number,
                'date'            => $l->date,
                'narration'       => $l->narration,
                'debit'           => (float) $l->debit,
                'credit'          => (float) $l->credit,
                'cleared'         => in_array($l->id, $clearedLineIds, true),
            ])->all();

        $statementLines = BankStatementLine::forTenant($tenantId)
            ->where('bank_account_id', $bank->id)
            ->where(fn ($q) => $q->whereIn('match_status', ['unmatched', 'suggested'])->orWhere('reconciliation_id', $rec->id))
            ->orderBy('txn_date')->get();

        return [
            'reconciliation' => $rec,
            'summary'        => $this->summary($rec),
            'book_lines'     => $bookLines,
            'statement_lines' => $statementLines,
        ];
    }

    /** Toggle a book line cleared/uncleared, optionally matched to a statement line. */
    public function toggleClear(Reconciliation $rec, int $voucherLineId, ?int $statementLineId, int $tenantId): array
    {
        $this->assert($rec, $tenantId);
        if ($rec->status !== 'in_progress') {
            throw new BusinessException('This reconciliation is already completed.');
        }

        $this->assertBookLine($tenantId, $rec->bank_account_id, $voucherLineId);

        $line = ReconciliationLine::where('reconciliation_id', $rec->id)->where('voucher_line_id', $voucherLineId)->first();

        DB::transaction(function () use ($line, $rec, $voucherLineId, $statementLineId, $tenantId) {
            if ($line) {
                // Un-clear
                if ($line->statement_line_id) {
                    BankStatementLine::where('id', $line->statement_line_id)->update([
                        'match_status' => 'unmatched', 'matched_voucher_line_id' => null, 'reconciliation_id' => null,
                    ]);
                }
                $line->delete();
            } else {
                // Clear
                ReconciliationLine::create([
                    'tenant_id'         => $tenantId,
                    'reconciliation_id' => $rec->id,
                    'voucher_line_id'   => $voucherLineId,
                    'statement_line_id' => $statementLineId,
                ]);
                if ($statementLineId) {
                    BankStatementLine::forTenant($tenantId)->where('id', $statementLineId)->update([
                        'match_status' => 'matched', 'matched_voucher_line_id' => $voucherLineId, 'reconciliation_id' => $rec->id,
                    ]);
                }
            }
        });

        return $this->summary($rec->fresh());
    }

    /** Auto-match unmatched statement lines to uncleared book lines by amount + date window. */
    public function autoSuggest(Reconciliation $rec, int $tenantId): array
    {
        $this->assert($rec, $tenantId);
        $bank = BankAccount::forTenant($tenantId)->findOrFail($rec->bank_account_id);

        $clearedLineIds = ReconciliationLine::where('reconciliation_id', $rec->id)->pluck('voucher_line_id')->all();
        $reconciledElsewhere = $this->reconciledLineIds($tenantId, $bank->id, exceptRec: $rec->id);
        $usedBookIds = array_merge($clearedLineIds, $reconciledElsewhere);

        $statementLines = BankStatementLine::forTenant($tenantId)
            ->where('bank_account_id', $bank->id)->whereIn('match_status', ['unmatched', 'suggested'])
            ->orderBy('txn_date')->get();

        // Load ALL candidate book lines once (uncleared, on this bank ledger); match in memory.
        $candidates = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('vl.ledger_id', $bank->ledger_id)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->whereNotIn('vl.id', $usedBookIds ?: [0])
            ->orderBy('v.date')
            ->get(['vl.id', 'v.date', 'vl.debit', 'vl.credit'])
            ->map(fn ($c) => (object) ['id' => $c->id, 'date' => \Illuminate\Support\Carbon::parse($c->date), 'debit' => (float) $c->debit, 'credit' => (float) $c->credit]);

        $usedInThisRun = [];
        $newLines = [];      // acc_reconciliation_lines rows to bulk-insert
        $matchedStatementIds = [];   // statement_line_id => voucher_line_id
        $now = now();

        foreach ($statementLines as $sl) {
            $isDeposit = (float) $sl->credit > 0;
            $amount = $isDeposit ? (float) $sl->credit : (float) $sl->debit;

            $hit = $candidates->first(function ($c) use ($isDeposit, $amount, $sl, $usedInThisRun) {
                if (isset($usedInThisRun[$c->id])) {
                    return false;
                }
                $side = $isDeposit ? $c->debit : $c->credit;
                if (round($side, 2) !== round($amount, 2)) {
                    return false;
                }
                return abs($c->date->diffInDays($sl->txn_date)) <= 5;
            });

            if ($hit) {
                $usedInThisRun[$hit->id] = true;
                $newLines[] = [
                    'tenant_id' => $tenantId, 'reconciliation_id' => $rec->id,
                    'voucher_line_id' => $hit->id, 'statement_line_id' => $sl->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $matchedStatementIds[$sl->id] = $hit->id;
            }
        }

        if (! empty($newLines)) {
            DB::transaction(function () use ($newLines, $matchedStatementIds, $rec) {
                ReconciliationLine::insert($newLines);
                foreach ($matchedStatementIds as $slId => $vlId) {
                    BankStatementLine::where('id', $slId)->update([
                        'match_status' => 'matched', 'matched_voucher_line_id' => $vlId, 'reconciliation_id' => $rec->id,
                    ]);
                }
            });
        }

        return ['matched' => count($newLines), 'summary' => $this->summary($rec->fresh())];
    }

    public function ignoreStatementLine(int $statementLineId, int $tenantId): void
    {
        $sl = BankStatementLine::forTenant($tenantId)->find($statementLineId);
        if (! $sl) {
            throw new BusinessException('Statement line not found.');
        }
        if ($sl->match_status === 'matched') {
            throw new BusinessException('This line is matched. Un-match it first.');
        }
        $sl->update(['match_status' => $sl->match_status === 'ignored' ? 'unmatched' : 'ignored']);
    }

    /** Complete: requires difference = 0. Locks the cleared lines into the reconciliation. */
    public function complete(Reconciliation $rec, int $tenantId, ?int $userId): Reconciliation
    {
        $this->assert($rec, $tenantId);
        if ($rec->status !== 'in_progress') {
            throw new BusinessException('This reconciliation is already completed.');
        }

        $summary = $this->summary($rec);
        if (round((float) $summary['difference'], 2) !== 0.0) {
            throw new BusinessException('Cannot finish — the difference is ' . number_format((float) $summary['difference'], 2) . '. It must be zero.');
        }

        $rec->update([
            'book_balance' => $summary['cleared_book_balance'],
            'difference'   => 0,
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $this->audit->log($tenantId, $userId, 'reconciliation', $rec->id, 'update', null, $rec->toArray());
        Log::channel('accounts')->info('Reconciliation completed', ['reconciliation_id' => $rec->id]);

        return $rec;
    }

    /** Cancel an in-progress reconciliation, releasing all its cleared/matched lines. */
    public function cancel(Reconciliation $rec, int $tenantId): void
    {
        $this->assert($rec, $tenantId);
        if ($rec->status !== 'in_progress') {
            throw new BusinessException('Only an in-progress reconciliation can be cancelled.');
        }

        DB::transaction(function () use ($rec, $tenantId) {
            BankStatementLine::forTenant($tenantId)->where('reconciliation_id', $rec->id)->update([
                'match_status' => 'unmatched', 'matched_voucher_line_id' => null, 'reconciliation_id' => null,
            ]);
            $rec->lines()->delete();
            $rec->delete();
        });
    }

    public function history(int $tenantId, int $bankAccountId)
    {
        return Reconciliation::forTenant($tenantId)
            ->where('bank_account_id', $bankAccountId)
            ->orderByDesc('statement_date')->orderByDesc('id')->get();
    }

    /* ── Internals ────────────────────────────────────────────── */

    public function summary(Reconciliation $rec): array
    {
        $clearedNet = (float) DB::table('acc_reconciliation_lines as rl')
            ->join('acc_voucher_lines as vl', 'vl.id', '=', 'rl.voucher_line_id')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('rl.reconciliation_id', $rec->id)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->selectRaw('COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')
            ->value('net');

        $clearedBook = round((float) $rec->opening_balance + $clearedNet, 2);
        $difference = round((float) $rec->statement_balance - $clearedBook, 2);

        return [
            'opening_balance'      => round((float) $rec->opening_balance, 2),
            'cleared_movement'     => round($clearedNet, 2),
            'cleared_book_balance' => $clearedBook,
            'statement_balance'    => round((float) $rec->statement_balance, 2),
            'difference'           => $difference,
            'is_balanced'          => $difference === 0.0,
        ];
    }

    /** Opening = last completed reconciliation's statement balance, else the ledger opening. */
    private function openingBalance(BankAccount $bank, int $tenantId): float
    {
        $last = Reconciliation::forTenant($tenantId)
            ->where('bank_account_id', $bank->id)->where('status', 'completed')
            ->orderByDesc('statement_date')->orderByDesc('id')->first();

        if ($last) {
            return (float) $last->statement_balance;
        }

        $ledger = Ledger::forTenant($tenantId)->find($bank->ledger_id);
        return $ledger ? (float) $ledger->opening_balance * ($ledger->opening_balance_type === 'dr' ? 1 : -1) : 0.0;
    }

    /** Voucher-line ids already locked into completed reconciliations for this bank. */
    private function reconciledLineIds(int $tenantId, int $bankAccountId, ?int $exceptRec = null): array
    {
        return ReconciliationLine::where('acc_reconciliation_lines.tenant_id', $tenantId)
            ->join('acc_reconciliations as r', 'r.id', '=', 'acc_reconciliation_lines.reconciliation_id')
            ->where('r.bank_account_id', $bankAccountId)
            ->where('r.status', 'completed')
            ->when($exceptRec, fn ($q) => $q->where('r.id', '!=', $exceptRec))
            ->pluck('acc_reconciliation_lines.voucher_line_id')->all();
    }

    private function assertBookLine(int $tenantId, int $bankAccountId, int $voucherLineId): void
    {
        $bank = BankAccount::forTenant($tenantId)->findOrFail($bankAccountId);
        $ok = DB::table('acc_voucher_lines')
            ->where('id', $voucherLineId)->where('tenant_id', $tenantId)->where('ledger_id', $bank->ledger_id)->exists();
        if (! $ok) {
            throw new BusinessException('That ledger line does not belong to this bank account.');
        }
    }

    private function assert(Reconciliation $rec, int $tenantId): void
    {
        if ($rec->tenant_id !== $tenantId) {
            throw new BusinessException('Reconciliation not found.');
        }
    }
}
