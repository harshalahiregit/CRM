<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'subject', 'description', 'status', 'priority',
        'assigned_to', 'customer_id', 'due_date', 'source',
    ];

    protected $casts = [
        'due_date' => 'datetime',
    ];

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopePriority($query, ?string $priority)
    {
        return $priority ? $query->where('priority', $priority) : $query;
    }

    /* ── Relationships ──────────────────────────────────────────── */
    public function replies()
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    public function feedback()
    {
        return $this->hasOne(TicketFeedback::class);
    }

    // Assignee is a shared auth user (staff/agent), so a real relation is fine.
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // NOTE: no customer() relation — customers belong to Zafar's module. Resolve
    // customer data through CustomerServiceContract, never an Eloquent join here.
}
