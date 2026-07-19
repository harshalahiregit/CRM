<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DiscussionComment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'discussion_id', 'user_id', 'content',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function discussion()
    {
        return $this->belongsTo(ProjectDiscussion::class, 'discussion_id');
    }
}
