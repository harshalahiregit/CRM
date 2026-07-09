<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'lead_id', 'content', 'contact_date', 'created_by',
    ];

    protected $casts = [
        'contact_date' => 'date',
    ];

    /* ── Relationships ─────────────────────── */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
