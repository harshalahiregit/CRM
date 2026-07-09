<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadActivity extends Model
{
    use HasFactory, BelongsToTenant;

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
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
