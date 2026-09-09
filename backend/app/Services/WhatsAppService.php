<?php

namespace App\Services;

use App\Models\Hr\HrWhatsAppLog;
use App\Services\WhatsApp\TenantWhatsApp;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

/**
 * Recruitment's WhatsApp (interview invites, status updates, reminders).
 *
 * Now speaks BOTH providers. It was Twilio-only, and the day the provider
 * default moved to Meta's Cloud API every call here would have dereferenced a
 * client that was never built -- the Twilio branch is the only one that ever
 * constructed one. Cloud sends go through the same CloudApiClient the
 * notification engine uses, so there is one wire format in the codebase.
 */
class WhatsAppService
{
    protected $client;
    protected $from;
    protected $enabled;
    protected string $provider;

    public function __construct(private ?TenantWhatsApp $tenants = null)
    {
        $this->tenants ??= app(TenantWhatsApp::class);
        $this->enabled = config('whatsapp.enabled', false);
        $this->provider = (string) config('whatsapp.provider', 'cloud');

        if ($this->enabled && $this->provider === 'twilio') {
            try {
                $this->client = new Client(
                    config('whatsapp.twilio.account_sid'),
                    config('whatsapp.twilio.auth_token')
                );
                $this->from = config('whatsapp.twilio.from');
            } catch (\Exception $e) {
                Log::error('WhatsApp Service initialization failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->enabled = false;
            }
        }
    }

    /**
     * Send a WhatsApp message.
     *
     * @param string $to Phone number to send to
     * @param string $message Message content
     * @param string $eventType Type of event (interview_scheduled, status_update, etc.)
     * @param int|null $candidateId Candidate ID
     * @param int|null $tenantId Tenant ID
     * @return HrWhatsAppLog
     */
    public function send(
        string $to,
        string $message,
        string $eventType,
        ?int $candidateId = null,
        ?int $tenantId = null
    ): HrWhatsAppLog {
        // Format phone number
        $to = $this->formatPhoneNumber($to);

        // Create log entry
        $log = HrWhatsAppLog::create([
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'to_number' => $to,
            'event_type' => $eventType,
            'message' => $message,
            'status' => 'queued',
        ]);

        // If WhatsApp is disabled, just log and return
        if (!$this->enabled) {
            Log::info('WhatsApp disabled. Message logged but not sent.', [
                'log_id' => $log->id,
                'to' => $to,
                'event_type' => $eventType,
            ]);
            return $log;
        }

        try {
            if ($this->provider === 'cloud') {
                return $this->sendViaCloud($log, $message, $tenantId);
            }

            $result = $this->client->messages->create(
                $to,
                [
                    'from' => $this->from,
                    'body' => $message,
                ]
            );

            $log->update([
                'message_sid' => $result->sid,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info('WhatsApp message sent successfully', [
                'log_id' => $log->id,
                'message_sid' => $result->sid,
                'to' => $to,
                'event_type' => $eventType,
            ]);

            return $log;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('WhatsApp message send failed', [
                'log_id' => $log->id,
                'to' => $to,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return $log;
        }
    }

    /**
     * Meta's Cloud API path.
     *
     * Free text first (it reads better), template on 131047 -- which is Meta
     * telling us the person has not messaged the business in 24 hours, so only
     * an approved template will land.
     */
    protected function sendViaCloud(HrWhatsAppLog $log, string $message, ?int $tenantId): HrWhatsAppLog
    {
        $client = $tenantId
            ? $this->tenants->clientFor($tenantId)
            : $this->tenants->platformClient();

        if (! $client) {
            $log->update(['status' => 'failed', 'error_message' => 'WhatsApp is not configured.']);

            return $log;
        }

        // The log holds the Twilio-shaped 'whatsapp:+91…'; Meta wants digits.
        $to = \App\Services\WhatsApp\CloudApiClient::normalise($log->to_number);

        $result = $client->sendText($to, $message);

        if (! $result->ok && $result->isOutsideServiceWindow()) {
            $result = $client->sendTemplate(
                $to,
                (string) config('whatsapp.cloud.notification_template'),
                (string) config('whatsapp.cloud.notification_template_language'),
                ['Update', $message],
            );
        }

        if ($result->ok) {
            $log->update(['message_sid' => $result->messageId, 'status' => 'sent', 'sent_at' => now()]);
        } else {
            $log->update(['status' => 'failed', 'error_message' => $result->error]);
            Log::error('WhatsApp (cloud) send failed', ['log_id' => $log->id, 'error' => $result->error]);
        }

        return $log;
    }

    /**
     * Format phone number for WhatsApp.
     *
     * Twilio's shape. The Cloud API path re-normalises from this, so callers
     * keep one convention regardless of provider.
     *
     * @param string $phone
     * @return string
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        // If already has whatsapp: prefix, return as is
        if (str_starts_with($phone, 'whatsapp:')) {
            return $phone;
        }

        // Add + if not present
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        // Add whatsapp: prefix
        return 'whatsapp:' . $phone;
    }

    /**
     * Update message status from webhook.
     *
     * @param string $messageSid
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $messageSid, string $status): bool
    {
        $log = HrWhatsAppLog::where('message_sid', $messageSid)->first();

        if (!$log) {
            Log::warning('WhatsApp log not found for message SID', [
                'message_sid' => $messageSid,
                'status' => $status,
            ]);
            return false;
        }

        $log->update(['status' => $status]);

        if ($status === 'delivered') {
            $log->update(['delivered_at' => now()]);
        }

        Log::info('WhatsApp message status updated', [
            'log_id' => $log->id,
            'message_sid' => $messageSid,
            'status' => $status,
        ]);

        return true;
    }

    /**
     * Get statistics for WhatsApp messages.
     *
     * @param int|null $tenantId
     * @return array
     */
    public function getStats(?int $tenantId = null): array
    {
        $query = HrWhatsAppLog::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $totalSent = $query->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $totalDelivered = $query->where('status', 'delivered')->count();
        $totalFailed = $query->whereIn('status', ['failed', 'undelivered'])->count();
        $totalQueued = $query->where('status', 'queued')->count();

        $deliveryRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 2) : 0;

        return [
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'total_queued' => $totalQueued,
            'delivery_rate' => $deliveryRate,
        ];
    }

    /**
     * Check if WhatsApp is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
