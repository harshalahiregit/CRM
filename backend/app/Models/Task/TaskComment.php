<?php

namespace App\Models\Task;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskComment extends Model
{
    protected $fillable = ['tenant_id', 'task_id', 'user_id', 'content'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Files attached to THIS comment (task files carrying this comment_id). */
    public function attachments()
    {
        return $this->hasMany(TaskFile::class, 'comment_id');
    }
}
