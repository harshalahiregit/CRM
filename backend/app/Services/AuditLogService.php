<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Central writer/reader for the polymorphic audit trail.
 *
 * One place decides how actor identity/role is snapshotted and how tenant_id
 * is derived, so every auditable entity (Manpower Request today; Job Posting,
 * Candidates, Interviews, Offers, Onboarding tomorrow) logs consistently.
 */
class AuditLogService
{
    /**
     * Record an action against an auditable model.
     */
    public function record(Model $auditable, string $action, ?User $actor = null, ?string $comment = null, array $metadata = [], ?string $actorLabel = null): AuditLog
    {
        $actor = $actor ?: (auth()->check() ? auth()->user() : null);

        // Tenant is taken from the record itself (row-level isolation), falling
        // back to the actor's tenant — never crosses tenant boundaries.
        $tenantId = $auditable->tenant_id ?? $actor?->tenant_id;

        return AuditLog::create([
            'tenant_id'      => $tenantId,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id'   => $auditable->getKey(),
            'action'         => $action,
            'actor_id'       => $actor?->id,
            // Unauthenticated actors (e.g. the public candidate portal) carry a label
            // so the trail is never anonymous.
            'actor_name'     => $actor?->name ?? $actorLabel,
            'actor_role'     => $actor ? ($actor->internal_role ?: $actor->role) : ($actorLabel ? 'portal' : null),
            'comment'        => $comment ?: null,
            'metadata'       => $metadata ? array_filter($metadata, fn ($v) => $v !== null && $v !== '') : null,
        ]);
    }

    /** Full, actor-loaded timeline for a record (newest first). */
    public function timeline(Model $auditable): Collection
    {
        return $auditable->auditLogs()->with('actor')->get();
    }
}
