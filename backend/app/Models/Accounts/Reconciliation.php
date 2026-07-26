<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank reconciliation session. Complete when the cleared book balance equals
 * the entered statement (bank) balance — i.e. difference = 0 (spec §4.7).
 */
class Reconciliation extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_reconciliations';

    protected $fillable = [
        'tenant_id', 'bank_account_id', 'statement_date', 'opening_balance',
        'statement_balance', 'book_balance', 'difference', 'status', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'statement_date'    => 'date',
        'opening_balance'   => 'decimal:2',
        'statement_balance' => 'decimal:2',
        'book_balance'      => 'decimal:2',
        'difference'        => 'decimal:2',
        'completed_at'      => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class);
    }
}
