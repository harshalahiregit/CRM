<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'status', 'customer_id',
        'billing_type', 'project_cost', 'rate_per_hour', 'start_date', 'deadline',
        'progress', 'progress_from_tasks', 'estimated_hours', 'created_by', 'date_finished',
        'pinned_by', 'visible_tabs', 'customer_permissions', 'deadline_notified',
    ];

    protected $casts = [
        'start_date'           => 'date',
        'deadline'             => 'date',
        'date_finished'        => 'datetime',
        'progress'             => 'integer',
        'progress_from_tasks'  => 'boolean',
        'project_cost'         => 'decimal:2',
        'rate_per_hour'        => 'decimal:2',
        'estimated_hours'      => 'decimal:2',
        'pinned_by'            => 'array',
        'visible_tabs'         => 'array',
        'customer_permissions' => 'array',
        'deadline_notified'    => 'boolean',
    ];

    /** Who pinned this is an implementation detail — the UI only needs is_pinned. */
    protected $hidden = ['pinned_by'];

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    /* ── Relationships ──────────────────────────────────────────── */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('order');
    }

    public function files()
    {
        return $this->hasMany(ProjectFile::class);
    }

    // NOTE: no customer() relation — Customer belongs to Zafar's module. Resolve
    // customer data through CustomerServiceContract, never an Eloquent join here.
    // NOTE: tasks are polymorphic (rel_type='project', rel_id=id); queried in the
    // Task module rather than via a direct hasMany to avoid a hard cross-module bind.
}
