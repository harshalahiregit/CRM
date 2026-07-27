<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single entry in the polymorphic audit trail.
 *
 * @see \App\Models\Traits\Auditable  attach to any model to make it auditable
 * @see \App\Services\AuditLogService the central writer
 */
class AuditLog extends Model
{
    protected $fillable = [
        'tenant_id', 'auditable_type', 'auditable_id',
        'action', 'actor_id', 'actor_name', 'actor_role',
        'comment', 'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The record this log entry describes (Manpower Request, Candidate, …). */
    public function auditable()
    {
        return $this->morphTo();
    }

    /** The user who performed the action (may be null / deleted — see snapshots). */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
