<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectMeeting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'mode', 'participants',
        'planned_date', 'meeting_date', 'status', 'mom_sent', 'notes', 'created_by',
    ];

    protected $casts = [
        'planned_date' => 'datetime',
        'meeting_date' => 'datetime',
        'mom_sent'     => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
