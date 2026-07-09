<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadActivity extends Model
{
    use HasFactory;

    protected $table = 'lead_activities';

    protected $fillable = [
        'tenant_id', 'lead_id', 'type', 'description',
        'old_value', 'new_value', 'performed_by',
    ];

    /* ── Relationships ─────────────────────── */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
