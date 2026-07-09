<?php

namespace App\Notifications\WhatsApp;

use App\Models\Hr\HrInterviewRound;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class InterviewReminderNotification
{
    /**
     * Send interview reminder notification.
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
            ]);
            return;
        }

        $message = self::buildMessage($interview, $candidate);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'interview_reminder',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send interview reminder WhatsApp', [
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
     * @param \App\Models\Hr\HrCandidate $candidate
     * @return string
     */
    protected static function buildMessage($interview, $candidate): string
    {
        $jobTitle = optional($candidate->jobPosting)->title ?? 'a position';
        $date = $interview->scheduled_at->format('M d, Y');
        $time = $interview->scheduled_at->format('g:i A');
        $companyName = config('whatsapp.company_name');
        
        $hoursUntil = now()->diffInHours($interview->scheduled_at);
        
        $timePhrase = $hoursUntil <= 24 ? 'tomorrow' : 'soon';

        return "Hi {$candidate->name},\n\n" .
               "⏰ *Interview Reminder*\n\n" .
               "This is a friendly reminder that your interview for *{$jobTitle}* is scheduled {$timePhrase}!\n\n" .
               "📅 *Date:* {$date}\n" .
               "⏰ *Time:* {$time}\n" .
               "⏱️ *Duration:* {$interview->duration_minutes} minutes\n" .
               "🎤 *Round:* {$interview->round_name}\n" .
               "👤 *Interviewer:* {$interview->interviewer_name}\n\n" .
               "📞 *Meeting Link:*\n{$interview->meet_link}\n\n" .
               "💡 *Tips:*\n" .
               "• Join 5 minutes early\n" .
               "• Ensure stable internet connection\n" .
               "• Keep your resume handy\n" .
               "• Be in a quiet environment\n\n" .
               "All the best! 🍀\n\n" .
               "- {$companyName} HR Team";
    }
}
