<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The one way anything in Transport writes audit evidence (SNG-TRN-027).
 *
 * Deliberately the single entry point, for the reason EnsureCanManageHrQueue's
 * docblock records about scattered checks: a mechanism called from many places
 * with slightly different shapes stops being a mechanism. Every Transport
 * service calls record() and nothing constructs TransportAuditLog directly.
 *
 * $tenantId is always an explicit argument. BelongsToTenant only auto-stamps
 * when auth()->check() is true, so relying on it would silently write a
 * null-tenant row from a queued job or console command — exactly the kind of
 * row that makes an audit trail untrustworthy.
 */
class TransportAuditLogger
{
    /**
     * Record one audit entry.
     *
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     * @param  array<string,mixed>       $context
     */
    public function record(
        string $action,
        int $tenantId,
        ?Model $subject = null,
        ?User $actor = null,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
    ): TransportAuditLog {
        $entry = TransportAuditLog::create([
            'tenant_id'      => $tenantId,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id'   => $subject?->getKey(),
            'action'         => $action,
            'actor_id'       => $actor?->id,
            // Snapshotted, not joined: the user may be renamed or deactivated
            // later and the evidence must still name who acted.
            'actor_name'     => $actor?->name,
            'actor_role'     => $actor?->role,
            'old_values'     => $old,
            'new_values'     => $new,
            'context'        => $context ?: null,
            'ip_address'     => $this->requestValue(fn () => request()->ip()),
            'user_agent'     => $this->truncate($this->requestValue(fn () => request()->userAgent()), 255),
            'occurred_at'    => now(),
        ]);

        Log::channel('transport')->info('Transport audit', [
            'action'    => $action,
            'tenant_id' => $tenantId,
            'subject'   => $subject ? $subject::class.'#'.$subject->getKey() : null,
            'actor_id'  => $actor?->id,
            'audit_id'  => $entry->id,
        ]);

        return $entry;
    }

    /**
     * Record a state transition. Its own method because a status change is the
     * single most-read kind of transport evidence — "who moved this trip, when,
     * and from what" — and giving it one shape stops each service inventing its
     * own payload for the same question.
     */
    public function recordTransition(
        string $action,
        int $tenantId,
        Model $subject,
        string $from,
        string $to,
        ?User $actor = null,
        array $context = [],
    ): TransportAuditLog {
        return $this->record(
            action: $action,
            tenantId: $tenantId,
            subject: $subject,
            actor: $actor,
            old: ['status' => $from],
            new: ['status' => $to],
            context: $context,
        );
    }

    /**
     * Entries for one record, newest first. Tenant-scoped at the point of the
     * query, never filtered afterwards.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TransportAuditLog>
     */
    public function forSubject(Model $subject, int $tenantId, int $limit = 100)
    {
        return TransportAuditLog::forTenant($tenantId)
            ->forSubject($subject::class, (int) $subject->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** request() does not exist in every context (console, some tests). */
    private function requestValue(callable $get): ?string
    {
        try {
            return $get() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncate(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
