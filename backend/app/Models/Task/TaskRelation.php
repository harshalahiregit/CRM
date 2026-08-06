<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One additional "Related To" link on a task (beyond its primary rel_type/rel_id).
 * rel_id is polymorphic by rel_type — resolved to a label by TaskService.
 */
class TaskRelation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'task_id', 'rel_type', 'rel_id'];

    protected $casts = [
        'rel_id' => 'integer',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
