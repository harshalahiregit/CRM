<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class TaskComment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'task_comments';

    protected $fillable = [
        'tenant_id', 'task_id', 'content', 'created_by',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
