<?php

namespace App\Models\Task;

use App\Models\Project\ProjectMilestone;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'priority', 'status',
        'start_date', 'due_date', 'date_finished', 'rel_type', 'rel_id',
        'milestone_id', 'billable', 'billed', 'hourly_rate',
        'is_public', 'visible_to_client', 'kanban_order', 'created_by',
        'recurring', 'recurring_type', 'repeat_every', 'cycles', 'total_cycles',
        'last_recurring_date', 'is_recurring_from', 'deadline_notified',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'due_date'            => 'date',
        'date_finished'       => 'datetime',
        'billable'            => 'boolean',
        'billed'              => 'boolean',
        'is_public'           => 'boolean',
        'visible_to_client'   => 'boolean',
        'kanban_order'        => 'integer',
        'hourly_rate'         => 'decimal:2',
        'recurring'           => 'boolean',
        'repeat_every'        => 'integer',
        'cycles'              => 'integer',
        'total_cycles'        => 'integer',
        'last_recurring_date' => 'date',
        'deadline_notified'   => 'boolean',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestone()
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function assignees()
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function followers()
    {
        return $this->hasMany(TaskFollower::class);
    }

    public function checklistItems()
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('order');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function timers()
    {
        return $this->hasMany(TaskTimer::class);
    }

    public function files()
    {
        return $this->hasMany(TaskFile::class)->latest();
    }

    public function reminders()
    {
        return $this->hasMany(TaskReminder::class)->orderBy('remind_at');
    }

    /** The recurring template this task was auto-created from, if any. */
    public function recurringParent()
    {
        return $this->belongsTo(self::class, 'is_recurring_from');
    }

    // rel_id is polymorphic (project|ticket|customer) — resolved by the service,
    // never a hard Eloquent relation, so unbuilt modules don't break this model.
}
