<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A transaction document (header). Its debit/credit legs live in voucher_lines.
 * Posted vouchers are never hard-deleted — they're `cancelled` and neutralised by
 * a reversing voucher (is_reversal / reversed_voucher_id), preserving the trail.
 */
class Voucher extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_vouchers';

    protected $fillable = [
        'tenant_id', 'voucher_type_id', 'financial_year_id', 'number', 'date',
        'narration', 'party_id', 'reference_no', 'status', 'total_amount',
        'is_reversal', 'reversed_voucher_id', 'created_by', 'source_type', 'source_id',
    ];

    protected $casts = [
        'date'         => 'date',
        'total_amount' => 'decimal:2',
        'is_reversal'  => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class, 'voucher_type_id');
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class, 'financial_year_id');
    }

    public function reversedVoucher(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_voucher_id');
    }

    /** The reversing voucher that neutralised this one, if any. */
    public function reversedBy()
    {
        return $this->hasOne(self::class, 'reversed_voucher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }
}
