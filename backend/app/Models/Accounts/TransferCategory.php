<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Category / Head" a fund transfer is classified under (Reversal, Excess
 * Payment Return, Double Payment Refund, …) — a reporting dimension alongside
 * the free-text narration.
 */
class TransferCategory extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_transfer_categories';

    protected $fillable = ['tenant_id', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
