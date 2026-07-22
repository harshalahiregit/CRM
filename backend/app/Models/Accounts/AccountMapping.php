<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds a semantic posting role (e.g. sales_income, output_cgst, bank_default) to
 * a concrete ledger, so posting rules reference roles, not hard-coded ledger ids
 * (spec v2 §7).
 */
class AccountMapping extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_account_mappings';

    protected $fillable = ['tenant_id', 'role_key', 'ledger_id'];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
