<?php

namespace App\Models\Purchase;

use App\Casts\BusinessDateTime;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\NormalisesBusinessTimes;
use App\Models\User;
use App\Support\Purchase\PurchaseKickoffStatus as Status;
use App\Support\Purchase\PurchaseMeetingTypeCatalog;
use App\Support\Purchase\PurchaseMomApprovalStatus as MomStatus;
use App\Support\Shared\MeetingTiming;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase-vendor meeting. Purchase-owned (purchase_kickoff_meetings) —
 * completely independent of the shared/TPV kickoff engine. Not polymorphic: it
 * always belongs to a Purchase vendor (purchase_vendor_id → purchase_vendors).
 *
 * "Kickoff" is now ONE configurable meeting_type among many (Sangoe TPV §9 / §39),
 * not a separate concept — the table name is legacy.
 */
class PurchaseKickoffMeeting extends Model
{
    use Auditable, BelongsToTenant, GeneratesSequentialCode, NormalisesBusinessTimes, SoftDeletes;

    protected $table = 'purchase_kickoff_meetings';

    protected $fillable = [
        'tenant_id', 'created_by', 'purchase_vendor_id', 'purchase_onboarding_id',
        'reference', 'meeting_no', 'title', 'meeting_type', 'agenda', 'status',
        'priority', 'confidentiality', 'chairperson', 'coordinator', 'organizer', 'department', 'client_name',
        'scheduled_at', 'end_at', 'duration_minutes', 'reminders_sent', 'mode', 'location',
        // When the call itself ran, written by MeetingPresence as people arrive
        // and leave. The slot above is the plan; this is the record.
        'actual_start_at', 'actual_end_at', 'presence_seen_at',
        'original_scheduled_at', 'delay_reason',
        'minutes', 'completed_at',
        'mom_status',
        'mom_submitted_at', 'mom_submitted_by',
        'mom_organizer_approved_at', 'mom_organizer_approved_by',
        'mom_approved_at', 'mom_approved_by', 'mom_approval_note',
        'mom_distributed_at', 'mom_distributed_by', 'mom_viewed_at',
        'ack_token', 'acknowledged_at', 'acknowledged_by_name', 'acknowledged_ip',
        'meeting_platform', 'meeting_link', 'meeting_id', 'meeting_passcode', 'meeting_host_link',
    ];

    protected $casts = [
        // Person-entered wall clocks in the tenant's timezone, NOT UTC instants.
        // Mirrors the shared engine exactly — see App\Casts\BusinessDateTime.
        'scheduled_at'              => BusinessDateTime::class,
        'end_at'                    => BusinessDateTime::class,
        'original_scheduled_at'     => BusinessDateTime::class,
        // Machine timestamps, NOT wall clocks: nobody types these, they are
        // stamped as the call happens. UTC like every other `now()` on the
        // model — BusinessDateTime is for times a person entered, and using
        // it here would shift them by the tenant's offset.
        'actual_start_at'           => 'datetime',
        'actual_end_at'             => 'datetime',
        'presence_seen_at'          => 'datetime',
        'completed_at'              => 'datetime',
        'acknowledged_at'          => 'datetime',
        'mom_submitted_at'          => 'datetime',
        'mom_organizer_approved_at' => 'datetime',
        'mom_approved_at'           => 'datetime',
        'mom_distributed_at'        => 'datetime',
        'mom_viewed_at'             => 'datetime',
        'duration_minutes'          => 'integer',
        'reminders_sent'            => 'array',
    ];

    /** The ack link is a bearer credential — never leak it in list/show payloads. */
    protected $hidden = ['ack_token'];

    protected $appends = [
        'status_label', 'is_acknowledged', 'meeting_type_label', 'mom_status_label',
        // Clock-derived; see the Timing block below. Mirrors the shared engine.
        'ends_at', 'timing_state', 'timing_label', 'is_expired', 'is_live', 'minutes_until_start',
        // The record of the call itself, not the slot it was booked into.
        'has_ended', 'held_minutes',
        // Whether a minutes DOCUMENT exists — asked the same way of both
        // engines, which store it in entirely different places.
        'has_mom_document',
    ];

