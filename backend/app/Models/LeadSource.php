<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadSource extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'sort_order',
    ];

    /* ── Relationships ─────────────────────── */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'source_id');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
