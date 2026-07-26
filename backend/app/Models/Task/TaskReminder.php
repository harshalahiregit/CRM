<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskReminder extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'task_id', 'user_id', 'created_by', 'description', 'remind_at', 'notified_at',
    ];

    protected $casts = [
        'remind_at'   => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Pending and due — exactly what the scheduler fires on. */
    public function scopeDue($query)
    {
        return $query->whereNull('notified_at')->where('remind_at', '<=', now());
    }
}
