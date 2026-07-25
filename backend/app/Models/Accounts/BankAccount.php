<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank account, always backed by an is_bank ledger under the Bank Accounts
 * group. `current_balance` is a cache — the truth is the ledger's posted lines.
 */
class BankAccount extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_bank_accounts';

    protected $fillable = [
        'tenant_id', 'ledger_id', 'account_no', 'ifsc', 'bank_name',
        'branch', 'account_type', 'current_balance', 'is_active',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
