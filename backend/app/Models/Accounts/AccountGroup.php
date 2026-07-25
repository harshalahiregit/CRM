<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the chart-of-accounts classification tree (Tally "Group"). Carries
 * `nature` (asset/liability/equity/income/expense) which flows down to child
 * ledgers and drives every report's sign logic. `is_system` groups are the
 * standard seeded groups and cannot be deleted.
 */
class AccountGroup extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_account_groups';

    protected $fillable = [
        'tenant_id', 'parent_id', 'name', 'nature', 'is_primary', 'is_system',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_system'  => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class, 'group_id');
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where('name', 'like', "%{$term}%");
    }
}
