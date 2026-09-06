<?php

namespace App\Models\Hr;

use App\Models\Shared\Attachment;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What an advance was actually spent on.
 *
 * A rejected settlement is NOT deleted when another is submitted. SangoeTrack
 * removes the old row — "Delete old rejected settlement if re-submitting" — so
 * the only record of what was first claimed disappears at exactly the moment
 * somebody would want to compare the two. Every attempt is kept here, and the
 * latest one is the one that counts.
 *
 * Bills are attachments on this row, so a re-submission carries its own
 * documents rather than overwriting the first set.
 */
class HrAdvanceSettlement extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_advance_settlements';

    public const PENDING  = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const ON_HOLD  = 'on_hold';

    public const ALL = [self::PENDING, self::ACCEPTED, self::REJECTED, self::ON_HOLD];

    protected $fillable = [
        'tenant_id', 'advance_id',
        'actual_expense', 'balance_return', 'extra_due', 'notes',
        'status', 'review_remarks', 'reviewed_by', 'reviewed_at', 'submitted_by',
    ];

    protected $casts = [
        'actual_expense' => 'decimal:2',
        'balance_return' => 'decimal:2',
        'extra_due'      => 'decimal:2',
        'reviewed_at'    => 'datetime',
    ];

    protected $appends = ['case_label'];

    public function advance()
    {
        return $this->belongsTo(HrAdvance::class, 'advance_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The bills. */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function messages()
    {
        return $this->morphMany(HrRequestMessage::class, 'subject');
    }

    /**
     * Which of the three cases this is, worded once on the server.
     *
     * The CRM's SangoeTrack screen notes that THEY send the sentence so the
     * arithmetic is not re-derived — differently — on the client. That was the
     * right call, so it is kept.
     */
    public function getCaseLabelAttribute(): string
    {
        if ((float) $this->extra_due > 0) {
            return 'Spent more than advanced — the company owes the difference';
        }

        if ((float) $this->balance_return > 0) {
            return 'Spent less than advanced — the balance comes back';
        }

        return 'Spent exactly what was advanced';
    }
}
