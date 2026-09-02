<?php

namespace App\Models\Hr;

use App\Models\Shared\Attachment;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Hr\AdvanceStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An advance: money paid out before it is spent.
 *
 * The row carries the request and where it has reached on the ladder. The
 * conversation lives on hr_request_messages and the supporting documents on the
 * shared attachments table, the same way an expense claim works — so a hold on
 * an advance reads like a hold on a claim, because it is the same machinery.
 */
class HrAdvance extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'hr_advances';

    public const REF_PREFIX = 'ADV-';

    protected $fillable = [
        'tenant_id', 'employee_id', 'reference',
        'advance_type', 'category', 'project_site', 'purpose',
        'amount_requested', 'amount_approved',
        'required_date', 'expected_settlement_date',
        'status', 'held_from', 'proposed_amount',
        'disbursed_amount', 'disbursement_mode', 'disbursement_reference',
        'disbursed_by', 'disbursed_at',
        'decided_by', 'decided_at',
    ];

    protected $casts = [
        'required_date'            => 'date',
        'expected_settlement_date' => 'date',
        'amount_requested'         => 'decimal:2',
        'amount_approved'          => 'decimal:2',
        'proposed_amount'          => 'decimal:2',
        'disbursed_amount'         => 'decimal:2',
        'disbursed_at'             => 'datetime',
        'decided_at'               => 'datetime',
    ];

    protected $appends = ['stage_label', 'next_tier', 'can_accept_proposal'];

    /* ── relations ───────────────────────────────────────────────────── */

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function disbursedBy()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /** Every settlement ever submitted, oldest first — rejected ones included. */
    public function settlements()
    {
        return $this->hasMany(HrAdvanceSettlement::class, 'advance_id')->orderBy('id');
    }

    /** The one that counts right now: the most recent. */
    public function latestSettlement()
    {
        return $this->hasOne(HrAdvanceSettlement::class, 'advance_id')->latestOfMany();
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function messages()
    {
        return $this->morphMany(HrRequestMessage::class, 'subject');
    }

    /* ── state ───────────────────────────────────────────────────────── */

    public function isOnHold(): bool
    {
        return $this->status === AdvanceStage::ON_HOLD;
    }

    public function isDecided(): bool
    {
        return AdvanceStage::isDecided($this->status);
    }

    public function isOpen(): bool
    {
        return AdvanceStage::isOpen($this->status);
    }

    /**
     * The tier whose approval is the next thing that has to happen.
     *
     * Held requests report the tier they were held FROM, so an approver looking
     * at the queue still knows whose desk it is on — a hold pauses the ladder, it
     * does not take the request off it.
     */
    public function getNextTierAttribute(): ?string
    {
        return AdvanceStage::nextTier($this->isOnHold() ? (string) $this->held_from : (string) $this->status);
    }

    public function getStageLabelAttribute(): string
    {
        return AdvanceStage::label((string) $this->status);
    }

    public function getCanAcceptProposalAttribute(): bool
    {
        return $this->isOnHold() && $this->proposed_amount !== null;
    }

    /** What the ladder has agreed to pay, falling back to what was asked. */
    public function effectiveAmount(): float
    {
        return $this->amount_approved !== null
            ? (float) $this->amount_approved
            : (float) $this->amount_requested;
    }

    /**
     * The next reference for a tenant.
     *
     * MAX over the numeric tail rather than a count, so a deleted row cannot
     * cause the next request to reuse a reference. Mirrors Estimate and
     * HrEmployee, which is the pattern the rest of the codebase already uses.
     */
    public static function nextReference(int $tenantId): string
    {
        $len = strlen(self::REF_PREFIX);

        $max = (int) static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', self::REF_PREFIX . '%')
            ->selectRaw('MAX(CAST(SUBSTR(reference, ' . ($len + 1) . ') AS UNSIGNED)) AS n')
            ->value('n');

        return self::REF_PREFIX . ($max + 1);
    }
}
