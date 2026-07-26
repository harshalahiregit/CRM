<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * In-app notifications. Modules call notify() to drop a message into a user's
 * bell; delivery is best-effort and swallow-logged so a notification failure can
 * never break the business write that triggered it (same rule as helpdesk mail).
 */
class NotificationService
{
    /** Send to one user. Returns null when skipped (no user, or self-notify). */
    public function notify(
        ?int $userId,
        int $tenantId,
        string $type,
        string $title,
        ?string $message = null,
        ?string $link = null,
        ?int $actorId = null,
    ): ?Notification {
        // Nobody to tell, or the person who caused it is the recipient — a user
        // doesn't need to be told about their own action.
        if (! $userId || ($actorId !== null && $actorId === $userId)) {
            return null;
        }

        try {
            return Notification::create([
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'message'   => $message ? mb_substr($message, 0, 500) : null,
                'link'      => $link,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Notification failed ({$type} → user {$userId}): {$e->getMessage()}");

            return null;
        }
    }

    public function listFor(int $userId, int $tenantId, int $limit = 30): array
    {
        return Notification::forTenant($tenantId)->forUser($userId)
            ->orderByRaw('read_at IS NULL DESC')   // unread first
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function unreadCount(int $userId, int $tenantId): int
    {
        return Notification::forTenant($tenantId)->forUser($userId)->unread()->count();
    }

    public function markRead(int $id, int $userId, int $tenantId): void
    {
        Notification::forTenant($tenantId)->forUser($userId)->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllRead(int $userId, int $tenantId): int
    {
        return Notification::forTenant($tenantId)->forUser($userId)->unread()->update(['read_at' => now()]);
    }
}
