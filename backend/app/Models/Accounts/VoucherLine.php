<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or credit leg of a voucher. THIS TABLE IS THE JOURNAL / ledger of
 * record — every financial report is derived from posted rows here.
 */
class VoucherLine extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_voucher_lines';

    protected $fillable = [
        'tenant_id', 'voucher_id', 'ledger_id', 'debit', 'credit', 'line_narration',
    ];

    protected $casts = [
        'debit'  => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
