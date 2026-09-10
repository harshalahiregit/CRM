<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — an emergency release override.
 *
 * A register, not just an audit line. Both exist: the audit trail records that it
 * happened, and this table makes it QUERYABLE — how many overrides last quarter,
 * which gates, who authorised them, on what grounds. Governance that cannot be
 * counted is not governance.
 *
 * No soft deletes and no update path. An override is a statement someone made at
 * a moment in time; editing it afterwards would defeat the point.
 */
class ReleaseOverride extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;

    public const REASON_HOTFIX              = 'hotfix';
    public const REASON_CUSTOMER_COMMITMENT = 'customer_commitment';
    public const REASON_REGULATORY          = 'regulatory';
    public const REASON_OTHER               = 'other';

    public const REASONS = [
        self::REASON_HOTFIX, self::REASON_CUSTOMER_COMMITMENT,
        self::REASON_REGULATORY, self::REASON_OTHER,
    ];

    public const REASON_LABELS = [
        self::REASON_HOTFIX              => 'Production hotfix',
        self::REASON_CUSTOMER_COMMITMENT => 'Customer commitment',
        self::REASON_REGULATORY          => 'Regulatory deadline',
        self::REASON_OTHER               => 'Other',
    ];

    protected $table = 'sire_release_overrides';

    protected $guarded = ['id'];

    protected $casts = [
        'overridden_gates' => 'array',
        'gate_snapshot'    => 'array',
        'authorized_at'    => 'datetime',
        'revoked_at'       => 'datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'authorized_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
