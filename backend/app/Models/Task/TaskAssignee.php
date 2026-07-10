<?php

namespace App\Models\Task;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskAssignee extends Model
{
    protected $fillable = ['tenant_id', 'task_id', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
