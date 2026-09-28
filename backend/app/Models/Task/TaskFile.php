<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A file attached to a task, or to one comment on it (`comment_id`).
 *
 * Uploader is polymorphic for the same reason TaskComment's author is: a
 * Purchase vendor has no User row. Read it through `author_label`.
 */
class TaskFile extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'task_id', 'comment_id', 'file_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by',
        'author_kind', 'author_id', 'author_name',
    ];

    protected $casts = ['file_size' => 'integer'];

    /** file_path is a private-disk location — never expose it to the client. */
    protected $hidden = ['file_path'];

    protected $appends = ['author_label'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getAuthorLabelAttribute(): string
    {
        return $this->uploader?->name ?? $this->author_name ?? 'Unknown';
    }
}
