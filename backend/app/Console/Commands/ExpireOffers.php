<?php

namespace App\Console\Commands;

use App\Models\Hr\HrOffer;
use App\Services\Hr\OfferService;
use Illuminate\Console\Command;

/**
 * Flip offers past their validity window to Expired (+ HR notification), instead of
 * only expiring them lazily when someone opens the portal. Reuses the existing
 * OfferService::expireIfDue() logic — no new business rules here.
 */
class ExpireOffers extends Command
{
    protected $signature = 'offers:expire-due';

    protected $description = 'Expire HR offers whose validity date has passed (Generated/Sent/Viewed).';

    public function handle(OfferService $offers): int
    {
        // isPastValidity() only fires for Generated/Sent/Viewed, so pre-filter to those.
        $due = HrOffer::whereIn('status', ['Generated', 'Sent', 'Viewed'])
            ->whereNotNull('validity_date')
            ->whereDate('validity_date', '<', now()->toDateString())
            ->with('candidate')
            ->get();

        $expired = 0;
        foreach ($due as $offer) {
            $before = $offer->status;
            $offers->expireIfDue($offer);
            if ($offer->fresh()->status === 'Expired' && $before !== 'Expired') {
                $expired++;
            }
        }

        $this->info("Offer expiry sweep complete: {$expired} offer(s) expired out of {$due->count()} due.");

        return self::SUCCESS;
    }
}
