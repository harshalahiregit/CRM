<?php

namespace App\Models\Traits;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;

/**
 * Makes a model auditable: gives it an `auditLogs` timeline and a convenient
 * `recordAudit()` helper. Drop `use Auditable;` onto any model (Job Posting,
 * Candidate, Interview, Offer, Onboarding, …) to get a full audit trail with
 * zero extra schema — audit_logs is polymorphic.
 */
trait Auditable
{
    /** Full timeline for this record, newest first. */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('id');
    }

    /**
     * Record an action against this record.
     *
     * @param  string  $action    e.g. "L1 Approved", "Rejected", "Sent Back"
     * @param  User|null  $actor   defaults to the authenticated user
     * @param  string|null  $comment
     * @param  array  $metadata    e.g. ['level' => 'L1', 'from' => 'Draft', 'to' => 'L1_Pending']
     */
    public function recordAudit(string $action, ?User $actor = null, ?string $comment = null, array $metadata = []): AuditLog
    {
        return app(AuditLogService::class)->record($this, $action, $actor, $comment, $metadata);
    }
}
