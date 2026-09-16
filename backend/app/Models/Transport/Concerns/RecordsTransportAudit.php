<?php

namespace App\Models\Transport\Concerns;

use App\Models\Transport\TransportAuditLog;
use App\Models\User;
use App\Services\Transport\TransportAuditLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Plugs a Transport model into the audit trail (SNG-TRN-027).
 *
 * Gives a model its `auditTrail()` relation and a short `audit()` helper, so a
 * service writes one readable line instead of assembling a log payload by hand:
 *
 *     $order->audit('transport.order.created', $actor, new: $order->toArray());
 *
 * Deliberately does NOT hook model events to log automatically. Two reasons:
 *
 *   An automatic created/updated hook cannot know WHY something changed, and
 *   "updated" with a column diff is far weaker evidence than "order approved by
 *   X because Y". Step 9 P-008 asks for changes that are reviewable, not merely
 *   recorded.
 *
 *   It would also log during seeding, factories and test fixtures, filling the
 *   trail with rows nobody performed.
 *
 * So auditing stays an explicit act in the service layer, which is also where
 * the actor and the reason are actually known.
 */
trait RecordsTransportAudit
{
    public function auditTrail(): MorphMany
    {
        return $this->morphMany(TransportAuditLog::class, 'auditable')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /**
     * Record one entry against this record.
     *
     * tenant_id comes from the model itself rather than auth(), so the entry is
     * scoped correctly even when written from a console command or queued job
     * where there is no authenticated user.
     *
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     * @param  array<string,mixed>       $context
     */
    public function audit(
        string $action,
        ?User $actor = null,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
    ): TransportAuditLog {
        return app(TransportAuditLogger::class)->record(
            action: $action,
            tenantId: (int) $this->tenant_id,
            subject: $this,
            actor: $actor,
            old: $old,
            new: $new,
            context: $context,
        );
    }

    /** Record a state transition against this record. */
    public function auditTransition(
        string $action,
        string $from,
        string $to,
        ?User $actor = null,
        array $context = [],
    ): TransportAuditLog {
        return app(TransportAuditLogger::class)->recordTransition(
            action: $action,
            tenantId: (int) $this->tenant_id,
            subject: $this,
            from: $from,
            to: $to,
            actor: $actor,
            context: $context,
        );
    }
}
