<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SIRE — a comment somebody typed, when the host has no notes system.
 *
 * Editable by its author, which is the whole difference between this and
 * AuditEvent. SIRE's timeline merges both and tags each `kind` so the UI keeps
 * showing which is which.
 *
 * `author_name` is snapshotted rather than joined: a comment should still say who
 * wrote it after that person leaves.
 */
class Note extends Model
{
    use BelongsToSireTenant;

    protected $table = 'sire_notes';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id',
        'body', 'is_internal', 'author_id', 'author_name',
    ];

    protected $casts = ['is_internal' => 'boolean'];
}
