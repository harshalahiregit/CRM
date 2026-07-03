<?php

namespace App\Notifications\WhatsApp;

use App\Models\HrOnboarding;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class OnboardingWelcomeNotification
{
    /**
     * Send onboarding welcome notification.
     *
     * @param HrOnboarding $onboarding
     * @return void
     */
    public static function send(HrOnboarding $onboarding): void
    {
        $candidate = $onboarding->candidate;

        // Check if candidate can receive WhatsApp
        if (!$candidate || !$candidate->canReceiveWhatsApp()) {
            Log::info('Skipping WhatsApp notification - candidate cannot receive', [
                'onboarding_id' => $onboarding->id,
                'candidate_id' => $candidate?->id,
            ]);
            return;
        }

        $message = self::buildMessage($onboarding, $candidate);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'onboarding_welcome',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send onboarding welcome WhatsApp', [
                'onboarding_id' => $onboarding->id,
                'candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the WhatsApp message.
     *
     * @param HrOnboarding $onboarding
     * @param \App\Models\HrCandidate $candidate
     * @return string
     */
    protected static function buildMessage(HrOnboarding $onboarding, $candidate): string
    {
        $companyName = config('whatsapp.company_name');
        $joiningDate = $onboarding->joining_date ? $onboarding->joining_date->format('M d, Y') : 'soon';

        $message = "Hi {$candidate->name},\n\n" .
                   "🎉 *Welcome to {$companyName}!*\n\n" .
                   "We are excited to have you join our team!\n\n" .
                   "📋 *Onboarding Details:*\n" .
                   "• Joining Date: {$joiningDate}\n";

        if ($onboarding->employee_code) {
            $message .= "• Employee ID: {$onboarding->employee_code}\n";
        }

        $message .= "\n📧 You will receive detailed onboarding instructions via email, including:\n" .
                   "• Documents required\n" .
                   "• First day schedule\n" .
                   "• Access credentials\n" .
                   "• Team introductions\n\n" .
                   "If you have any questions, please don't hesitate to reach out to our HR team.\n\n" .
                   "Looking forward to working with you! 🚀\n\n" .
                   "- {$companyName} HR Team";

        return $message;
    }
}
