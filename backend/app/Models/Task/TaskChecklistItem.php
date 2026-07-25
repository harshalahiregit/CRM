<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
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
}
