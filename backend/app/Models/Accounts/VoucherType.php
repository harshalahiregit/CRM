<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template governing which legs a voucher produces (Sales, Purchase, Payment,
 * Receipt, Contra, Journal, Debit/Credit Note, Stock Journal). All converge on
 * PostingService; the type mainly drives numbering + stock/GST flags.
 */
class VoucherType extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_voucher_types';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'is_system', 'active', 'numbering_series_id', 'affects_stock', 'affects_gst',
    ];

    protected $casts = [
        'affects_stock' => 'boolean',
        'affects_gst'   => 'boolean',
        'is_system'     => 'boolean',
        'active'        => 'boolean',
    ];

    public function numberingSeries(): BelongsTo
    {
        return $this->belongsTo(NumberingSeries::class, 'numbering_series_id');
    }
}
