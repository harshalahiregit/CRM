<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line from an imported bank statement. `debit` = withdrawal (money out of
 * the bank), `credit` = deposit (money in) — the bank's own perspective. During
 * reconciliation a line is matched to a bank-ledger voucher line.
 */
class BankStatementLine extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_bank_statement_lines';

    protected $fillable = [
        'tenant_id', 'import_id', 'bank_account_id', 'txn_date', 'value_date',
        'description', 'ref_no', 'debit', 'credit', 'running_balance',
        'match_status', 'matched_voucher_line_id', 'reconciliation_id',
    ];

    protected $casts = [
        'txn_date'        => 'date',
        'value_date'      => 'date',
        'debit'           => 'decimal:2',
        'credit'          => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function scopeUnmatched($query)
    {
        return $query->whereIn('match_status', ['unmatched', 'suggested']);
    }
}
