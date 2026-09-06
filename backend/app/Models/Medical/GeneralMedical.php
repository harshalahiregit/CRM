<?php

namespace App\Models\Medical;

use App\Models\Customer\ClientContact;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A medical examination of somebody who is not a vendor worker.
 *
 * Internal employees, a client's people, and site visitors all file here. The
 * vendor registers (tpv_worker_medicals, purchase_worker_medicals) are
 * unchanged and stay where they are — see the migration for why.
 *
 * The validity vocabulary is deliberately identical to those registers: passing
 * AND current AND accepted by the quality team. Sharing the definition is the
 * point; "medically cleared" cannot mean one thing for a contractor and another
 * for an employee standing next to them.
 */
class GeneralMedical extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'general_medicals';

    /** Who an examination can be OF. */
    public const SUBJECT_USER = 'user';

    public const SUBJECT_CLIENT = 'client_contact';

    public const SUBJECT_VISITOR = 'visitor';

    public const SUBJECTS = [self::SUBJECT_USER, self::SUBJECT_CLIENT, self::SUBJECT_VISITOR];

    public const SUBJECT_LABELS = [
        self::SUBJECT_USER    => 'Internal team member',
        self::SUBJECT_CLIENT  => 'Client contact',
        self::SUBJECT_VISITOR => 'Site visitor',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'exam_date'    => 'date',
        'valid_until'  => 'date',
        'qc_at'        => 'datetime',
        'is_reexam'    => 'boolean',
        'health_score' => 'float',
        'investigations'     => 'array',
        // Both vendor registers cast this; this one did not, so a declared
        // condition, surgery or habit was stored as the literal word 'Array'
        // and lost. The three registers record the same examination — they
        // have to read it back the same way too.
        'medical_history'    => 'array',
        'iteration_count'    => 'integer',
        'attempt_no'         => 'integer',
    ];

    protected $appends = ['is_passing', 'is_expired', 'is_cleared', 'qc_label', 'fitness_label', 'subject_label'];

    /* ── Relations ──────────────────────────────────────────────────────── */

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    /**
     * The person examined.
     *
     * Resolved by hand rather than through morphTo, because the three subject
     * types live in tables that have nothing else in common and two of them are
     * owned by other modules — a polymorphic relation would invite eager-loading
     * across module boundaries that the isolation rules do not allow.
     */
    public function subject()
    {
        return match ($this->subject_type) {
            self::SUBJECT_USER    => User::find($this->subject_id),
            self::SUBJECT_CLIENT  => ClientContact::find($this->subject_id),
            self::SUBJECT_VISITOR => MedicalVisitor::find($this->subject_id),
            default               => null,
        };
    }

    /** Every examination of the same person, newest first. */
    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /* ── The verdict ────────────────────────────────────────────────────── */

    /** Fit OR Fit-with-restrictions — the outcomes that allow clearance. */
    public function isPassing(): bool
    {
        return Fitness::isPassing($this->fitness_status);
    }

    /** The certificate has lapsed — its currency window closed. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * Pre-module records carry no verdict; a null reads as approved so nobody
     * is retroactively blocked by a check that did not exist when they passed.
     */
    public function isQcCleared(): bool
    {
        return $this->qc_status === null || MedicalQcStatus::isCleared($this->qc_status);
    }

    /** Passing, current, AND accepted by the quality team. */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired() && $this->isQcCleared();
    }

    public function getIsPassingAttribute(): bool
    {
        return $this->isPassing();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    public function getIsClearedAttribute(): bool
    {
        return $this->isCurrentlyValid();
    }

    public function getQcLabelAttribute(): string
    {
        return MedicalQcStatus::label($this->qc_status);
    }

    public function getFitnessLabelAttribute(): string
    {
        return Fitness::label($this->fitness_status);
    }

    public function getSubjectLabelAttribute(): string
    {
        return self::SUBJECT_LABELS[$this->subject_type] ?? $this->subject_type;
    }

    public function getOriginLabelAttribute(): string
    {
        return MedicalWorkflow::originLabel($this->origin);
    }
}
