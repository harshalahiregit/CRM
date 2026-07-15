<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskFile extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'task_id', 'file_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by',
    ];

    protected $casts = ['file_size' => 'integer'];

    /** file_path is a private-disk location — never expose it to the client. */
    protected $hidden = ['file_path'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
