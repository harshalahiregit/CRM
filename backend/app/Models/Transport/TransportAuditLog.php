<?php

namespace App\Models\Transport;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One immutable entry in the Transport audit trail (SNG-TRN-027).
 *
 * Immutability is enforced in code, not left to convention. LOCK-006 forbids
 * deleting or altering historical audit evidence, and an audit table that any
 * service can quietly ->update() is not evidence of anything. Both events throw.
 *
 * Uses BelongsToTenant like every Transport model, which gives forTenant() and
 * auto-stamping. Note the trait only stamps when auth()->check() is true — the
 * logger therefore always passes tenant_id explicitly, so a queued job or
 * console command cannot write an unscoped row.
 *
 * @property int         $tenant_id
 * @property string      $action
 * @property string|null $auditable_type
 * @property int|null    $auditable_id
 */
class TransportAuditLog extends Model
{
    use BelongsToTenant;

    protected $table = 'transport_audit_logs';

    /** No updated_at — the table has no such column, by design. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'auditable_type', 'auditable_id', 'action',
        'actor_id', 'actor_name', 'actor_role',
        'old_values', 'new_values', 'context',
        'ip_address', 'user_agent', 'occurred_at',
    ];

    protected $casts = [
        'old_values'  => 'array',
        'new_values'  => 'array',
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only. Anything that tries to revise or remove evidence fails
        // loudly rather than succeeding quietly.
        static::updating(function () {
            throw new RuntimeException('Transport audit entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Transport audit entries are immutable and cannot be deleted.');
        });
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }
}
