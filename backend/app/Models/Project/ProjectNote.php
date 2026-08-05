<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'content', 'created_by',
        'assigned_to', 'remind_at', 'reminded',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'reminded'  => 'boolean',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments()
    {
        return $this->hasMany(ProjectNoteAttachment::class)->latest();
    }
}
