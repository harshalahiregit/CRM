<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class Reminder extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'remindable_type', 'remindable_id', 'type', 'title', 'notes',
        'due_at', 'priority', 'staff_id', 'outcome', 'next_follow_up',
        'is_recurring', 'recur_every', 'recur_type', 'is_notified',
        'notified_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'due_at'         => 'datetime',
        'next_follow_up' => 'datetime',
        'is_recurring'   => 'boolean',
        'is_notified'    => 'boolean',
        'notified_at'    => 'datetime',
        'completed_at'   => 'datetime',
    ];

    /* ── Relationships ─────────────────────── */
    public function remindable()
    {
        return $this->morphTo();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopePending($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('remindable_type', $type)->where('remindable_id', $id);
    }

    public function scopeDue($query)
    {
        return $query->whereNull('completed_at')->where('due_at', '<=', now());
    }
}
