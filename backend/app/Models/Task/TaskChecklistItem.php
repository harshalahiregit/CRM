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

    /** Who this single item is on — a staff member, vendor, or TPV (all are Users). */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
