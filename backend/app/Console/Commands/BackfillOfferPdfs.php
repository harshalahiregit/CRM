<?php

namespace App\Console\Commands;

use App\Models\Hr\HrOffer;
use App\Services\Hr\OfferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Repairs offers created before the offer-letter PDF existed: any offer whose
 * letter_path is NULL (or points at a missing file) gets its letter rendered
 * through the same OfferService::renderLetter() used by create()/regenerate().
 *
 * Offers that already have a valid PDF on disk are skipped.
 */
class BackfillOfferPdfs extends Command
{
    protected $signature = 'hr:backfill-offer-pdfs {--dry-run : List what would be generated without writing anything}';

    protected $description = 'Generate missing offer letter PDFs for existing offers';

    public function handle(OfferService $offers): int
    {
        $dry = (bool) $this->option('dry-run');
        $done = 0;
        $skipped = 0;
        $failed = 0;

        HrOffer::orderBy('id')->chunkById(100, function ($chunk) use ($offers, $dry, &$done, &$skipped, &$failed) {
            foreach ($chunk as $offer) {
                $hasFile = ! empty($offer->letter_path)
                    && Storage::disk(OfferService::DOC_DISK)->exists($offer->letter_path);

                if ($hasFile) {
                    $skipped++;
                    continue;
                }

                if ($dry) {
                    $this->line("  would generate → OFR-{$offer->id}");
                    $done++;
                    continue;
                }

                try {
                    $path = $offers->renderLetter($offer);
                    $offer->update(['letter_path' => $path]);
                    $this->info("  generated → OFR-{$offer->id}  {$path}");
                    $done++;
                } catch (\Throwable $e) {
                    $this->error("  FAILED → OFR-{$offer->id}: {$e->getMessage()}");
                    $failed++;
                }
            }
        });

        $this->newLine();
        $this->info(($dry ? '[dry-run] ' : '')."Generated: {$done}   Skipped (already had a PDF): {$skipped}   Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
