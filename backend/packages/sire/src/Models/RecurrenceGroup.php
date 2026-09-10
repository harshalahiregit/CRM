<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — a defect that keeps coming back.
 *
 * Not a duplicate group. A duplicate is the same OCCURRENCE reported twice; a
 * recurrence is the same DEFECT occurring again, and every member is real,
 * separate work. The count is the point.
 *
 * Every statistic here is derived by SireRecurrenceService::recompute(). None of
 * them is user-editable: a count someone can type is a count that will be wrong.
 */
class RecurrenceGroup extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const FIX_STATUSES = ['none', 'planned', 'in_progress', 'shipped', 'verified'];
    public const RISKS        = ['low', 'medium', 'high', 'critical'];

    protected $table = 'sire_recurrence_groups';

    protected $guarded = ['id'];

    protected $casts = [
        'first_occurrence_at'   => 'datetime',
        'latest_occurrence_at'  => 'datetime',
        'risk_computed_at'      => 'datetime',
        'occurrence_count'      => 'integer',
        'average_interval_days' => 'float',
        'is_closed'             => 'boolean',
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(Report::class, 'recurrence_group_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'owner_id');
    }

    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(RootCause::class, 'root_cause_id');
    }

    public function permanentFixRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'permanent_fix_release_id');
    }

    /** CAPA raised against the PATTERN, not against any one occurrence. */
    public function actions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'recurrence_group_id');
    }

    /**
     * Deterministic grouping hint. Used to SUGGEST membership in the UI, never to
     * assign it: exact-match on facts the register already holds, no fuzzy string
     * comparison and no model.
     */
    public static function signatureFor(Report $report): ?string
    {
        $parts = array_filter([
            $report->module,
            $report->section,
            $report->screen,
            $report->category?->code,
        ]);

        return $parts === [] ? null : mb_substr(implode('|', $parts), 0, 191);
    }
}
