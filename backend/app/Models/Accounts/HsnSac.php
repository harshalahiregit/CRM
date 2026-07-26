<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HSN (goods) / SAC (services) catalog with a default GST rate. */
class HsnSac extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_hsn_sac';

    protected $fillable = [
        'tenant_id', 'code', 'description', 'type', 'default_gst_rate_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function defaultRate(): BelongsTo
    {
        return $this->belongsTo(GstRate::class, 'default_gst_rate_id');
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }
        return $query->where(fn ($q) => $q->where('code', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
    }
}
