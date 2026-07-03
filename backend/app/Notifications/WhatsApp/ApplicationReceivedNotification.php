<?php

namespace App\Notifications\WhatsApp;

use App\Models\HrCandidate;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class ApplicationReceivedNotification
{
    /**
     * Send application received notification.
     *
     * @param HrCandidate $candidate
     * @return void
     */
    public static function send(HrCandidate $candidate): void
    {
        // Check if candidate can receive WhatsApp
        if (!$candidate->canReceiveWhatsApp()) {
            Log::info('Skipping WhatsApp notification - candidate cannot receive', [
                'candidate_id' => $candidate->id,
                'whatsapp_opt_in' => $candidate->whatsapp_opt_in,
                'has_phone' => !empty($candidate->phone),
            ]);
            return;
        }

        $message = self::buildMessage($candidate);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'application_received',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send application received WhatsApp', [
                'candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the WhatsApp message.
     *
     * @param HrCandidate $candidate
     * @return string
     */
    protected static function buildMessage(HrCandidate $candidate): string
    {
        $jobTitle = optional($candidate->jobPosting)->title ?? 'a position';
        $companyName = config('whatsapp.company_name');

        return "Hi {$candidate->name},\n\n" .
               "Thank you for applying to *{$jobTitle}* at {$companyName}! 🎯\n\n" .
               "We have received your application and our team will review it shortly.\n\n" .
               "Application ID: #{$candidate->id}\n\n" .
               "We'll keep you updated on your application status.\n\n" .
               "- {$companyName} HR Team";
    }
}
