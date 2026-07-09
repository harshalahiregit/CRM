<?php

namespace App\Notifications\WhatsApp;

use App\Models\Hr\HrOffer;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class OfferReleasedNotification
{
    /**
     * Send offer released notification.
     *
     * @param HrOffer $offer
     * @return void
     */
    public static function send(HrOffer $offer): void
    {
        $candidate = $offer->candidate;

        // Check if candidate can receive WhatsApp
        if (!$candidate || !$candidate->canReceiveWhatsApp()) {
            Log::info('Skipping WhatsApp notification - candidate cannot receive', [
                'offer_id' => $offer->id,
                'candidate_id' => $candidate?->id,
            ]);
            return;
        }

        $message = self::buildMessage($offer, $candidate);
        
        $whatsapp = new WhatsAppService();
        
        try {
            $whatsapp->send(
                $candidate->getWhatsAppNumber(),
                $message,
                'offer_released',
                $candidate->id,
                $candidate->tenant_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to send offer released WhatsApp', [
                'offer_id' => $offer->id,
                'candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the WhatsApp message.
     *
     * @param HrOffer $offer
     * @param \App\Models\Hr\HrCandidate $candidate
     * @return string
     */
    protected static function buildMessage(HrOffer $offer, $candidate): string
    {
        $jobTitle = optional($candidate->jobPosting)->title ?? 'a position';
        $companyName = config('whatsapp.company_name');
        $joiningDate = $offer->joining_date ? $offer->joining_date->format('M d, Y') : 'TBD';
        $validUntil = $offer->valid_until ? $offer->valid_until->format('M d, Y') : '';

        $message = "Hi {$candidate->name},\n\n" .
                   "🎉 *Congratulations!*\n\n" .
                   "We are pleased to extend an offer for the position of *{$jobTitle}* at {$companyName}!\n\n" .
                   "📄 *Offer Details:*\n" .
                   "• Position: {$jobTitle}\n" .
                   "• Joining Date: {$joiningDate}\n";

        if ($offer->annual_ctc) {
            $ctc = number_format($offer->annual_ctc, 0);
            $message .= "• Annual CTC: ₹{$ctc}\n";
        }

        if ($validUntil) {
            $message .= "• Valid Until: {$validUntil}\n";
        }

        $message .= "\nYou will receive the detailed offer letter via email shortly.\n\n" .
                   "Please review and respond at your earliest convenience.\n\n" .
                   "Welcome to the team! 🎊\n\n" .
                   "- {$companyName} HR Team";

        return $message;
    }
}
