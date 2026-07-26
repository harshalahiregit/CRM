<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
    // The only Project model that was missing this — ProjectMember::forTenant()
    // would have fatalled, and rows relied on callers passing tenant_id by hand.
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'user_id'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
