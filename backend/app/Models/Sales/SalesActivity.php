<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class SalesActivity extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'sales_activities';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'type',
        'description', 'old_value', 'new_value', 'performed_by',
    ];

    /* ── Relationships ─────────────────────── */
    public function subject()
    {
        return $this->morphTo();
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

    public function scopeForSubject($query, string $subjectType, int $subjectId)
    {
        return $query->where('subject_type', $subjectType)
                     ->where('subject_id', $subjectId);
    }
}
