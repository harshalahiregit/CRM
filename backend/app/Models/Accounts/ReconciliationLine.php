<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank-ledger voucher line marked "cleared" within a reconciliation. Kept as a
 * pivot so the Phase-1 voucher_lines table is never altered by banking.
 */
class ReconciliationLine extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_reconciliation_lines';

    protected $fillable = [
        'tenant_id', 'reconciliation_id', 'voucher_line_id', 'statement_line_id',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function voucherLine(): BelongsTo
    {
        return $this->belongsTo(VoucherLine::class);
    }
}
