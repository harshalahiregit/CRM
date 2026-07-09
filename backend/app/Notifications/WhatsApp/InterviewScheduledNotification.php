<?php

namespace App\Notifications\WhatsApp;

use App\Models\HrInterviewRound;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class InterviewScheduledNotification
{
    /**
     * Send interview scheduled notification.
     *
     * @param HrInterviewRound $interview
     * @return void
     */
    public static function send(HrInterviewRound $interview): void
    {
        $candidate = $interview->candidate;

        // Check if candidate can receive WhatsApp
        if (!$candidate || !$candidate->canReceiveWhatsApp()) {
            Log::info('Skipping WhatsApp notification - candidate cannot receive', [
                'interview_id' => $interview->id,
                'candidate_id' => $candidate?->id,
                'whatsapp_opt_in' => $candidate?->whatsapp_opt_in,
                'has_phone' => !empty($candidate?->phone),
            ]);
            return;
        }

        $message = self::buildMessage($interview, $candidate);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'interview_scheduled',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send interview scheduled WhatsApp', [
                'interview_id' => $interview->id,
                'candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the WhatsApp message.
     *
     * @param HrInterviewRound $interview
     * @param \App\Models\HrCandidate $candidate
     * @return string
     */
    protected static function buildMessage($interview, $candidate): string
    {
        $jobTitle = optional($candidate->jobPosting)->title ?? 'a position';
        $date = $interview->scheduled_at->format('M d, Y');
        $time = $interview->scheduled_at->format('g:i A');
        $companyName = config('whatsapp.company_name');

        return "Hi {$candidate->name},\n\n" .
               "Your interview for *{$jobTitle}* has been scheduled!\n\n" .
               "📅 *Date:* {$date}\n" .
               "⏰ *Time:* {$time}\n" .
               "⏱️ *Duration:* {$interview->duration_minutes} minutes\n" .
               "🎤 *Round:* {$interview->round_name}\n" .
               "👤 *Interviewer:* {$interview->interviewer_name}\n\n" .
               "📞 *Meeting Link:*\n{$interview->meet_link}\n\n" .
               "Please join on time. Good luck!\n\n" .
               "- {$companyName} HR Team";
    }
}
