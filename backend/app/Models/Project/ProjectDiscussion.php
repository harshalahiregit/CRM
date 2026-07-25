<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectDiscussion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'subject', 'body', 'visible_to_customer', 'created_by',
    ];

    protected $casts = [
        'visible_to_customer' => 'boolean',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(DiscussionComment::class, 'discussion_id')->oldest();
    }
}
