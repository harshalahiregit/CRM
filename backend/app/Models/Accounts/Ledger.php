<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A postable account (Tally "Ledger") — including bank accounts, cash, and party
 * control accounts. `nature` is inherited from its group. A ledger with postings
 * can only be deactivated, never deleted (ChartOfAccountsService enforces this).
 */
class Ledger extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_ledgers';

    protected $fillable = [
        'tenant_id', 'group_id', 'name', 'code',
        'opening_balance', 'opening_balance_type',
        'is_bank', 'is_cash', 'is_party', 'party_id', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_bank'         => 'boolean',
        'is_cash'         => 'boolean',
        'is_party'        => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class, 'ledger_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }
}
