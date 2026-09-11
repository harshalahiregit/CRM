<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — a corrective or preventive action.
 *
 * Attaches to EITHER an issue or a recurrence group, never both. A CAPA raised
 * because a defect has now happened five times belongs to the pattern; attached
 * to the fifth occurrence it would be closed and forgotten when that issue closed.
 */
class CorrectiveAction extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\CorrectiveActionFactory
    {
        return \Sire\Database\Factories\CorrectiveActionFactory::new();
    }
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const CORRECTIVE  = 'corrective';
    public const PREVENTIVE  = 'preventive';
    public const CONTAINMENT = 'containment';
    public const TYPES = [self::CORRECTIVE, self::PREVENTIVE, self::CONTAINMENT];

    public const STATUSES = ['open', 'in_progress', 'completed', 'verified', 'cancelled'];

    /** Statuses that still block a recurrence group from being closed out. */
    public const OPEN_STATUSES = ['open', 'in_progress'];

    public const EFFECTIVENESS = ['effective', 'partial', 'ineffective'];

    protected $table = 'sire_actions';

    protected $guarded = ['id'];

    protected $casts = [
        'due_at'       => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function recurrenceGroup(): BelongsTo
    {
        return $this->belongsTo(RecurrenceGroup::class, 'recurrence_group_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'owner_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'verified_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue($query)
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_at !== null
            && $this->due_at->isPast();
    }
}
