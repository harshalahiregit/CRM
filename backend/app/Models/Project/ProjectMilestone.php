<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectMilestone extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'description', 'due_date',
        'start_date', 'color', 'order', 'hide_from_customer',
    ];

    protected $casts = [
        'due_date'           => 'date',
        'start_date'         => 'date',
        'order'              => 'integer',
        'hide_from_customer' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
