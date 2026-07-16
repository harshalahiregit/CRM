<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-configurable task status.
 *
 * `key` is what tasks.status stores and never changes; `name` is the label and
 * can be renamed freely. See the create_status_lookup_tables migration.
 */
class TaskStatus extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'key', 'name', 'color', 'order',
        'is_default_filter', 'is_closed_status', 'is_system',
        'can_be_changed_to', 'hidden_for',
    ];

    protected $casts = [
        'order'             => 'integer',
        'is_default_filter' => 'boolean',
        'is_closed_status'  => 'boolean',
        'is_system'         => 'boolean',
        'can_be_changed_to' => 'array',
        'hidden_for'        => 'array',
    ];
}
