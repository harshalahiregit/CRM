<?php

namespace App\Models\Project;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
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
