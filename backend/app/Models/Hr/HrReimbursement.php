<?php

namespace App\Models\Hr;

use App\Models\Shared\Attachment;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An expense claim.
 *
 * The conversation lives on hr_request_messages and the receipts on the shared
 * attachments table, so this row carries only the claim itself and where it has
 * got to.
 */
class HrReimbursement extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'hr_reimbursements';

    protected $fillable = [
        'tenant_id', 'employee_id',
        'title', 'description', 'category', 'expense_date',
        'amount_claimed', 'amount_approved',
        'status', 'held_from', 'proposed_amount',
        'decided_by', 'decided_at',
    ];

    protected $casts = [
        'expense_date'    => 'date',
        'amount_claimed'  => 'decimal:2',
        'amount_approved' => 'decimal:2',
        'proposed_amount' => 'decimal:2',
        'decided_at'      => 'datetime',
    ];

    protected $appends = ['can_accept_proposal'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Receipts, on the shared store rather than a column here. */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function messages()
    {
        return $this->morphMany(HrRequestMessage::class, 'subject');
    }

    public function isOnHold(): bool
    {
        return $this->status === ReimbursementStatus::ON_HOLD;
    }

    public function isDecided(): bool
    {
        return ReimbursementStatus::isTerminal($this->status);
    }

    /**
     * Whether the employee has an amount to accept.
     *
     * Only true while held AND a figure was actually proposed. Offering Accept on
     * every hold would invite somebody to accept a question — most holds ask for
     * a document and leave proposed_amount null.
     */
    public function getCanAcceptProposalAttribute(): bool
    {
        return $this->isOnHold() && $this->proposed_amount !== null;
    }

    /** What is actually payable: the approved figure, else nothing yet. */
    public function payableAmount(): ?float
    {
        return $this->amount_approved !== null ? (float) $this->amount_approved : null;
    }
}
