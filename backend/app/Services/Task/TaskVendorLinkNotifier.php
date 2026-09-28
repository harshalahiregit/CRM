<?php

namespace App\Services\Task;

use App\Mail\Task\TaskActivityMail;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Task\Task;
use App\Models\Vendor\Vendor;
use App\Services\Mail\TenantMailer;
use App\Services\NotificationService;
use App\Services\Purchase\PurchaseVendorNotificationService;
use App\Services\Vendor\VendorEmployeeService;
use Illuminate\Support\Facades\Log;

/**
 * Tells a VENDOR that a task has been filed against them.
 *
 * ── The gap this closes ─────────────────────────────────────────────────
 * Only assignment used to notify anybody, and assignment means a row in
 * `task_assignees` keyed to a User. A Purchase vendor is not a User, so the one
 * way to attach a task to one — `rel_type`/`rel_id`, the "Related to (company)"
 * field — reached nobody at all. The task simply appeared on their portal one
 * day, if they happened to look. "I assigned the vendor and they were not
 * notified" was exactly right, and this is the missing half.
 *
 * ── Why a shared class can name both modules ────────────────────────────
 * TPV and Purchase stay separate registers; this does not merge them. What it
 * shares is only the SHAPE of the link — `rel_type` plus an id — which
 * App\Support\Task\VendorTaskLink already reads the same way for both. The
 * delivery below stays module-owned: TPV notifies Users through the shared bell,
 * Purchase notifies a PurchaseVendor through the Purchase-owned
 * purchase_vendor_notifications store. Neither writes into the other's table.
 *
 * ── Delivery is best-effort ─────────────────────────────────────────────
 * Same contract as TaskNotifier: every leg is swallow-logged, so a dead SMTP
 * host or a missing vendor can never roll back the task write that triggered it.
 */
class TaskVendorLinkNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private PurchaseVendorNotificationService $purchaseNotifications,
        private VendorEmployeeService $employees,
        private TaskConfigService $config,
        private TaskTreeService $tree,
        private TenantMailer $mailer,
    ) {
    }

    /**
     * A task was linked to a vendor. Called only when the link actually CHANGED,
     * so saving an unrelated field on the same task does not re-announce it.
     */
    public function linked(Task $task, string $relType, int $vendorId, ?int $actorId = null): void
    {
        // Shares the assignment switch: being handed a task is one idea, and an
        // admin who turned assignment alerts off does not want this either.
        if (! $this->config->on($task->tenant_id, 'notify_assigned')) {
            return;
        }

        try {
            match ($relType) {
                'tpv_vendor'      => $this->notifyTpv($task, $vendorId, $actorId),
                'purchase_vendor' => $this->notifyPurchase($task, $vendorId),
                default           => null,
            };
        } catch (\Throwable $e) {
            Log::warning("Task vendor-link notification failed (task {$task->id} → {$relType} {$vendorId}): {$e->getMessage()}");
        }
    }

    /* ── TPV: a vendor whose people ARE Users ───────────────────────────── */

    private function notifyTpv(Task $task, int $vendorId, ?int $actorId): void
    {
        $userIds = $this->employees->portalUserIds($vendorId, (int) $task->tenant_id);
        if (! $userIds) {
            return;   // a vendor master with no login yet — nothing to ring
        }

        $title = "New task for your company: {$task->name}";
        $body  = $this->summary($task);

        foreach ($userIds as $uid) {
            // The link is the generic /app one; the portal's own portalLink()
            // remaps it to the portal's Tasks page, because a vendor cannot open
            // the /app shell.
            $this->notifications->notify(
                $uid, (int) $task->tenant_id, 'task.vendor_linked',
                $title, $body, "/app/tasks/{$task->id}", $actorId,
            );
        }

        $emails = Vendor::where('tenant_id', $task->tenant_id)->find($vendorId)?->email;
        $this->mail($task, array_filter([$emails]), $title, $body);
    }

    /* ── Purchase: a vendor that is its own login ───────────────────────── */

    private function notifyPurchase(Task $task, int $vendorId): void
    {
        $vendor = PurchaseVendor::where('tenant_id', $task->tenant_id)->find($vendorId);
        if (! $vendor) {
            return;
        }

        $title = "New task for your company: {$task->name}";
        $body  = $this->summary($task);

        // Straight to the portal route: a Purchase vendor's bell is read inside
        // the portal, so there is no /app link to remap.
        $this->purchaseNotifications->notify(
            (int) $vendor->id, (int) $task->tenant_id, 'task.vendor_linked',
            $title, $body, '/purchase-portal/tasks',
        );

        $this->mail($task, array_filter([$vendor->email]), $title, $body);
    }

    /* ── Shared bits ────────────────────────────────────────────────────── */

    /** "High priority · due Mar 4" — the two things that decide what to do next. */
    private function summary(Task $task): string
    {
        $due = $task->due_date ? ' · due '.$task->due_date->format('M j') : '';

        return ucfirst((string) $task->priority).' priority'.$due;
    }

    private function mail(Task $task, array $addresses, string $headline, string $body): void
    {
        if (! $addresses || ! $this->config->on($task->tenant_id, 'email_enabled')) {
            return;
        }

        try {
            $ancestry = $this->tree->ancestryOf($task, (int) $task->tenant_id);
        } catch (\Throwable $e) {
            $ancestry = [];
        }

        try {
            $this->mailer->send(
                (int) $task->tenant_id,
                $addresses,
                new TaskActivityMail($task, $ancestry, $headline, $body),
            );
        } catch (\Throwable $e) {
            Log::warning("Task vendor-link mail failed (task {$task->id}): {$e->getMessage()}");
        }
    }
}
