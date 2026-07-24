<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A time-boxed grant of approval authority from one user to another.
 */
class ApprovalDelegation extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'approval_delegations';

    protected $fillable = [
        'tenant_id', 'delegator_id', 'delegate_id', 'reason', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function delegator()
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate()
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** Delegations active right now for a given delegate. */
    public function scopeActiveFor($query, int $delegateId)
    {
        return $query->where('delegate_id', $delegateId)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }
}
