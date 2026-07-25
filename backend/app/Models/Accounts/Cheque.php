<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An issued or received cheque with a lifecycle status. A post-dated cheque (PDC)
 * sits as `post_dated` until its due date, when a scheduled job surfaces it for
 * clearing (spec §4.8 / §5.5).
 */
class Cheque extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_cheques';

    protected $fillable = [
        'tenant_id', 'bank_account_id', 'chequebook_id', 'voucher_id', 'direction', 'cheque_no',
        'cheque_date', 'party_name', 'amount', 'is_account_payee', 'status', 'is_pdc', 'pdc_due_date',
        'cleared_date', 'memo', 'reference', 'source_type', 'payer_bank', 'created_by',
    ];

    protected $casts = [
        'cheque_date'      => 'date',
        'pdc_due_date'     => 'date',
        'cleared_date'     => 'date',
        'amount'           => 'decimal:2',
        'is_pdc'           => 'boolean',
        'is_account_payee' => 'boolean',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function chequebook(): BelongsTo
    {
        return $this->belongsTo(Chequebook::class);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('cheque_no', 'like', "%{$term}%")
            ->orWhere('party_name', 'like', "%{$term}%"));
    }
}
