<?php

namespace App\Models\Shared;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Shared\KickoffStatus as Status;
use App\Support\Shared\KickoffSubject;
use App\Support\Shared\MeetingTypeCatalog;
use App\Support\Shared\MomApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pre-onboarding (or pre-project) kickoff meeting.
 *
 * Shared, not TPV-owned: it attaches polymorphically to any allowlisted subject
 * so Shivam's Project&Task module can plug in without a second table.
 */
class KickoffMeeting extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'kickoff_meetings';

    protected $fillable = [
        'tenant_id', 'created_by', 'kickoffable_type', 'kickoffable_id',
        // Kickoff is now one configurable meeting type among many (config/meetings.php).
        'meeting_type',
        'reference', 'meeting_no', 'title', 'agenda', 'status',
        // Meeting.docx §2 detail fields.
        'end_at', 'priority', 'confidentiality', 'chairperson', 'coordinator',
        'department', 'client_name', 'project_id', 'work_package',
        'scheduled_at', 'duration_minutes', 'mode', 'location',
        'original_scheduled_at', 'delay_reason',
        'mom_path', 'minutes', 'completed_at',
        // MOM approval workflow (Meeting.docx). Distribution = the vendor send.
        'mom_status', 'mom_submitted_at', 'mom_submitted_by', 'mom_approved_at',
        'mom_approved_by', 'mom_approval_note', 'mom_distributed_at', 'mom_distributed_by',
        // Two-level approval + distribution tracking (Meeting.docx §12/§13).
        'mom_organizer_approved_at', 'mom_organizer_approved_by', 'mom_viewed_at',
        'acknowledgement_response_type',
        // Structured venue. `location` above stays the single displayable string
        // every existing consumer reads; these are the parts it is built from.
        'city', 'venue', 'address',
        // The date originally promised, independent of any Delayed transition.
        'planned_date',
        'ack_token', 'acknowledged_at', 'acknowledged_by_name', 'acknowledged_ip',
        // 48-hour acknowledgement window (see KickoffMeetingService::publishForAck).
        'acknowledgement_sent_at', 'acknowledgement_deadline', 'acknowledgement_status',
        // The vendor's free-text response captured at acknowledgement.
        'acknowledgement_comment',
        // Online meeting fields (nullable — only set when mode = 'online')
        'meeting_platform', 'meeting_link', 'meeting_id', 'meeting_passcode', 'meeting_host_link',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'end_at' => 'datetime',
        'original_scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'duration_minutes' => 'integer',
        'planned_date' => 'date',
        'acknowledgement_sent_at' => 'datetime',
        'acknowledgement_deadline' => 'datetime',
        'mom_submitted_at' => 'datetime',
        'mom_approved_at' => 'datetime',
        'mom_distributed_at' => 'datetime',
        'mom_organizer_approved_at' => 'datetime',
        'mom_viewed_at' => 'datetime',
    ];

    /** Acknowledgement window states. NULL = never sent for acknowledgement. */
    public const ACK_PENDING = 'pending';

    public const ACK_ACKNOWLEDGED = 'acknowledged';

    public const ACK_EXPIRED = 'expired';

    /** How long a vendor has to acknowledge published minutes. */
    public const ACK_WINDOW_HOURS = 48;

    /**
     * The ack link is a bearer credential — possession lets a vendor acknowledge.
     * Never let it ride along in a list/show payload; it is disclosed only in the
     * audited publish-minutes response.
     */
    protected $hidden = ['ack_token'];

    /** Auto-assign a human Meeting No (MTG-YYYY-NNNN) per tenant (Meeting.docx §2). */
    protected static function booted(): void
    {
        static::creating(function (KickoffMeeting $m) {
            if (empty($m->meeting_no)) {
                $year = date('Y');
                $n = static::withTrashed()
                    ->where('tenant_id', $m->tenant_id)
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $m->meeting_no = sprintf('MTG-%s-%04d', $year, $n);
            }
        });
    }

    protected $appends = [
        // 'subject' stays — it is the primary vendor and existing callers read it.
        // 'subject_list' is the full set, added alongside rather than instead.
        'status_label', 'is_acknowledged', 'subject', 'subject_list',
        'acknowledgement_open', 'acknowledgement_expired', 'can_complete',
        'meeting_type_label', 'mom_status_label',
    ];

    /** Human label for the MOM approval state. Defaults to Draft. */
    public function getMomStatusLabelAttribute(): string
    {
        return MomApprovalStatus::label($this->mom_status);
    }

    public function momSubmitter()
    {
        return $this->belongsTo(User::class, 'mom_submitted_by');
    }

    public function momApprover()
    {
        return $this->belongsTo(User::class, 'mom_approved_by');
    }

    public function momDistributor()
    {
        return $this->belongsTo(User::class, 'mom_distributed_by');
    }

    public function momOrganizerApprover()
    {
        return $this->belongsTo(User::class, 'mom_organizer_approved_by');
    }

    /**
     * Human label for the stored meeting_type key. Resolved through the tenant's
     * catalogue (config baseline + any admin-defined types), request-cached so
     * reading it on every list row does not re-query. Falls back to the raw key.
     */
    public function getMeetingTypeLabelAttribute(): string
    {
        return app(MeetingTypeCatalog::class)
            ->label((int) $this->tenant_id, $this->meeting_type);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Vendor / onboarding today; project next. */
    public function kickoffable()
    {
        return $this->morphTo();
    }

    /**
     * Every vendor on this meeting, primary first.
     *
     * `kickoffable` above still points at the primary and is what the vendor
     * portal, the onboarding pointer and scopeFor() query — this is the full set
     * on top of it, not a replacement.
     */
    public function subjects()
    {
        return $this->hasMany(KickoffMeetingSubject::class, 'kickoff_meeting_id')
            ->orderByDesc('is_primary')->orderBy('id');
    }

    public function attendees()
    {
        return $this->hasMany(KickoffAttendee::class, 'kickoff_meeting_id');
    }

    /** Itemised minutes, in the order they were captured. */
    public function momItems()
    {
        return $this->hasMany(KickoffMomItem::class, 'kickoff_meeting_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** Structured agenda (topic · owner · duration · priority), in agenda order. */
    public function agendaItems()
    {
        return $this->hasMany(MeetingAgendaItem::class, 'kickoff_meeting_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** Decision register (Meeting.docx §9). */
    public function decisions()
    {
        return $this->hasMany(MeetingDecision::class, 'kickoff_meeting_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** Issues raised (Meeting.docx §10). */
    public function issues()
    {
        return $this->hasMany(MeetingIssue::class, 'kickoff_meeting_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether the acknowledgement window is still open.
     *
     * A meeting published before this window existed has no deadline; those stay
     * open indefinitely rather than being retroactively expired, because nobody
     * told that vendor they were on a clock.
     */
    public function getAcknowledgementOpenAttribute(): bool
    {
        if ($this->acknowledged_at !== null) {
            return false;
        }
        if ($this->acknowledgement_sent_at === null) {
            return false;
        }

        return $this->acknowledgement_deadline === null
            || $this->acknowledgement_deadline->isFuture();
    }

    /** True once the window has shut without an acknowledgement. */
    public function getAcknowledgementExpiredAttribute(): bool
    {
        return $this->acknowledged_at === null
            && $this->acknowledgement_deadline !== null
            && $this->acknowledgement_deadline->isPast();
    }

    /**
     * Whether the meeting may be marked Completed yet.
     *
     * The toggle must not be available before the meeting has happened —
     * completing a kickoff that is still in the future is a data-entry mistake,
     * not a workflow. A meeting with no scheduled_at has nothing to wait for.
     */
    public function getCanCompleteAttribute(): bool
    {
        return $this->scheduled_at === null || $this->scheduled_at->isPast();
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getIsAcknowledgedAttribute(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Structured subject so a listing shows the vendor name, not a row id. */
    /**
     * Every vendor on the meeting, for display. Primary first.
     *
     * `subject` (singular, below) still returns the primary and is untouched, so
     * anything already reading it keeps working; this is the full list beside it.
     */
    public function getSubjectListAttribute(): array
    {
        if (! $this->relationLoaded('subjects')) {
            return $this->subject ? [$this->subject + ['is_primary' => true]] : [];
        }

        return $this->subjects->map->toDisplayArray()->values()->all();
    }

    public function getSubjectAttribute(): ?array
    {
        if (! $this->kickoffable_type) {
            return null;
        }
        $key = KickoffSubject::keyFor($this->kickoffable_type);

        return [
            'type' => $key,
            'id' => $this->kickoffable_id,
            'name' => KickoffSubject::nameOf($this->kickoffable),
            'label' => KickoffSubject::label($key),
        ];
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', Status::OPEN);
    }

    public function scopeFor($query, string $type, int $id)
    {
        return $query->where('kickoffable_type', $type)->where('kickoffable_id', $id);
    }
}
