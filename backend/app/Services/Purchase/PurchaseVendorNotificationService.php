<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendorNotification;
use Illuminate\Support\Facades\Log;

/**
 * In-app (bell) notifications for the Purchase vendor portal. The Purchase-side
 * mirror of App\Services\NotificationService: modules call notify() to drop a
 * message into a Purchase vendor's bell. Delivery is best-effort and
 * swallow-logged so a notification failure can never break the business write
 * that triggered it.
 */
class PurchaseVendorNotificationService
{
    /** Drop a bell notification for one Purchase vendor. Null when skipped. */
    public function notify(
        ?int $purchaseVendorId,
        int $tenantId,
        string $type,
        string $title,
        ?string $message = null,
        ?string $link = null,
    ): ?PurchaseVendorNotification {
        if (! $purchaseVendorId) {
            return null;
        }

        try {
            return PurchaseVendorNotification::create([
                'tenant_id'          => $tenantId,
                'purchase_vendor_id' => $purchaseVendorId,
                'type'               => $type,
                'title'              => $title,
                'message'            => $message ? mb_substr($message, 0, 500) : null,
                'link'               => $link,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Purchase vendor notification failed ({$type} → vendor {$purchaseVendorId}): {$e->getMessage()}");

            return null;
        }
    }

    public function listFor(int $purchaseVendorId, int $tenantId, int $limit = 30): array
    {
        return PurchaseVendorNotification::forTenant($tenantId)->forVendor($purchaseVendorId)
            ->orderByRaw('read_at IS NULL DESC')   // unread first
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function unreadCount(int $purchaseVendorId, int $tenantId): int
    {
        return PurchaseVendorNotification::forTenant($tenantId)->forVendor($purchaseVendorId)->unread()->count();
    }

    public function markRead(int $id, int $purchaseVendorId, int $tenantId): void
    {
        PurchaseVendorNotification::forTenant($tenantId)->forVendor($purchaseVendorId)
            ->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllRead(int $purchaseVendorId, int $tenantId): int
    {
        return PurchaseVendorNotification::forTenant($tenantId)->forVendor($purchaseVendorId)
            ->unread()->update(['read_at' => now()]);
    }
}
