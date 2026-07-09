<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadStatus extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'color', 'sort_order', 'is_default', 'is_won_status',
    ];

    protected $casts = [
        'is_default'    => 'boolean',
        'is_won_status' => 'boolean',
    ];

    /* ── Relationships ─────────────────────── */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /* ── Helpers ────────────────────────────── */
    public static function getDefault(int $tenantId): ?self
    {
        return static::forTenant($tenantId)->where('is_default', true)->first();
    }

    public static function getWonStatus(int $tenantId): ?self
    {
        return static::forTenant($tenantId)->where('is_won_status', true)->first();
    }
}
