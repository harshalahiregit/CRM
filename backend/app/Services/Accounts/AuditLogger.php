<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AuditLog;

/**
 * Append-only writer for the statutory audit trail (spec §8). Deliberately built
 * by hand rather than via a toggleable activity-log package so it genuinely
 * cannot be disabled. There is no update()/delete() here by design.
 */
class AuditLogger
{
    public function log(
        int $tenantId,
        ?int $userId,
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
    ): void {
        AuditLog::create([
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'before_json' => $before,
            'after_json'  => $after,
            'occurred_at' => now(),
            'ip'          => request()?->ip(),
        ]);
    }
}
