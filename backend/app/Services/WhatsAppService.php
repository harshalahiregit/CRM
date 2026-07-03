<?php

namespace App\Services;

use App\Models\HrWhatsAppLog;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class WhatsAppService
{
    protected $client;
    protected $from;
    protected $enabled;

    public function __construct()
    {
        $this->enabled = config('whatsapp.enabled', false);
        
        if ($this->enabled && config('whatsapp.provider') === 'twilio') {
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
     * Format phone number for WhatsApp.
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
