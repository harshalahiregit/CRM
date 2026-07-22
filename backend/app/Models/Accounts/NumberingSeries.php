<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Gap-less sequential numbering, one series per voucher type. The next number is
 * allocated atomically inside the posting transaction (see NumberingService,
 * which lockForUpdate()s this row) so numbers never duplicate under concurrency.
 */
class NumberingSeries extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_numbering_series';

    protected $fillable = [
        'tenant_id', 'voucher_type_code', 'prefix', 'suffix',
        'next_number', 'reset_frequency', 'width',
    ];

    protected $casts = [
        'next_number' => 'integer',
        'width'       => 'integer',
    ];
}
