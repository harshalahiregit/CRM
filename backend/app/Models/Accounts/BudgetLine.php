<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A budgeted amount for one ledger in one month of the budget's financial year. */
class BudgetLine extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_budget_lines';

    protected $fillable = ['tenant_id', 'budget_id', 'ledger_id', 'month', 'year', 'amount'];

    protected $casts = [
        'month'  => 'integer',
        'year'   => 'integer',
        'amount' => 'decimal:2',
    ];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
