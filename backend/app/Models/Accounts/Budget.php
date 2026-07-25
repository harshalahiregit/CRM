<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A budget for a financial year; targets live in acc_budget_lines (per ledger per month). */
class Budget extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_budgets';

    protected $fillable = ['tenant_id', 'financial_year_id', 'name'];

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
