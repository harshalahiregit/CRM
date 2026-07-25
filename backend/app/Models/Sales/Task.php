<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\LogsSalesActivity;

class Task extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, LogsSalesActivity;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'priority',
        'taskable_type', 'taskable_id', 'status_id',
        'start_date', 'due_date', 'date_finished', 'progress',
        'is_billable', 'billable_amount', 'is_recurring', 'recur_every',
        'recur_type', 'recur_cycles', 'recur_total_cycles', 'last_recurred_date',
        'kanban_order', 'created_by',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'due_date'           => 'date',
        'date_finished'      => 'date',
        'last_recurred_date' => 'date',
        'progress'           => 'integer',
        'is_billable'        => 'boolean',
        'is_recurring'       => 'boolean',
        'billable_amount'    => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            if (empty($task->status_id) && $task->tenant_id) {
                $default = TaskStatus::getDefault($task->tenant_id);
                if ($default) {
                    $task->status_id = $default->id;
                }
            }
        });
    }

    /* ── Relationships ─────────────────────── */
    public function taskable()
    {
        return $this->morphTo();
    }

    public function status()
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function assignees()
    {
        return $this->belongsToMany(User::class, 'task_assignees', 'task_id', 'staff_id')
                    ->withPivot('assigned_at')
                    ->withTimestamps();
    }

    public function checklistItems()
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('taskable_type', $type)->where('taskable_id', $id);
    }
}
