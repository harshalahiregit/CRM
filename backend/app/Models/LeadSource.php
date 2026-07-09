<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'sort_order',
    ];

    /* ── Relationships ─────────────────────── */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'source_id');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
