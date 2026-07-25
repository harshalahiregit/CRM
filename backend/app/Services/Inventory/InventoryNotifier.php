<?php

namespace App\Services\Inventory;

use App\Mail\Inventory\CapacityAlertMail;
use App\Mail\Inventory\CountSessionMail;
use App\Mail\Inventory\ExpiryAlertMail;
use App\Mail\Inventory\PickListMail;
use App\Mail\Inventory\RecallMail;
use App\Mail\Inventory\StockAlertMail;
use App\Mail\Inventory\TransferMail;
use App\Mail\Inventory\VoucherActivityMail;
use App\Models\Inventory\Batch;
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

    /* ── Approvals ──────────────────────────────────────────────── */

    /**
     * A document is waiting on somebody's decision.
     *
     * This one always emails, whatever the noise budget: an unapproved document
     * is stock that has NOT moved, and every hour it sits there is an hour the
     * warehouse is blocked on a person who doesn't know they're the blocker.
     */
    public function approvalRequested(Voucher $voucher, int $actorId): void
    {
        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;

        // The raiser cannot approve their own work, so they are excluded here —
        // telling them would be telling them about themselves.
        $audience = $this->audience($voucher->tenant_id, $voucher->warehouse_id, $actorId);

        $this->bell(
            $audience, $voucher->tenant_id, 'inventory.approval_requested',
            "Approval needed: {$label}",
            "{$actor} sent this for approval — ".$this->voucherLine($voucher),
            $this->voucherLink($voucher),
            $actorId,
        );

        $this->mailTo(
            $voucher->tenant_id,
            $this->emails($audience),
            fn () => new VoucherActivityMail(
                $voucher,
                "Approval needed: {$label}",
                "{$actor} has sent this document for approval. Nothing moves in the warehouse until somebody decides.",
            ),
            "approval request for {$voucher->code}",
        );
    }

    /** Approved or sent back. Either way the person who raised it needs to know. */
    public function approvalDecided(Voucher $voucher, int $actorId, bool $approved, ?string $reason): void
    {
        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;

        $title = $approved ? "Approved: {$label}" : "Sent back: {$label}";
        $body = $approved
            ? "{$actor} approved this. It can be posted now."
            : "{$actor} sent this back: ".$reason;

        // Straight to the author — plus admins, because a rejection is a fact
        // about the warehouse, not a private note between two people.
        $audience = $this->audience($voucher->tenant_id, $voucher->warehouse_id, $actorId, [$voucher->created_by]);

        $this->bell(
            $audience, $voucher->tenant_id,
            $approved ? 'inventory.approval_granted' : 'inventory.approval_rejected',
            $title, $body, $this->voucherLink($voucher), $actorId,
        );

        if ($voucher->created_by && (int) $voucher->created_by !== $actorId) {
            $this->mailTo(
                $voucher->tenant_id,
                $this->emails([(int) $voucher->created_by]),
                fn () => new VoucherActivityMail($voucher, $title, $body),
                "approval decision on {$voucher->code}",
            );
        }
    }

    /**
     * Inspection rejected some of a delivery.
     *
     * Always emails: a rejection is money already committed to goods that are
     * not usable, and it is a supplier conversation someone has to start today.
     * Nothing else in the app surfaces it until the document is opened by hand.
     */
    public function goodsRejected(Voucher $voucher, int $actorId): void
    {
        $actor = $this->name($actorId);
        $label = $voucher->type_label.' '.$voucher->code;

        $lines = $voucher->items()->where('rejected_qty', '>', 0)->with('product:id,sku,name')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $body = $lines->take(4)
            ->map(fn ($l) => ($l->product->name ?? 'Item').' — '.$this->qty((float) $l->rejected_qty).' rejected: '.$l->rejection_reason)
            ->implode('; ');

        $audience = $this->audience($voucher->tenant_id, $voucher->warehouse_id, null, [$voucher->created_by]);

        $this->bell(
            $audience, $voucher->tenant_id, 'inventory.goods_rejected',
            "Goods rejected on {$label}".($voucher->supplier_name ? " — {$voucher->supplier_name}" : ''),
            $body, $this->voucherLink($voucher), null,
        );

        $this->mailTo(
            $voucher->tenant_id,
            $this->emails($audience),
            fn () => new VoucherActivityMail(
                $voucher,
                "Goods rejected: {$label}",
                "{$actor} inspected this delivery and rejected part of it. The rejected quantity has NOT gone on the shelf. "
                    ."Raise a vendor return so the supplier takes it back.\n\n".$body,
            ),
            "rejection on {$voucher->code}",
        );
    }

    /**
     * A batch/lot was recalled — a safety/compliance event, so it always bells
     * the responsible people and (subject to the master email switch) emails
     * them. Audience = admins + the manager of the batch's warehouse.
     */
    public function batchRecalled(Batch $batch, int $actorId): void
    {
        $actor = $this->name($actorId);
        $product = $batch->product->name ?? ('Item #'.$batch->product_id);
        $wh = $this->warehouseName($batch->warehouse_id);

        $title = "Batch recalled: {$batch->batch_no} — {$product}";
        $body = "{$actor} recalled batch {$batch->batch_no} of {$product}"
            .($wh ? " at {$wh}" : '')
            .". Reason: {$batch->recall_reason}. "
            .$this->qty((float) $batch->remaining_qty)." remaining quarantined and removed from picking.";

        $audience = $this->audience($batch->tenant_id, $batch->warehouse_id, $actorId);

        $this->bell(
            $audience, $batch->tenant_id, 'inventory.batch_recalled',
            $title, $body, '/app/inventory/traceability', $actorId,
        );

        $this->mailTo(
            $batch->tenant_id,
            $this->emails($audience),
            fn () => new RecallMail($title, $product, $batch, $body),
            "recall of batch {$batch->batch_no}",
        );
    }

    /**
     * Auto-reorder raised draft purchase orders. Bells the buyers (admins +
     * whoever ran it) so they know there's procurement waiting to be reviewed —
     * in-app only, because a draft PO is a to-do, not an incident. If some items
     * had no vendor and were skipped, that's called out so they get one assigned.
     *
     * @param  \App\Models\Inventory\PurchaseOrder[]  $created
     */
    public function purchaseOrdersGenerated(int $tenantId, array $created, int $skipped, ?int $actorId): void
    {
        if (! $created && ! $skipped) {
            return;
        }

        $n = count($created);
        $subject = $n > 0
            ? "{$n} draft purchase order(s) ready to review"
            : 'Low-stock items need a vendor before they can be reordered';

        $body = collect($created)->take(4)
            ->map(fn ($po) => ($po->code ?? 'PO').' — '.($po->vendor->name ?? 'vendor').', '.$po->lines->count().' line(s)')
            ->implode('; ');
        if ($skipped > 0) {
            $body .= ($body ? '. ' : '')."{$skipped} low-stock item(s) have no vendor linked and were skipped.";
        }

        // Buyers = admins; the actor is kept in because they asked for this.
        $audience = $this->audience($tenantId, null, null);

        $this->bell(
            $audience, $tenantId, 'inventory.po_generated',
            $subject, $body, '/app/inventory/purchase-orders', $actorId,
        );
    }

    /**
     * A temperature/humidity reading fell outside a site's band. Always bells the
     * responsible people and (subject to the master switch) emails them — a
     * broken cold chain is spoilage in progress, and the window to save the stock
     * is measured in hours. Audience = admins + the site's manager.
     */
    public function environmentBreach(Warehouse $warehouse, $reading, int $actorId): void
    {
        $bits = [];
        if ($reading->temperature !== null) {
            $bits[] = 'temp '.rtrim(rtrim(number_format((float) $reading->temperature, 2, '.', ''), '0'), '.').'°'
                .($warehouse->temp_min !== null || $warehouse->temp_max !== null
                    ? ' (band '.($warehouse->temp_min ?? '−').'…'.($warehouse->temp_max ?? '−').')' : '');
        }
        if ($reading->humidity !== null) {
            $bits[] = 'humidity '.rtrim(rtrim(number_format((float) $reading->humidity, 2, '.', ''), '0'), '.').'%'
                .($warehouse->humidity_min !== null || $warehouse->humidity_max !== null
                    ? ' (band '.($warehouse->humidity_min ?? '−').'…'.($warehouse->humidity_max ?? '−').')' : '');
        }

        $title = "Environment out of range: {$warehouse->name}";
        $body = implode('; ', $bits).'. Check the site before stored stock is affected.';

        $audience = $this->audience($warehouse->tenant_id, $warehouse->id, null, [$actorId]);

        $this->bell(
            $audience, $warehouse->tenant_id, 'inventory.env_breach',
            $title, $body, '/app/inventory/warehouses', null,
        );

        $this->mailTo(
            $warehouse->tenant_id,
            $this->emails($audience),
            fn () => new VoucherActivityMail(
                new Voucher(['tenant_id' => $warehouse->tenant_id, 'code' => $warehouse->code ?? $warehouse->name, 'type' => 'internal']),
                $title,
                $body,
            ),
            "environment breach at {$warehouse->name}",
        );
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

    /* ── Pick, pack, ship ───────────────────────────────────────── */

    /** A job landed on somebody's list. Straight to them, not the whole team. */
    public function pickListAssigned($list, int $userId, int $actorId): void
    {
        if ($userId === $actorId || ! $this->on($list->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $lines = $list->lines()->count();

        $this->bell(
            [$userId], $list->tenant_id, 'inventory.pick_assigned',
            "{$actor} assigned you a pick: {$list->code}",
            $lines.' line(s) to pick'.($list->warehouse ? ' at '.$list->warehouse->name : ''),
            '/app/inventory/fulfilment', $actorId,
        );

        $this->mailTo(
            $list->tenant_id,
            $this->emails([$userId]),
            fn () => new PickListMail($list, "You have a pick to do: {$list->code}",
                "{$actor} assigned this to you. The list below is what to collect and where from."),
            "pick assignment {$list->code}",
        );
    }

    /**
     * The parcel left the building.
     *
     * Emails whether or not a courier account exists — when there is none, the
     * tracking number was typed off the courier's own slip, and that is exactly
     * the thing a colleague chasing the customer needs to have in writing.
     */
    public function parcelShipped($list, int $actorId, bool $manual): void
    {
        if (! $this->on($list->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $track = $list->tracking_number
            ? $list->carrier.' '.$list->tracking_number
            : 'no tracking number recorded';

        $audience = $this->audience($list->tenant_id, $list->warehouse_id, $actorId, [$list->created_by, $list->assigned_to]);

        $this->bell(
            $audience, $list->tenant_id, 'inventory.parcel_shipped',
            "{$actor} shipped {$list->code}",
            $track.($manual ? ' (recorded by hand)' : ''),
            '/app/inventory/fulfilment', $actorId,
        );

        $this->mailTo(
            $list->tenant_id,
            $this->emails($audience),
            fn () => new PickListMail($list, "Shipped: {$list->code}",
                "{$actor} dispatched this parcel. Tracking: {$track}."),
            "shipment {$list->code}",
        );
    }

    /** It arrived. In-app only — by now nobody is waiting on an inbox. */
    public function parcelDelivered($list, int $actorId): void
    {
        if (! $this->on($list->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $who = $list->received_by ? " — signed for by {$list->received_by}" : '';

        $this->bell(
            $this->audience($list->tenant_id, $list->warehouse_id, $actorId, [$list->created_by, $list->assigned_to]),
            $list->tenant_id, 'inventory.parcel_delivered',
            "Delivered: {$list->code}", trim($who, ' —') ?: 'Marked as delivered.',
            '/app/inventory/fulfilment', $actorId,
        );
    }

    /* ── Transfers on the road ──────────────────────────────────── */

    /**
     * A lorry left. Always emails the receiving end, whatever the noise budget:
     * somebody has to be at the other site to take it in, and a consignment that
     * arrives unannounced sits on a loading bay.
     */
    public function transferDispatched($transfer, int $actorId): void
    {
        if (! $this->on($transfer->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $lines = $transfer->lines()->count();
        $eta = $transfer->expected_at ? ' — expected '.$transfer->expected_at->format('d M Y') : '';

        // The destination's manager first, because they are the ones who have to
        // do something. Admins come along because it is stock in motion.
        $audience = $this->audience($transfer->tenant_id, $transfer->to_warehouse_id, $actorId);

        $this->bell(
            $audience, $transfer->tenant_id, 'inventory.transfer_dispatched',
            "Stock on its way to {$this->warehouseName($transfer->to_warehouse_id)} — {$transfer->code}",
            "{$actor} dispatched {$lines} line(s) from ".$this->warehouseName($transfer->from_warehouse_id).$eta,
            '/app/inventory/transfers', $actorId,
        );

        $this->mailTo(
            $transfer->tenant_id,
            $this->emails($audience),
            fn () => new TransferMail($transfer, "On its way: {$transfer->code}",
                "{$actor} dispatched this consignment. Until somebody receives it, the stock is held in transit and is on nobody's shelf."),
            "dispatch of {$transfer->code}",
        );
    }

    /**
     * It arrived — and possibly not all of it.
     *
     * A clean receipt is in-app only; a SHORT one always emails, because the
     * gap is money that left one building and never reached another, and it is
     * only worth chasing while the driver still remembers the trip.
     */
    public function transferReceived($transfer, int $actorId, array $summary): void
    {
        if (! $this->on($transfer->tenant_id, 'notify_voucher_activity')) {
            return;
        }

        $actor = $this->name($actorId);
        $short = ($summary['short_qty'] ?? 0) > 0;

        $title = $short
            ? "Short delivery on {$transfer->code}"
            : "Received: {$transfer->code}";
        $body = $short
            ? $this->qty((float) $summary['short_qty']).' unit(s) did not arrive — '.$this->money((float) $summary['short_value']).' at cost'
            : 'Everything that was sent arrived.';

        // The people who packed it are the ones who can say what went on the
        // lorry, so the SOURCE warehouse hears about this one.
        $audience = $this->audience($transfer->tenant_id, $transfer->from_warehouse_id, $actorId, [$transfer->dispatched_by]);

        $this->bell(
            $audience, $transfer->tenant_id,
            $short ? 'inventory.transfer_short' : 'inventory.transfer_received',
            $title, "{$actor} received this at ".$this->warehouseName($transfer->to_warehouse_id).". {$body}",
            '/app/inventory/transfers', $actorId,
        );

        if ($short) {
            $this->mailTo(
                $transfer->tenant_id,
                $this->emails($audience),
                fn () => new TransferMail(
                    $transfer, $title,
                    "{$actor} received this consignment and it came up short. {$body} "
                        .'The missing units are still held in transit — they have NOT been written off. '
                        .'Either they turn up and get received late, or somebody signs them off as a transit loss.',
                    $summary,
                ),
                "short delivery on {$transfer->code}",
            );
        }
    }

    /** Somebody signed off stock that never arrived. */
    public function transferLoss($transfer, int $actorId, string $reason, array $summary): void
    {
        $actor = $this->name($actorId);

        // Both ends, plus admins: a write-off is a fact about the route, not a
        // private decision at the receiving desk.
        $audience = array_values(array_unique(array_merge(
            $this->audience($transfer->tenant_id, $transfer->from_warehouse_id, $actorId, [$transfer->dispatched_by]),
            $this->audience($transfer->tenant_id, $transfer->to_warehouse_id, $actorId),
        )));

        $title = "Transit loss written off — {$transfer->code}";

        $this->bell(
            $audience, $transfer->tenant_id, 'inventory.transfer_loss',
            $title, "{$actor}: {$reason}", '/app/inventory/transfers', $actorId,
        );

        // Always emails. This is the one moment stock is declared gone with
        // nobody having seen where it went.
        $this->mailTo(
            $transfer->tenant_id,
            $this->emails($audience),
            fn () => new TransferMail(
                $transfer, $title,
                "{$actor} wrote off stock that never arrived on this consignment. Reason given: {$reason}",
                $summary,
            ),
            "transit loss on {$transfer->code}",
        );
    }

    /* ── Physical counts ────────────────────────────────────────── */

    /** A count sheet landed on somebody's list. Straight to them. */
    public function countAssigned($session, int $userId, int $actorId): void
    {
        if ($userId === $actorId || ! $this->on($session->tenant_id, 'notify_count_activity')) {
            return;
        }

        $actor = $actorId ? $this->name($actorId) : 'The scheduler';
        $lines = $session->lines()->count();
        $what = $session->name ?: $this->scopeLabel($session);

        $this->bell(
            [$userId], $session->tenant_id, 'inventory.count_assigned',
            "{$actor} assigned you a count: {$session->code}",
            $lines.' line(s) to walk — '.$what.($session->warehouse ? ' at '.$session->warehouse->name : ''),
            // Actor 0 means the scheduler raised it, not a person.
            '/app/inventory/counts', $actorId ?: null,
        );

        $this->mailTo(
            $session->tenant_id,
            $this->emails([$userId]),
            fn () => new CountSessionMail($session, "You have a count to do: {$session->code}",
                "{$actor} assigned this to you. Walk the list below and record what is physically there."),
            "count assignment {$session->code}",
        );
    }

    /**
     * A finished sheet is waiting on somebody's signature.
     *
     * This one always emails, like an approval request: an unapproved count is a
     * known discrepancy that the ledger is still lying about, and every day it
     * sits unsigned is a day the numbers are knowingly wrong.
     *
     * @param  array  $summary  the variance block from CountService::variance()
     */
    public function countSubmitted($session, int $actorId, array $summary): void
    {
        $actor = $this->name($actorId);

        // The counter cannot approve their own sheet, so they are excluded —
        // telling them would be telling them about themselves.
        $audience = $this->audience($session->tenant_id, $session->warehouse_id, $actorId);

        $body = $summary['variances'] > 0
            ? $summary['variances'].' of '.$summary['counted'].' line(s) disagree with the ledger — '
                .$this->money((float) $summary['variance_value']).' at cost'
            : 'Every line matched the ledger.';

        $this->bell(
            $audience, $session->tenant_id, 'inventory.count_submitted',
            "Count needs approval: {$session->code}",
            "{$actor} finished counting. {$body}",
            '/app/inventory/counts', $actorId,
        );

        $this->mailTo(
            $session->tenant_id,
            $this->emails($audience),
            fn () => new CountSessionMail(
                $session,
                "Count needs approval: {$session->code}",
                "{$actor} has finished this count and sent it for approval. {$body} "
                    .'Nothing is corrected in the ledger until somebody signs it off.',
                $summary,
            ),
            "count approval request {$session->code}",
        );
    }

    /** Signed off, or sent back to be walked again. */
    public function countDecided($session, int $actorId, bool $approved, ?string $reason): void
    {
        $actor = $this->name($actorId);

        $title = $approved ? "Count approved: {$session->code}" : "Count sent back: {$session->code}";
        $body = $approved
            ? "{$actor} approved this count. The corrections are now in the ledger"
                .($session->variance_value !== null ? ' ('.$this->money((float) $session->variance_value).' at cost).' : '.')
            : "{$actor} sent this back: ".$reason;

        // Both the counter and whoever raised the sheet: one did the work, the
        // other is waiting on the answer.
        $audience = $this->audience($session->tenant_id, $session->warehouse_id, $actorId, [$session->assigned_to, $session->created_by]);

        $this->bell(
            $audience, $session->tenant_id,
            $approved ? 'inventory.count_approved' : 'inventory.count_rejected',
            $title, $body, '/app/inventory/counts', $actorId,
        );

        // Straight to the counter's inbox — someone else just decided what
        // happens to work they did with their own hands.
        $counter = (int) ($session->assigned_to ?: $session->created_by);
        if ($counter && $counter !== $actorId) {
            $this->mailTo(
                $session->tenant_id,
                $this->emails([$counter]),
                fn () => new CountSessionMail($session, $title, $body),
                "count decision on {$session->code}",
            );
        }
    }

    private function scopeLabel($session): string
    {
        return match ($session->scope) {
            'location' => 'selected bins',
            'category' => 'selected categories',
            'abc'      => 'class '.($session->scope_ref['abc_class'] ?? 'A').' items',
            'product'  => 'selected items',
            'random'   => 'a random sample',
            default    => 'the whole warehouse',
        };
    }

    private function money(float $n): string
    {
        return ($n < 0 ? '-' : '').number_format(abs($n), 2);
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

    /* ── Bin capacity ───────────────────────────────────────────── */

    /**
     * The daily housekeeping digest: bins holding more than they can take, and
     * stock at a site with no bin recorded at all.
     *
     * One message covering every warehouse, like the expiry digest — an
     * overfull shelf is a job for the morning, not an interruption. The second
     * half matters more than the first: a bin over its limit is a tidy-up, but
     * stock nobody can locate is stock that will be re-ordered because somebody
     * could not find it.
     *
     * @param  array  $sites  from LayoutService::capacityAlerts()
     */
    public function binsOverCapacity(int $tenantId, array $sites): void
    {
        if (! $sites || ! $this->on($tenantId, 'notify_capacity_alerts')) {
            return;
        }

        $overCount = array_sum(array_map(fn ($s) => count($s['over']), $sites));
        $lost = array_sum(array_map(fn ($s) => (float) $s['unlocated'], $sites));

        $bits = [];
        if ($overCount) {
            $bits[] = "{$overCount} bin(s) over capacity";
        }
        if ($lost > 0) {
            $bits[] = $this->qty($lost).' unit(s) with no bin recorded';
        }
        $subject = 'Warehouse housekeeping: '.implode(', ', $bits);

        $body = collect($sites)->take(3)->map(function ($s) {
            $part = $s['warehouse']['name'].' — ';
            $part .= $s['over'] ? count($s['over']).' bin(s) over their limit' : 'no bins over';
            if ($s['unlocated'] > 0) {
                $part .= ', '.$this->qty((float) $s['unlocated']).' unlocated';
            }

            return $part;
        })->implode('; ');

        // Every warehouse's manager plus admins. Passing no warehouse id keeps
        // it to admins, so each site is announced against its own.
        foreach ($sites as $site) {
            $audience = $this->audience($tenantId, $site['warehouse']['id'], null);

            $this->bell(
                $audience, $tenantId, 'inventory.capacity',
                $subject, $body, '/app/inventory/warehouses', null,
            );
        }

        $all = $this->audience($tenantId, null, null);
        foreach ($sites as $site) {
            $mgr = Warehouse::forTenant($tenantId)->whereKey($site['warehouse']['id'])->value('manager_id');
            if ($mgr) {
                $all[] = (int) $mgr;
            }
        }

        $this->mailTo(
            $tenantId,
            $this->emails(array_values(array_unique($all))),
            fn () => new CapacityAlertMail($subject, $sites),
            'capacity digest',
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
