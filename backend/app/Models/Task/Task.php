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
        'tenant_id', 'parent_id', 'root_id', 'depth',
        'name', 'description', 'priority', 'status',
        'start_date', 'due_date', 'date_finished', 'rel_type', 'rel_id',
        'milestone_id', 'billable', 'billed', 'hourly_rate', 'billable_amount', 'rate_unit',
        'is_public', 'visible_to_client', 'kanban_order', 'created_by',
        'recurring', 'recurring_type', 'repeat_every', 'cycles', 'total_cycles',
        'last_recurring_date', 'is_recurring_from', 'deadline_notified',
    ];

    protected $casts = [
        'depth'               => 'integer',
        'start_date'          => 'date',
        'due_date'            => 'date',
        'date_finished'       => 'datetime',
        'billable'            => 'boolean',
        'billed'              => 'boolean',
        'is_public'           => 'boolean',
        'visible_to_client'   => 'boolean',
        'kanban_order'        => 'integer',
        'hourly_rate'         => 'decimal:2',
        'billable_amount'     => 'decimal:2',
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

    /* ── Billing (PR1) ──────────────────────────────────────────── */

    /** Total logged seconds across completed timers (uses the loaded relation if present). */
    public function loggedSeconds(): int
    {
        $timers = $this->relationLoaded('timers')
            ? $this->timers->whereNotNull('end_time')
            : $this->timers()->whereNotNull('end_time')->get();

        return (int) $timers->sum(fn ($t) => $t->durationSeconds());
    }

    /**
     * The task's effective billable amount: the FIXED billable_amount when set,
     * otherwise hourly_rate × logged hours. Returns 0.0 when neither applies.
     */
    public function effectiveBillableAmount(): float
    {
        if ($this->billable_amount !== null && (float) $this->billable_amount > 0) {
            return round((float) $this->billable_amount, 2);
        }

        return round((float) $this->hourly_rate * ($this->loggedSeconds() / 3600), 2);
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

    /* ── Subtask tree ───────────────────────────────────────────── */

    /** The task this one sits under. Null for a top-level task. */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The top of this task's tree. Points at itself for a top-level task. */
    public function rootTask()
    {
        return $this->belongsTo(self::class, 'root_id');
    }

    /** Direct children only. The full tree is TaskTreeService's job. */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('kanban_order')->orderBy('id');
    }

    public function isSubtask(): bool
    {
        return $this->parent_id !== null;
    }

    // Additional "Related To" links (beyond the primary rel_type/rel_id). rel_id is
    // polymorphic by rel_type — resolved to a label by TaskService.
    public function relations()
    {
        return $this->hasMany(TaskRelation::class);
    }

    // rel_id is polymorphic (project|ticket|customer) — resolved by the service,
    // never a hard Eloquent relation, so unbuilt modules don't break this model.
}
