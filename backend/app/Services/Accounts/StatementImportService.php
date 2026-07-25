<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\BankAccount;
use App\Models\Accounts\BankStatementImport;
use App\Models\Accounts\BankStatementLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports a bank statement (CSV/XLSX) into acc_bank_statement_lines. The first
 * row is a header; columns are mapped by name (date / description / ref /
 * withdrawal|debit / deposit|credit / balance) so bank export formats vary
 * gracefully. Money-out goes to `debit`, money-in to `credit` (the bank's view).
 */
class StatementImportService
{
    private const HEADER_MAP = [
        'date'        => ['date', 'txn date', 'transaction date', 'value date'],
        'description' => ['description', 'narration', 'particulars', 'details', 'remarks'],
        'ref_no'      => ['ref', 'ref no', 'reference', 'cheque no', 'chq no', 'utr'],
        'debit'       => ['debit', 'withdrawal', 'withdrawals', 'dr', 'money out', 'paid out'],
        'credit'      => ['credit', 'deposit', 'deposits', 'cr', 'money in', 'paid in'],
        'balance'     => ['balance', 'running balance', 'closing balance'],
    ];

    public function import(int $bankAccountId, array $rows, string $source, ?string $fileName, int $tenantId, ?int $userId, bool $simulate = false): array
    {
        $bank = BankAccount::forTenant($tenantId)->find($bankAccountId);
        if (! $bank) {
            throw new BusinessException('Bank account not found.');
        }
        if (count($rows) < 2) {
            throw new BusinessException('The file has no data rows.');
        }

        $map = $this->mapColumns(array_shift($rows));
        if (! isset($map['date']) || (! isset($map['debit']) && ! isset($map['credit']))) {
            throw new BusinessException('Could not find a Date column and a Debit/Withdrawal or Credit/Deposit column in the header.');
        }

        $parsed = [];
        $errors = 0;
        foreach ($rows as $row) {
            $date = $this->parseDate($this->cell($row, $map, 'date'));
            $debit = $this->num($this->cell($row, $map, 'debit'));
            $credit = $this->num($this->cell($row, $map, 'credit'));

            if (! $date || ($debit == 0 && $credit == 0)) {
                $errors++;
                continue;
            }

            $parsed[] = [
                'txn_date'        => $date,
                'description'     => $this->cell($row, $map, 'description') ?: null,
                'ref_no'          => $this->cell($row, $map, 'ref_no') ?: null,
                'debit'           => $debit,
                'credit'          => $credit,
                'running_balance' => isset($map['balance']) ? ($this->num($this->cell($row, $map, 'balance')) ?: null) : null,
            ];
        }

        if (empty($parsed)) {
            throw new BusinessException('No valid rows found. Check the date and amount columns.');
        }

        $dates = array_column($parsed, 'txn_date');
        sort($dates);

        if ($simulate) {
            return ['simulated' => true, 'valid' => count($parsed), 'skipped' => $errors, 'from' => $dates[0], 'to' => end($dates)];
        }

        return DB::transaction(function () use ($bank, $parsed, $errors, $dates, $source, $fileName, $tenantId, $userId) {
            $import = BankStatementImport::create([
                'tenant_id'       => $tenantId,
                'bank_account_id' => $bank->id,
                'source'          => $source,
                'file_name'       => $fileName,
                'from_date'       => $dates[0],
                'to_date'         => end($dates),
                'lines_count'     => count($parsed),
                'status'          => 'imported',
                'created_by'      => $userId,
                'imported_at'     => now(),
            ]);

            $now = now();
            $rows = array_map(fn ($line) => [
                'tenant_id'       => $tenantId,
                'import_id'       => $import->id,
                'bank_account_id' => $bank->id,
                'match_status'    => 'unmatched',
                'created_at'      => $now,
                'updated_at'      => $now,
                ...$line,
            ], $parsed);
            BankStatementLine::insert($rows);

            Log::channel('accounts')->info('Bank statement imported', [
                'import_id' => $import->id, 'bank_account_id' => $bank->id, 'lines' => count($parsed),
            ]);

            return ['import_id' => $import->id, 'imported' => count($parsed), 'skipped' => $errors, 'from' => $dates[0], 'to' => end($dates)];
        });
    }

    public function imports(int $tenantId, int $bankAccountId)
    {
        return BankStatementImport::forTenant($tenantId)
            ->where('bank_account_id', $bankAccountId)
            ->withCount('lines')
            ->orderByDesc('imported_at')
            ->get();
    }

    public function lines(int $tenantId, int $bankAccountId, array $filters)
    {
        $query = BankStatementLine::forTenant($tenantId)->where('bank_account_id', $bankAccountId);

        if (! empty($filters['match_status'])) {
            $query->where('match_status', $filters['match_status']);
        }

        return $query->orderBy('txn_date')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 100));
    }

    /** Delete an import and all its lines (only if none are matched/reconciled). */
    public function revert(BankStatementImport $import, int $tenantId): void
    {
        if ($import->tenant_id !== $tenantId) {
            throw new BusinessException('Import not found.');
        }
        if ($import->lines()->where('match_status', 'matched')->exists()) {
            throw new BusinessException('Some lines are already matched/reconciled. Un-match them before reverting.');
        }

        $import->lines()->delete();
        $import->delete();
    }

    /* ── Parsing helpers ──────────────────────────────────────── */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $label) {
            $norm = strtolower(trim((string) $label));
            foreach (self::HEADER_MAP as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }
                if (in_array($norm, $aliases, true)) {
                    $map[$field] = $idx;
                }
            }
        }
        return $map;
    }

    private function cell(array $row, array $map, string $field): ?string
    {
        if (! isset($map[$field]) || ! array_key_exists($map[$field], $row)) {
            return null;
        }
        $v = $row[$map[$field]];
        return $v === null ? null : trim((string) $v);
    }

    private function num(?string $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        return round((float) str_replace([',', '₹', ' '], '', $v), 2);
    }

    private function parseDate(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        // Excel serial date (rough): a bare number in a plausible range.
        if (is_numeric($v) && (int) $v > 30000 && (int) $v < 60000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd/m/y', 'd M Y', 'd-M-Y', 'd-M-y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // try next
            }
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
