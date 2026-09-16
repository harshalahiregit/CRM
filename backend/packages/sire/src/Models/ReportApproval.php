<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — one approval request.
 *
 * The purchase/tpv register shape, reproduced so a future consolidation of the
 * CRM's five approval implementations is a merge rather than a rewrite. SIRE
 * builds no sixth engine.
 *
 * Append-only. Cancel, never delete — an approval register that can lose rows is
 * not a register.
 */
class ReportApproval extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;

    public const TYPE_CHANGE_REQUEST      = 'change_request';
    public const TYPE_REPORT_CLOSURE      = 'report_closure';
    public const TYPE_ACTION_VERIFICATION = 'action_verification';

    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const CANCELLED = 'cancelled';

    public const SUBJECT_REPORT = 'sire_report';
    public const SUBJECT_ACTION = 'sire_action';

    protected $table = 'sire_approvals';

    protected $guarded = ['id'];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at'   => 'datetime',
        'due_at'       => 'datetime',
        'escalated_at' => 'datetime',
        'level'        => 'integer',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function isDecided(): bool
    {
        return $this->status !== self::PENDING;
    }
}
