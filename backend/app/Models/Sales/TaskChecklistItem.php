<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class TaskChecklistItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'task_checklist_items';

    protected $fillable = [
        'tenant_id', 'task_id', 'description',
        'is_finished', 'finished_by', 'finished_at', 'sort_order',
    ];

    protected $casts = [
        'is_finished' => 'boolean',
        'finished_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function finisher()
    {
        return $this->belongsTo(User::class, 'finished_by');
    }
}
