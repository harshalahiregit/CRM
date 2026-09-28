<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskChecklistItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'task_id', 'description', 'finished', 'finished_by', 'order', 'assigned_to',
    ];

    protected $casts = [
        'finished' => 'boolean',
        'order'    => 'integer',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The FIRST person on this item, as a convenience for code that wants one
     * name. Reads the mirror column — see assignees() for the real answer.
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Everyone this item is on — staff, vendors or TPVs (all are Users).
     *
     * This is the truth. `assigned_to` is a mirror of the first row of it, kept
     * so the notification leg and anything outside this module that reads the
     * column go on working; only TaskService::assignChecklistItem() writes
     * either, and it writes both.
     */
    public function assignees()
    {
        return $this->hasMany(TaskChecklistItemAssignee::class, 'item_id');
    }
}
