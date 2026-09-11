<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Sire\Support\SireReleaseClass;
use Sire\Support\SireStatus;
use Sire\Support\SireTrack;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — an issue. Defect or change request, depending on `workflow_track`.
 *
 * BelongsToTenant supplies an OPT-IN scopeForTenant() and a creating hook that
 * only fires when a user is authenticated. It is NOT a global scope — there are
 * zero addGlobalScope calls in this codebase. Applying the trait filters nothing
 * on its own; every read must chain ->forTenant($tenantId).
 */
class Report extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReportFactory
    {
        return \Sire\Database\Factories\ReportFactory::new();
    }
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    protected $table = 'sire_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at'             => 'datetime',
        'acknowledged_at'         => 'datetime',
        'triaged_at'              => 'datetime',
        'assigned_at'             => 'datetime',
        'assignment_accepted_at'  => 'datetime',
        'development_started_at'  => 'datetime',
        'ready_for_qa_at'         => 'datetime',
        'qa_started_at'           => 'datetime',
        'released_at'             => 'datetime',
        'production_validated_at' => 'datetime',
        'closed_at'               => 'datetime',
        'reopened_at'             => 'datetime',
        'held_at'                 => 'datetime',
        'sla_started_at'          => 'datetime',
        'sla_paused_since'        => 'datetime',
        'sla_paused_minutes'      => 'integer',
        'reopen_count'            => 'integer',
        'is_regression'           => 'boolean',
        'include_in_release_notes' => 'boolean',
        'affected_versions'       => 'array',
        'details'                 => 'array',
    ];

    /**
     * An attachment's stored path is an internal detail; a download route serves
     * the bytes after checking ownership. Never expose either.
     */
    protected $hidden = ['path', 'disk'];

    // ---------------------------------------------------------------- scopes

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', SireStatus::TERMINAL);
    }

    public function scopeTrack($query, string $track)
    {
        return $query->where('workflow_track', $track);
    }

    public function isChangeRequest(): bool
    {
        return $this->workflow_track === SireTrack::CHANGE;
    }

    public function isTerminal(): bool
    {
        return SireStatus::isTerminal((string) $this->status);
    }

    /**
     * What this issue contributes to a release: bug, change, improvement,
     * security or performance.
     *
     * Resolution order, most specific first:
     *   1. this row's release_class            a deliberate per-issue override
     *   2. its category's release_class        the tenant's mapping for that type
     *   3. derived from workflow_track         change → change, otherwise bug
     *
     * Falling through to `bug` rather than to null matters: an unclassified issue
     * must still appear in release notes. Silently dropping it is how a shipped
     * fix goes unannounced.
     */
    public function releaseClass(): string
    {
        if (SireReleaseClass::isValid($this->release_class)) {
            return $this->release_class;
        }
        if (SireReleaseClass::isValid($this->category?->release_class)) {
            return $this->category->release_class;
        }

        return $this->workflow_track === SireTrack::CHANGE
            ? SireReleaseClass::CHANGE
            : SireReleaseClass::BUG;
    }

    // --------------------------------------------------------------- people

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'assignee_id');
    }

    public function qaAssignee(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'qa_assignee_id');
    }

    // ------------------------------------------------------------ classification

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class, 'category_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(ReportSeverity::class, 'severity_id');
    }

    // ---------------------------------------------------------------- phase 2

    public function rootCause(): HasOne
    {
        return $this->hasOne(RootCause::class, 'report_id');
    }

    public function recurrenceGroup(): BelongsTo
    {
        return $this->belongsTo(RecurrenceGroup::class, 'recurrence_group_id');
    }

    /** The issue this one duplicates. 1:1, on the row — see sire_report_links. */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** Issues marked as duplicates of THIS one. Never deleted, always countable. */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    public function regressionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regression_of_id');
    }

    public function detectedVersion(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'detected_version_id');
    }

    public function fixedVersion(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'fixed_version_id');
    }

    public function releasedVersion(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'released_version_id');
    }

    public function causedByRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'caused_by_release_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ReportLink::class, 'from_report_id');
    }

    public function inboundLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class, 'to_report_id');
    }

    public function kbLinks(): HasMany
    {
        return $this->hasMany(KbLink::class, 'report_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'report_id');
    }

    public function workCycles(): HasMany
    {
        return $this->hasMany(WorkCycle::class, 'report_id');
    }

    public function context(): HasOne
    {
        return $this->hasOne(ReportContext::class, 'report_id');
    }

    // ------------------------------------------------------- shared engines
    //
    // Attachments, comments and audit entries are NOT relations on this model.
    //
    // They belong to CRM subsystems that SIRE reaches through adapters --
    // SireAttachmentProvider, SireNotesProvider, SireAuditProvider -- precisely so
    // that SIRE does not depend on their table names, their column names, or
    // even on their being Eloquent models at all. A morphMany here would put
    // that assumption back and hide it inside a model.
    //
    // Read them through the adapters, or through SireTimelineService, which
    // merges audit entries and comments into the one ordered view the UI shows.
}
