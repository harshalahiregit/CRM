<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvNotificationLog;
use App\Models\Tpv\TpvRenewal;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Notifications\NotificationService;

/**
 * TPV Communications Centre (Sangoe TPV §31). Two halves:
 *
 *  1. A DERIVED alerts feed — the vendor-facing communications the current
 *     governance state calls for (documents lapsing, overdue NCRs/CAPAs, open
 *     violations, renewals falling due), each with a deep link and a suggested
 *     message. Nothing is stored; it is recomputed from live data.
 *  2. SEND + LOG — dispatch a message to a vendor over the tenant's own
 *     email/WhatsApp/SMS transport (NotificationService) and record it in
 *     tpv_notification_logs, plus an in-app bell notification for the sender's
 *     audit trail.
 *
 * Additive: it reuses the existing notification transport and log; no transport
 * or template code is modified.
 */
class TpvCommunicationService
{
    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    /** Days-ahead a document expiry or renewal due-date becomes an alert. */
    public const HORIZON_DAYS = 30;

    public function __construct(private NotificationService $notifications) {}

    /**
     * Actionable vendor-communication alerts derived from current state, most
     * severe first.
     */
    public function alerts(int $tenantId): array
    {
        $alerts = [];
        $horizon = now()->addDays(self::HORIZON_DAYS);

        // Documents expired / expiring soon.
        $docs = VendorDocument::forTenant($tenantId)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $horizon)
            ->with('vendor:id,company_name,vendor_code')
            ->get();
        foreach ($docs as $d) {
            if (! $d->vendor) {
                continue;
            }
            $expired = $d->expires_at->isPast();
            $alerts[] = $this->alert(
                kind: 'document_expiry',
                severity: $expired ? 'high' : 'medium',
                vendor: $d->vendor,
                title: ($expired ? 'Expired: ' : 'Expiring: ').$d->type_label,
                message: "Your {$d->type_label} ".($expired ? 'has expired on ' : 'expires on ').$d->expires_at->toFormattedDateString().'. Please upload a renewed copy.',
                link: '/app/tpv/document-vault',
                due: $d->expires_at->toDateString(),
            );
        }