    /** Auto-assign a Meeting-No (MTG-YYYY-NNNN) per tenant/year on create (§2). */
    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->meeting_no)) {
                // Highest issued + 1, not count + 1 — see GeneratesSequentialCode.
                // meeting_no has no unique index, so the old count-based version
                // did not fail on a collision, it wrote a DUPLICATE: delete one
                // meeting and the next reused its number. That number is what the
                // minutes print and what a vendor quotes back at you.
                $m->meeting_no = static::nextSequentialCode(
                    'meeting_no', 'MTG-'.now()->year.'-', (int) $m->tenant_id, 4,
                );
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function momSubmitter()
    {
        return $this->belongsTo(User::class, 'mom_submitted_by');
    }

    public function momOrganizerApprover()
    {
        return $this->belongsTo(User::class, 'mom_organizer_approved_by');
    }

    public function momApprover()
    {
        return $this->belongsTo(User::class, 'mom_approved_by');
    }

    public function momDistributor()
    {
        return $this->belongsTo(User::class, 'mom_distributed_by');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function onboarding()
    {
        return $this->belongsTo(PurchaseOnboarding::class, 'purchase_onboarding_id');
    }

    public function participants()
    {
        return $this->hasMany(PurchaseKickoffParticipant::class, 'purchase_kickoff_meeting_id');
    }

    public function momDocuments()
    {
        return $this->hasMany(PurchaseKickoffMom::class, 'purchase_kickoff_meeting_id');
    }

    /** Labelled meeting-level supporting documents (not per-action evidence). */
    public function documents()
    {
        return $this->hasMany(PurchaseKickoffDocument::class, 'purchase_kickoff_meeting_id')
            ->whereNull('purchase_mom_action_item_id')
            ->orderByDesc('id');
    }

    /** Structured agenda items (Meeting.docx §3 agenda builder). */
    public function agendaItems()
    {
        return $this->hasMany(PurchaseMomAgendaItem::class, 'purchase_kickoff_meeting_id');
    }

    /** Action items raised in this meeting's minutes (Sangoe TPV §9 action engine). */
    public function actionItems()
    {
        return $this->hasMany(PurchaseMomActionItem::class, 'purchase_kickoff_meeting_id');
    }

    /** Issues raised in this meeting's minutes (Sangoe TPV §9 issue register). */
    public function momIssues()
    {
        return $this->hasMany(PurchaseMomIssue::class, 'purchase_kickoff_meeting_id');
    }

    /** Decisions recorded in this meeting's minutes (Sangoe TPV §9 decision register). */
    public function momDecisions()
    {
        return $this->hasMany(PurchaseMomDecision::class, 'purchase_kickoff_meeting_id');
    }

    /** The current MOM PDF, if any. */
    public function currentMom()
    {
        return $this->hasOne(PurchaseKickoffMom::class, 'purchase_kickoff_meeting_id')->where('is_current', true);
    }

    /**
     * Is there a minutes document to open?
     *
     * The one admin screen drives both engines, and it asked `mom_path` — a
     * column this engine does not have; the document lives in purchase_kickoff_mom.
     * So View and Download never appeared and the button always said "Generate
     * PDF": people pressed it again and again, which is why some meetings carry
     * three generated copies of the same minutes. One question, answered the
     * same way by both engines.
     */
    public function getHasMomDocumentAttribute(): bool
    {
        return $this->relationLoaded('currentMom')
            ? (bool) $this->getRelation('currentMom')
            : $this->currentMom()->exists();
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getMeetingTypeLabelAttribute(): string
    {
        return PurchaseMeetingTypeCatalog::label($this->meeting_type);
    }

    public function getMomStatusLabelAttribute(): string
    {
        return MomStatus::label($this->mom_status);
    }

    public function getIsAcknowledgedAttribute(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', Status::OPEN);
    }

    /* ── Timing ───────────────────────────────────────────────────────────
     *
     * Where the meeting sits against the clock, as opposed to what people
     * decided about it (that is `status`). Derived on every read, so a meeting
     * becomes Expired the moment its end passes without a job having to run.
     * Appended, so every payload that carries a meeting carries this too —
     * the list, the detail, the portal and both dashboards read the same
     * answer instead of each re-deriving it from scheduled_at.
     */

    /** When the meeting actually ends: the stored end, else start + duration. */
    public function getEndsAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return MeetingTiming::endsAt(
            $this->scheduled_at, $this->end_at, $this->duration_minutes, $this->tenant_id,
        );
    }

    /** draft | upcoming | live | ended | expired | closed */
    public function getTimingStateAttribute(): string
    {
        return MeetingTiming::state(
            $this->scheduled_at,
            $this->end_at,
            $this->duration_minutes,
            $this->status === Status::DRAFT,
            Status::isClosed($this->status),
            $this->tenant_id,
            // What the call actually did, which outranks what was booked: a
            // meeting everyone left after seven minutes is over, however much
            // of its hour is left.
            $this->actual_start_at,
            $this->actual_end_at,
            // The last heartbeat. A call whose browser was closed without
            // hanging up reports no end at all, and this is what stops the
            // meeting reading "In progress" for ever afterwards.
            $this->presence_seen_at,
        );
    }

    public function getTimingLabelAttribute(): string
    {
        return MeetingTiming::label($this->timing_state);
    }

    /**
     * Ended while still open — nobody completed or cancelled it.
     *
     * This is what every "this meeting has expired" message keys off, and what
     * withholds the join link: a link to a meeting that finished yesterday is
     * worse than no link, because it looks like it should work.
     */
    public function getIsExpiredAttribute(): bool
    {
        // Both finished states: a call that was held and ended cannot be joined
        // either, and every consumer of this asks the same question.
        return in_array($this->timing_state, MeetingTiming::FINISHED, true);
    }

    /** The call was held and has finished — as opposed to never having happened. */
    public function getHasEndedAttribute(): bool
    {
        return $this->timing_state === MeetingTiming::ENDED;
    }

    /**
     * How long the meeting actually ran, in minutes. Null until it has ended.
     *
     * The booked length is `duration_minutes`; this is what really happened,
     * and the two are shown side by side so a meeting that took twenty minutes
     * of its booked hour reads as exactly that.
     */
    public function getHeldMinutesAttribute(): ?int
    {
        return MeetingTiming::heldMinutes($this->actual_start_at, $this->actual_end_at ?: $this->presence_seen_at);
    }

    /** Running right now — started, not yet ended, not closed. */
    public function getIsLiveAttribute(): bool
    {
        return $this->timing_state === MeetingTiming::LIVE;
    }

    /** Negative once the meeting has begun; null when it has no date. */
    public function getMinutesUntilStartAttribute(): ?int
    {
        return MeetingTiming::minutesUntilStart($this->scheduled_at, $this->tenant_id);
    }

}
