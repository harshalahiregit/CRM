<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseNotificationLog;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * Purchase Communications Centre — the Purchase-side mirror of
 * TpvCommunicationService (parity rule). A DERIVED alerts feed over the Purchase
 * governance state (documents lapsing, overdue NCRs/CAPAs) plus send + log to a
 * vendor over the tenant's own email/WhatsApp/SMS transport, recorded in
 * purchase_notification_logs with an in-app bell breadcrumb.
 *
 * Additive: it reuses the existing notification transport and log. Alert kinds
 * whose Purchase mirrors haven't landed (violations, renewals) are simply absent.
 */
class PurchaseCommunicationService
{
    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    public const HORIZON_DAYS = 30;

    public function __construct(private NotificationService $notifications) {}

    /** Actionable vendor-communication alerts derived from current state. */
    public function alerts(int $tenantId): array
    {
        $alerts = [];
        $horizon = now()->addDays(self::HORIZON_DAYS);

        // Documents expired / expiring soon.
        foreach (PurchaseDocument::forTenant($tenantId)
            ->whereNotNull('expires_at')->whereDate('expires_at', '<=', $horizon)
            ->with('vendor:id,company_name,purchase_vendor_code')->get() as $d) {
            if (! $d->vendor) {
                continue;
            }
            $expired = $d->expires_at->isPast();
            $alerts[] = $this->alert('document_expiry', $expired ? 'high' : 'medium', $d->vendor,
                ($expired ? 'Expired: ' : 'Expiring: ').$d->type_label,
                "Your {$d->type_label} ".($expired ? 'has expired on ' : 'expires on ').$d->expires_at->toFormattedDateString().'. Please upload a renewed copy.',
                '/app/purchase/document-vault', $d->expires_at->toDateString());
        }

        // Overdue NCRs.
        foreach (PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed')
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())
            ->with('vendor:id,company_name,purchase_vendor_code')->get() as $n) {
            if (! $n->vendor) {
                continue;
            }
            $alerts[] = $this->alert('ncr_overdue', 'high', $n->vendor,
                "NCR {$n->reference} overdue", "Non-conformance {$n->reference} — \"{$n->title}\" — is past its due date. Please submit your corrective action.",
                '/app/purchase/ncr', $n->due_date->toDateString());
        }

        // Overdue CAPAs.
        foreach (PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified')
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())
            ->with('vendor:id,company_name,purchase_vendor_code')->get() as $c) {
            if (! $c->vendor) {
                continue;
            }
            $alerts[] = $this->alert('capa_overdue', 'high', $c->vendor,
                "CAPA {$c->reference} overdue", "Corrective action {$c->reference} — \"{$c->title}\" — is past its due date. Please close it out with evidence.",
                '/app/purchase/capa', $c->due_date->toDateString());
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($alerts, fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

        return $alerts;
    }

    /** Recent outbound communications for this tenant. */
    public function log(int $tenantId, array $filters = [])
    {
        return PurchaseNotificationLog::forTenant($tenantId)
            ->with('vendor:id,company_name,purchase_vendor_code')
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->latest('id')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get();
    }

    /** Send a message to a vendor over the chosen channel and record it. */
    public function send(PurchaseVendor $vendor, string $channel, string $subject, string $body, ?User $actor): PurchaseNotificationLog
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
                'email'    => $this->notifications->email($recipient, $subject, $body, ['vendor_id' => $vendor->id, 'event' => 'purchase_communication'], $vendor->tenant_id),
                'whatsapp' => $this->notifications->whatsapp($recipient, $body, ['vendor_id' => $vendor->id]),
                'sms'      => $this->notifications->sms($recipient, $body, ['vendor_id' => $vendor->id]),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
        }

        $log = PurchaseNotificationLog::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'type'      => 'communication',
            'channel'   => $channel,
            'subject'   => $subject,
            'recipient' => $recipient,
            'status'    => $status,
            'sent_at'   => now(),
        ]);

        if ($actor) {
            \App\Models\Notification::create([
                'tenant_id' => $vendor->tenant_id,
                'user_id'   => $actor->id,
                'type'      => 'purchase.communication',
                'title'     => 'Message '.($status === 'sent' ? 'sent to ' : 'failed for ').$vendor->company_name,
                'message'   => \Illuminate\Support\Str::limit($subject, 120),
                'link'      => '/app/purchase/communications',
            ]);
        }

        return $log->load('vendor:id,company_name,purchase_vendor_code');
    }

    private function alert(string $kind, string $severity, PurchaseVendor $vendor, string $title, string $message, string $link, ?string $due): array
    {
        return [
            'kind'        => $kind,
            'severity'    => $severity,
            'vendor_id'   => $vendor->id,
            'vendor'      => $vendor->company_name,
            'vendor_code' => $vendor->purchase_vendor_code,
            'title'       => $title,
            'message'     => $message,
            'link'        => $link,
            'due'         => $due,
        ];
    }
}
