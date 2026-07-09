<?php

namespace App\Notifications\WhatsApp;

use App\Models\Hr\HrCandidate;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class StatusUpdateNotification
{
    /**
     * Send status update notification.
     *
     * @param HrCandidate $candidate
     * @param string $newStage
     * @return void
     */
    public static function send(HrCandidate $candidate, string $newStage): void
    {
        // Check if candidate can receive WhatsApp
        if (!$candidate->canReceiveWhatsApp()) {
            Log::info('Skipping WhatsApp notification - candidate cannot receive', [
                'candidate_id' => $candidate->id,
                'stage' => $newStage,
            ]);
            return;
        }

        $message = self::buildMessage($candidate, $newStage);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'status_update',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send status update WhatsApp', [
                'candidate_id' => $candidate->id,
                'stage' => $newStage,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the WhatsApp message based on stage.
     *
     * @param HrCandidate $candidate
     * @param string $newStage
     * @return string
     */
    protected static function buildMessage(HrCandidate $candidate, string $newStage): string
    {
        $jobTitle = optional($candidate->jobPosting)->title ?? 'a position';
        $companyName = config('whatsapp.company_name');

        $messages = [
            'Screening' => [
                'emoji' => '🔍',
                'title' => 'Application Under Review',
                'body' => "Good news! Your application for *{$jobTitle}* has moved to the screening stage.\n\nOur team is reviewing your profile in detail.",
            ],
            'Assessment' => [
                'emoji' => '📝',
                'title' => 'Assessment Round',
                'body' => "Congratulations! You have been shortlisted for the assessment round for *{$jobTitle}*.\n\nYou will receive further instructions shortly.",
            ],
            'Interview' => [
                'emoji' => '🎤',
                'title' => 'Interview Stage',
                'body' => "Great news! You have been selected for an interview for *{$jobTitle}*.\n\nYou will receive the interview schedule separately.",
            ],
            'Offer' => [
                'emoji' => '🎁',
                'title' => 'Offer Stage',
                'body' => "Congratulations! 🎉 We are pleased to move forward with an offer for *{$jobTitle}*.\n\nYou will receive the offer letter shortly.",
            ],
            'Hired' => [
                'emoji' => '🎉',
                'title' => 'Welcome Aboard!',
                'body' => "Congratulations! Welcome to the {$companyName} team! 🎊\n\nWe are excited to have you join us for the *{$jobTitle}* role.\n\nOur onboarding team will contact you shortly with next steps.",
            ],
            'Rejected' => [
                'emoji' => '',
                'title' => 'Application Status Update',
                'body' => "Thank you for your interest in *{$jobTitle}* at {$companyName}.\n\nAfter careful consideration, we have decided to move forward with other candidates at this time.\n\nWe encourage you to apply for future openings that match your skills and experience.\n\nBest wishes in your career journey!",
            ],
        ];

        $stageData = $messages[$newStage] ?? [
            'emoji' => '📢',
            'title' => 'Status Update',
            'body' => "Your application status for *{$jobTitle}* has been updated to: *{$newStage}*.",
        ];

        return "Hi {$candidate->name},\n\n" .
               "{$stageData['emoji']} *{$stageData['title']}*\n\n" .
               "{$stageData['body']}\n\n" .
               "- {$companyName} HR Team";
    }
}
