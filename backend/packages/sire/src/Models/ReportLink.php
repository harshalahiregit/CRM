<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — a many-to-many relationship between two issues.
 *
 * Duplicates are NOT stored here: that relationship is 1:1, is enforced by the
 * mark_duplicate transition, and lives on sire_reports.duplicate_of_id. Two
 * sources of truth for one relationship is how they drift.
 */
class ReportLink extends Model
{
    use BelongsToSireTenant;

    public const TYPE_REGRESSION_OF = 'regression_of';
    public const TYPE_RELATED_TO    = 'related_to';
    public const TYPE_CAUSED_BY     = 'caused_by';
    public const TYPE_BLOCKS        = 'blocks';

    public const TYPES = [
        self::TYPE_REGRESSION_OF, self::TYPE_RELATED_TO,
        self::TYPE_CAUSED_BY, self::TYPE_BLOCKS,
    ];

    /** How each link reads from the other end, for rendering the inbound side. */
    public const INVERSE_LABELS = [
        self::TYPE_REGRESSION_OF => 'has regression',
        self::TYPE_RELATED_TO    => 'related to',
        self::TYPE_CAUSED_BY     => 'caused',
        self::TYPE_BLOCKS        => 'blocked by',
    ];

    protected $table = 'sire_report_links';

    protected $guarded = ['id'];

    public function fromReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'from_report_id');
    }

    public function toReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'to_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'created_by');
    }
}
