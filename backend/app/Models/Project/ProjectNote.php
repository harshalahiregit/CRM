<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectNote extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'title', 'content', 'created_by'];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
