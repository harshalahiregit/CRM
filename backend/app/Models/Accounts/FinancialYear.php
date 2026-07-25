<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Accounting period boundary (India FY: Apr 1 – Mar 31). `is_closed` locks the
 * period — the PostingService refuses to post into a closed FY (spec §7.3).
 */
class FinancialYear extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_financial_years';

    protected $fillable = [
        'tenant_id', 'start_date', 'end_date', 'is_closed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_closed'  => 'boolean',
    ];

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    /** The open FY containing the given date, if any. */
    public function scopeContaining($query, $date)
    {
        return $query->where('start_date', '<=', $date)->where('end_date', '>=', $date);
    }
}
