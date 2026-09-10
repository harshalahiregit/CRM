<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Sire\Support\Ai\AiCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE AI — one suggestion, and what a human did about it.
 *
 * The ONLY table the AI layer writes to. A suggestion sits beside the record it
 * is about and never inside it: the original issue data is untouched, and every
 * value on a report was put there by a person.
 *
 * Nothing here is generated today. The only registered provider declines
 * everything; this table exists so that when one does not, the shape of the
 * record is already settled.
 */
class AiSuggestion extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;

    /** Awaiting a human. The only status a suggestion is created in. */
    public const PENDING = 'pending';

    public const ACCEPTED   = 'accepted';
    public const REJECTED   = 'rejected';
    /** Taken as a starting point and changed — the most useful feedback signal. */
    public const MODIFIED   = 'modified';
    /**
     * "Not sure" — seen, and deliberately left undecided.
     *
     * Distinct from rejected on purpose: rejecting says the suggestion was wrong,
     * deferring says the reader could not tell. Collapsing them would poison the
     * feedback signal with uncertainty recorded as disagreement.
     */
    public const DEFERRED = 'deferred';

    /** Replaced by a newer suggestion for the same subject and capability. */
    public const SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::PENDING, self::ACCEPTED, self::REJECTED, self::MODIFIED,
        self::DEFERRED, self::SUPERSEDED,
    ];

    /** Decisions a human can record. Superseded is the system's, not theirs. */
    public const HUMAN_DECISIONS = [self::ACCEPTED, self::REJECTED, self::MODIFIED, self::DEFERRED];

    protected $table = 'sire_ai_suggestions';

    protected $guarded = ['id'];

    protected $casts = [
        'payload'          => 'array',
        'evidence'         => 'array',
        'final_value'      => 'array',
        'redaction_report' => 'array',
        'confidence'       => 'float',
        'decided_at'       => 'datetime',
    ];

    public function decider(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'decided_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'requested_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function capabilityLabel(): string
    {
        return AiCapability::label((string) $this->capability);
    }

    /**
     * Every capability is advisory. Nothing in SIRE gates, blocks or auto-applies
     * on a suggestion — a release is never blocked by something whose reasoning
     * nobody can reconstruct.
     */
    public function isAdvisoryOnly(): bool
    {
        return AiCapability::isAdvisoryOnly((string) $this->capability);
    }

    /**
     * How this appears in a timeline. Deliberately its own kind: a reader must be
     * able to tell an AI proposal from a human decision and from a workflow event
     * without knowing the schema.
     */
    public function timelineKind(): string
    {
        return 'ai_suggestion';
    }
}
