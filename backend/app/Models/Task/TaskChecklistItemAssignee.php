<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One person on one checklist line. See the pivot's migration for why. */
class TaskChecklistItemAssignee extends Model
{
    use BelongsToTenant;

    protected $table = 'task_checklist_item_assignees';

    protected $fillable = ['tenant_id', 'item_id', 'user_id'];

    protected $casts = ['user_id' => 'integer'];

    public function item()
    {
        return $this->belongsTo(TaskChecklistItem::class, 'item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
