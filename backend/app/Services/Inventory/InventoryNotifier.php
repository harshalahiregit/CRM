<?php

namespace App\Services\Inventory;

use App\Mail\Inventory\ExpiryAlertMail;
use App\Mail\Inventory\StockAlertMail;
use App\Mail\Inventory\VoucherActivityMail;
use App\Models\Inventory\Product;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every inventory alert — in-app bell and email — is decided here.
 *
 * Two rules the rest of the module relies on:
 *
 *  1. AUDIENCE MATCHES THE BARRIER. Stock alerts go to the people who can
 *     actually act on them: admins, plus the manager of the warehouse the event
 *     happened at (the same pair that GuardsInventoryAccess lets change a
 *     document there). A storekeeper isn't spammed about a warehouse they can't
 *     touch, and nobody is told about their own action — notify() drops
 *     self-notification, and mail() filters the actor out too.
 *
 *  2. DELIVERY IS BEST-EFFORT. Both channels are swallow-logged, so a dead SMTP
 *     host or a full notifications table can never roll back the stock movement
 *     that triggered the alert. The ledger is the truth; the alert is a courtesy.
 *
 * Categories are individually switchable in Settings → Notifications, and
 * `email_alerts` is the master outbound switch: turn it off and the bell keeps
 * working while nothing leaves the building.
 */
class InventoryNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private ConfigService $config,
    ) {
    }

    /* ── Voucher activity ───────────────────────────────────────── */

    /**
     * A voucher was posted — this is the moment stock actually moved, so it's
     * the moment worth telling people about. In-app only: a workspace that posts
     * twenty receipts a day would learn to filter the email within a week.
     */
    public function voucherPosted(Voucher $voucher, int $actorId): void
    {
        if (! $this->on($voucher->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;

        $this->bell(
            $this->audience($voucher->tenant_id, $voucher->warehouse_id, $actorId),
            $voucher->tenant_id, 'inventory.voucher_posted',
            "{$actor} posted {$label}",
            $this->voucherLine($voucher),
            $this->voucherLink($voucher),
            $actorId,
        );

        // An internal transfer has a second, more specific audience: whoever runs
        // the warehouse the goods are heading to. They have to physically receive
        // it, so this one earns an email.
        if ($voucher->type === 'internal') {
            $this->transferIncoming($voucher, $actorId);
        }
    }

    /**
     * Cancelling a POSTED voucher writes reversing movements — stock that people
     * were counting on has just moved back. The document's author gets an email
     * because someone else undid their work; everyone else gets the bell.
     */
    public function voucherCancelled(Voucher $voucher, int $actorId, bool $wasPosted): void
    {
        if (! $this->on($voucher->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;
        $note = $wasPosted
            ? 'The stock it moved has been reversed in the ledger.'
            : 'It was still a draft, so no stock changed.';

        $this->bell(
            $this->audience($voucher->tenant_id, $voucher->warehouse_id, $actorId, [$voucher->created_by]),
            $voucher->tenant_id, 'inventory.voucher_cancelled',
            "{$actor} cancelled {$label}",
            $note,
            $this->voucherLink($voucher),
            $actorId,
        );

        // Only the author gets this in their inbox, and only if someone else did it.
        if ($wasPosted && $voucher->created_by && (int) $voucher->created_by !== $actorId) {
            $this->mailTo(
                $voucher->tenant_id,
                $this->emails([(int) $voucher->created_by]),
                fn () => new VoucherActivityMail(
                    $voucher,
                    "Cancelled: {$label}",
                    "{$actor} cancelled this document. {$note}",
                ),
                "cancellation of {$voucher->code}",
            );
        }
    }

    /** Goods are on their way to a warehouse someone else runs. */
    private function transferIncoming(Voucher $voucher, int $actorId): void
    {
        $destIds = $voucher->items()->pluck('to_warehouse_id')->filter()->unique()->all();
        if (! $destIds) {
            return;
        }

        $managers = Warehouse::forTenant($voucher->tenant_id)
            ->whereIn('id', $destIds)
            ->whereNotNull('manager_id')
            ->pluck('manager_id')->map(fn ($i) => (int) $i)
            ->reject(fn ($id) => $id === $actorId)
            ->unique()->values()->all();

        if (! $managers) {
            return;
        }

        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;

        $this->bell(
            $managers, $voucher->tenant_id, 'inventory.transfer_incoming',
            "Stock inbound to your warehouse — {$label}",
            "{$actor} transferred stock in. Confirm it arrives.",
            $this->voucherLink($voucher),
            $actorId,
        );

        $this->mailTo(
            $voucher->tenant_id,
            $this->emails($managers),
            fn () => new VoucherActivityMail(
                $voucher,
                "Incoming stock: {$label}",
                "{$actor} has transferred stock to your warehouse. The lines below are on their way — please confirm they arrive.",
            ),
            "incoming transfer {$voucher->code}",
        );
    }

    /* ── Stock level alerts ─────────────────────────────────────── */

    /**
     * Items that just crossed their reorder point.
     *
     * Edge-triggered on purpose: `$crossed` is computed by comparing on-hand
     * before and after a posting, so an item that is *already* low doesn't
     * re-alert on every subsequent issue. Being told once, at the moment it
     * happens, is a working alert; being told hourly is a filter rule.
     *
     * @param  array<int,array{product:Product,on_hand:float,threshold:float}>  $crossed
     */
    public function stockCrossedThreshold(int $tenantId, array $crossed, ?int $warehouseId, int $actorId): void
    {
        if (! $crossed || ! $this->on($tenantId, 'notify_stock_alerts')) {
            return;
        }

        // The whole batch is escalated if anything in it actually hit zero —
        // "out of stock" is a different conversation from "getting low".
        $out = array_values(array_filter($crossed, fn ($c) => $c['on_hand'] <= 0));
        $type = $out ? 'inventory.out_of_stock' : 'inventory.low_stock';

        $first = $crossed[0]['product'];
        $more = count($crossed) - 1;
        $subject = $out
            ? ($more > 0 ? count($out).' item(s) are out of stock' : "Out of stock: {$first->name}")
            : ($more > 0 ? count($crossed).' items fell below their reorder point' : "Low stock: {$first->name}");

        $body = collect($crossed)->take(4)
            ->map(fn ($c) => "{$c['product']->name} — ".$this->qty($c['on_hand']).' left (reorder at '.$this->qty($c['threshold']).')')
            ->implode('; ');
        if (count($crossed) > 4) {
            $body .= ' …and '.(count($crossed) - 4).' more';
        }

        // The actor is INCLUDED here, not excluded as they are everywhere else.
        // Everywhere else the rule is "you don't need telling about your own
        // action" — but the storekeeper who just issued the last twelve units is
        // precisely the person standing next to the empty shelf, and the one who
        // can raise the replenishment. Suppressing it because they caused it
        // would be the wrong kind of consistent.
        $audience = $this->audience($tenantId, $warehouseId, null, [$actorId]);

        $this->bell(
            $audience, $tenantId, $type, $subject, $body,
            count($crossed) === 1 ? "/app/inventory/products/{$first->id}" : '/app/inventory/products?alert=low',
            null,
        );

        $this->mailTo(
            $tenantId,
            $this->emails($audience),
            fn () => new StockAlertMail($subject, $crossed, $out !== [], $this->warehouseName($warehouseId)),
            'stock alert',
        );
    }

    /* ── Expiry ─────────────────────────────────────────────────── */

    /**
     * Daily digest of batches nearing (or past) expiry. One message covering
     * everything, not one per batch — expiry is a shopping list, not an incident.
     *
     * @param  \Illuminate\Support\Collection  $batches  rows with product loaded
     */
    public function expiringBatches(int $tenantId, $batches, int $withinDays): void
    {
        if ($batches->isEmpty() || ! $this->on($tenantId, 'notify_expiry_alerts')) {
            return;
        }

        $expired = $batches->filter(fn ($b) => $b->expiry_date && $b->expiry_date->isPast())->count();
        $subject = $expired
            ? "{$expired} batch(es) have expired, ".($batches->count() - $expired)." expiring within {$withinDays} days"
            : "{$batches->count()} batch(es) expiring within {$withinDays} days";

        $body = $batches->take(4)
            ->map(fn ($b) => ($b->product->name ?? 'Item')." batch {$b->batch_no} — ".
                ($b->expiry_date ? $b->expiry_date->format('d M Y') : 'no date'))
            ->implode('; ');

        $audience = $this->audience($tenantId, null, null);

        $this->bell(
            $audience, $tenantId, 'inventory.expiring',
            $subject, $body, '/app/inventory/traceability', null,
        );

        $this->mailTo(
            $tenantId,
            $this->emails($audience),
            fn () => new ExpiryAlertMail($subject, $batches->values()->all(), $withinDays),
            'expiry digest',
        );
    }

    /* ── Audience ───────────────────────────────────────────────── */

    /**
     * Who hears about an event at this warehouse: every admin, plus the
     * warehouse's manager. Mirrors who is allowed to *act* on it, so an alert
     * never lands with someone who'd only get a 403 for responding to it.
     *
     * @param  int[]  $alsoInclude  extra user ids (e.g. a document's author)
     * @return int[]
     */
    private function audience(int $tenantId, $warehouseId, ?int $excludeUserId, array $alsoInclude = []): array
    {
        $ids = User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('role', 'admin')
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        if ($warehouseId) {
            $manager = Warehouse::forTenant($tenantId)->whereKey($warehouseId)->value('manager_id');
            if ($manager) {
                $ids[] = (int) $manager;
            }
        }

        foreach ($alsoInclude as $extra) {
            if ($extra) {
                $ids[] = (int) $extra;
            }
        }

        $ids = array_values(array_unique($ids));

        return $excludeUserId ? array_values(array_diff($ids, [$excludeUserId])) : $ids;
    }

    /** Addresses for a set of users, plus the tenant's extra alert inbox. */
    private function emails(array $userIds): array
    {
        return User::whereIn('id', $userIds)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->unique()->values()->all();
    }

    /* ── Delivery ───────────────────────────────────────────────── */

    /** @param int[] $userIds */
    private function bell(array $userIds, int $tenantId, string $type, string $title, ?string $message, ?string $link, ?int $actorId): void
    {
        foreach ($userIds as $uid) {
            $this->notifications->notify($uid, $tenantId, $type, $title, $message, $link, $actorId);
        }
    }

    /**
     * Send one mailable to a list of addresses, honouring the tenant's master
     * email switch and its extra alert inbox. The mailable is built lazily so we
     * never pay for it when email is off or there's nobody to tell.
     */
    private function mailTo(int $tenantId, array $addresses, callable $make, string $what): void
    {
        if (! $this->on($tenantId, 'email_alerts')) {
            return;
        }

        $extra = $this->config->get($tenantId, 'alert_email_extra');
        if ($extra) {
            $addresses[] = $extra;
        }

        $addresses = array_values(array_unique(array_filter($addresses)));
        if (! $addresses) {
            return;
        }

        try {
            Mail::to($addresses)->send($make());
        } catch (\Throwable $e) {
            Log::warning("Inventory mail failed ({$what}): {$e->getMessage()}");
        }
    }

    /* ── Small helpers ──────────────────────────────────────────── */

    private function on(int $tenantId, string $key): bool
    {
        try {
            return (bool) $this->config->get($tenantId, $key);
        } catch (\Throwable $e) {
            return true;   // a broken settings read shouldn't silence every alert
        }
    }

    private function name(?int $userId): string
    {
        return $userId ? (User::whereKey($userId)->value('name') ?: 'Someone') : 'Someone';
    }

    private function warehouseName($warehouseId): ?string
    {
        return $warehouseId ? Warehouse::whereKey($warehouseId)->value('name') : null;
    }

    private function voucherLink(Voucher $voucher): string
    {
        return "/app/inventory/vouchers/{$voucher->type}";
    }

    private function voucherLine(Voucher $voucher): string
    {
        $lines = $voucher->items()->count();

        return $lines.' line(s)'.($voucher->warehouse_id ? ' at '.$this->warehouseName($voucher->warehouse_id) : '');
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }
}
