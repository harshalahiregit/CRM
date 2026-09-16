<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SIRE — one thing that happened, when the host has no audit system.
 *
 * WRITE-ONCE, AND ENFORCED HERE AS WELL AS IN THE SCHEMA
 *
 * `$timestamps = false` with a manually-set created_at is not a shortcut: this
 * table has no updated_at because there is no update. The model refuses to save
 * an existing row at all — release approvals and emergency overrides are
 * defended by this trail, and a history that can be rewritten afterwards proves
 * nothing about what happened.
 *
 * Deleting is left to the database's retention policy, not to application code.
 */
class AuditEvent extends Model
{
    use BelongsToSireTenant;

    protected $table = 'sire_audit_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'action', 'comment',
        'actor_id', 'actor_name', 'actor_role', 'before', 'after', 'metadata', 'created_at',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The last line of defence. The schema has no updated_at and no endpoint
        // reaches here, but a future `$event->update(...)` would still silently
        // rewrite history — so it throws instead.
        static::updating(function (): void {
            throw new \RuntimeException(
                'SIRE: audit events are write-once. Record a new event instead of editing one.'
            );
        });

        static::deleting(function (): void {
            throw new \RuntimeException(
                'SIRE: audit events cannot be deleted. Retention is a database policy, not an application one.'
            );
        });
    }
}