        // Overdue NCRs.
        foreach (TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed')
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())
            ->with('vendor:id,company_name,vendor_code')->get() as $n) {
            if (! $n->vendor) {
                continue;
            }
            $alerts[] = $this->alert('ncr_overdue', 'high', $n->vendor,
                "NCR {$n->reference} overdue", "Non-conformance {$n->reference} — \"{$n->title}\" — is past its due date. Please submit your corrective action.",
                '/app/tpv/ncr', $n->due_date->toDateString());
        }

        // Overdue CAPAs.
        foreach (TpvCapa::forTenant($tenantId)->where('status', '!=', 'Verified')
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())
            ->with('vendor:id,company_name,vendor_code')->get() as $c) {
            if (! $c->vendor) {
                continue;
            }
            $alerts[] = $this->alert('capa_overdue', 'high', $c->vendor,
                "CAPA {$c->reference} overdue", "Corrective action {$c->reference} — \"{$c->title}\" — is past its due date. Please close it out with evidence.",
                '/app/tpv/capa', $c->due_date->toDateString());
        }

        // Open violations.
        foreach (TpvVendorViolation::forTenant($tenantId)->where('status', 'Open')
            ->with('vendor:id,company_name,vendor_code')->get() as $v) {
            if (! $v->vendor) {
                continue;
            }
            $alerts[] = $this->alert('violation_open', 'medium', $v->vendor,
                "Open violation {$v->reference}", "A {$v->severity} violation ({$v->type}) is recorded against you. Please review and respond.",
                '/app/tpv/violations', null);
        }

        // Renewals falling due.
        foreach (TpvRenewal::forTenant($tenantId)->where('status', '!=', 'Decided')
            ->whereNotNull('due_date')->whereDate('due_date', '<=', $horizon)
            ->with('vendor:id,company_name,vendor_code')->get() as $r) {
            if (! $r->vendor) {
                continue;
            }
            $overdue = $r->due_date->isPast();
            $alerts[] = $this->alert('renewal_due', $overdue ? 'high' : 'medium', $r->vendor,
                "Renewal {$r->reference} ".($overdue ? 'overdue' : 'due soon'),
                "Your engagement renewal {$r->reference} is due on {$r->due_date->toFormattedDateString()}.",
                '/app/tpv/renewals', $r->due_date->toDateString());
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($alerts, fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

        return $alerts;
    }

    /** Recent outbound communications for this tenant. */
    public function log(int $tenantId, array $filters = [])
    {
        return TpvNotificationLog::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code')
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->latest('id')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get();
    }

    /**
     * Send a message to a vendor over the chosen channel and record it. Email
     * falls back to the vendor's master email; WhatsApp/SMS use the phone.
     */
    public function send(Vendor $vendor, string $channel, string $subject, string $body, ?User $actor): TpvNotificationLog
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new BusinessException("Unknown channel: {$channel}.");
        }

        $recipient = $channel === 'email' ? ($vendor->email ?? $vendor->user?->email) : $vendor->phone;
        if (empty($recipient)) {
            throw new BusinessException('This vendor has no '.($channel === 'email' ? 'email address' : 'phone number').' on file.');
        }

        $status = 'sent';
        try {
            match ($channel) {
                'email'    => $this->notifications->email($recipient, $subject, $body, ['vendor_id' => $vendor->id, 'event' => 'tpv_communication'], $vendor->tenant_id),
                'whatsapp' => $this->notifications->whatsapp($recipient, $body, ['vendor_id' => $vendor->id]),
                'sms'      => $this->notifications->sms($recipient, $body, ['vendor_id' => $vendor->id]),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
        }

        $log = TpvNotificationLog::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'type'      => 'communication',
            'channel'   => $channel,
            'subject'   => $subject,
            'recipient' => $recipient,
            'status'    => $status,
            'sent_at'   => now(),
        ]);

        // In-app breadcrumb for the sender, so the outbound message is visible
        // in their bell without opening the log.
        if ($actor) {
            \App\Models\Notification::create([
                'tenant_id' => $vendor->tenant_id,
                'user_id'   => $actor->id,
                'type'      => 'tpv.communication',
                'title'     => 'Message '.($status === 'sent' ? 'sent to ' : 'failed for ').$vendor->company_name,
                'message'   => \Illuminate\Support\Str::limit($subject, 120),
                'link'      => '/app/tpv/communications',
            ]);
        }

        return $log->load('vendor:id,company_name,vendor_code');
    }

    /* ── Automatic, event-driven dispatch (§31) ─────────────────────────────
     *
     * Push counterparts to the pull-based alerts() feed: when a governance event
     * fires, the vendor is emailed immediately rather than waiting for someone to
     * open the Communications Centre and hit Send. Best-effort — see autoDispatch.
     */

    /** An NCR was raised against the vendor — tell them to act. */
    public function onNcrRaised(TpvNcr $ncr): void
    {
        $vendor = $ncr->vendor ?: Vendor::find($ncr->vendor_id);
        if (! $vendor) {
            return;
        }
        $by = $ncr->due_date ? ' by '.$ncr->due_date->toFormattedDateString() : '';
        $this->autoDispatch($vendor, 'ncr_raised',
            "Non-conformance {$ncr->reference} raised",
            "Dear {$vendor->company_name},\n\n".
            "A non-conformance ({$ncr->reference})".($ncr->title ? " — \"{$ncr->title}\"" : '').
            " has been raised against your engagement. Please review it and submit your corrective action{$by}.\n\n".
            "Regards,\nTPV Vendor Management Team");
    }

    /** A violation was recorded against the vendor — tell them to respond. */
    public function onViolationRecorded(TpvVendorViolation $violation): void
    {
        $vendor = $violation->vendor ?: Vendor::find($violation->vendor_id);
        if (! $vendor) {
            return;
        }
        $this->autoDispatch($vendor, 'violation_recorded',
            "Violation {$violation->reference} recorded",
            "Dear {$vendor->company_name},\n\n".
            "A {$violation->severity} violation ({$violation->type}) has been recorded against you (ref {$violation->reference}). ".
            "Please review the details in your portal and respond.\n\n".
            "Regards,\nTPV Vendor Management Team");
    }

    /**
     * Best-effort email for an automatic event. Honours the auto_dispatch toggle,
     * never throws (a comms failure must not roll back the event that triggered
     * it), emails only when the vendor has an address, and records every attempt
     * (sent / failed / skipped) in the notification log for the audit trail.
     */
    private function autoDispatch(Vendor $vendor, string $event, string $subject, string $body): void
    {
        if (! config('tpv.communications.auto_dispatch', true)) {
            return;
        }

        $recipient = $vendor->email ?? $vendor->user?->email;
        $status = 'skipped';
        if (! empty($recipient)) {
            $status = 'sent';
            try {
                $this->notifications->email($recipient, $subject, $body, ['vendor_id' => $vendor->id, 'event' => $event], $vendor->tenant_id);
            } catch (\Throwable $e) {
                $status = 'failed';
            }
        }

        TpvNotificationLog::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'type'      => $event,
            'channel'   => 'email',
            'subject'   => $subject,
            'recipient' => $recipient ?: null,
            'status'    => $status,
            'sent_at'   => $status === 'sent' ? now() : null,
        ]);
    }

    private function alert(string $kind, string $severity, Vendor $vendor, string $title, string $message, string $link, ?string $due): array
    {
        return [
            'kind'        => $kind,
            'severity'    => $severity,
            'vendor_id'   => $vendor->id,
            'vendor'      => $vendor->company_name,
            'vendor_code' => $vendor->vendor_code,
            'title'       => $title,
            'message'     => $message,
            'link'        => $link,
            'due'         => $due,
        ];
    }
}
