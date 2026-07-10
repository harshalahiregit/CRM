<?php

namespace App\Models\Task;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskTimer extends Model
{
    protected $fillable = ['tenant_id', 'task_id', 'user_id', 'start_time', 'end_time', 'hourly_rate', 'note'];

    protected $casts = [
        'start_time'  => 'datetime',
        'end_time'    => 'datetime',
        'hourly_rate' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Duration in seconds (running timers count up to now). */
    public function durationSeconds(): int
    {
        return $this->start_time->diffInSeconds($this->end_time ?? now());
    }
}
