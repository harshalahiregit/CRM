<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectNoteAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_note_id', 'original_name', 'path', 'size', 'mime', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function note()
    {
        return $this->belongsTo(ProjectNote::class, 'project_note_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
