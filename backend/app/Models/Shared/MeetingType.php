<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-defined meeting type + its agenda template (Meeting.docx — admin
 * Types/Templates settings). Layered over config/meetings.php by
 * {@see MeetingTypeCatalog}; a row adds a new type or
 * overrides a built-in one's label/template for its tenant.
 */
class MeetingType extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_types';

    protected $fillable = [
        'tenant_id', 'key', 'label', 'templates', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'templates' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
