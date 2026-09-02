<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseOnboarding;
use App\Support\Purchase\PurchaseOnboardingStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Purchase work-start letter — mirror of TPV's, on Purchase's tables.
 *
 * purchase_onboardings has carried a work_start_letter_path column all along
 * and nothing ever wrote to it, so an approved Purchase vendor had no document
 * saying they were cleared to start. The letter is the artefact the site gate
 * and the vendor both point at, so a column with no writer is the whole feature
 * missing.
 *
 * Written to the PRIVATE disk: it names the vendor, the approved document set
 * and the approval reference, and it is not something to leave world-readable.
 */
class PurchaseWorkStartLetterService
{
    private const DISK = 'local';

    /**
     * Render the letter and record its path.
     *
     * A fresh letter each call — an approval can legitimately be re-issued, and
     * the newest path wins. NEVER throws into the approval flow: a letter that
     * failed to render must not roll back an activation that was properly
     * decided.
     */
    public function generate(PurchaseOnboarding $onboarding): ?string
    {
        try {
            $onboarding->loadMissing(['vendor']);
            $vendor = $onboarding->vendor;
            if (! $vendor) {
                return null;
            }

            $approvedDocs = [];
            try {
                $approvedDocs = \DB::table('purchase_documents')
                    ->where('purchase_vendor_id', $vendor->id)
                    ->whereNull('deleted_at')
                    ->where('status', 'Approved')
                    ->pluck('type')->filter()->values()->all();
            } catch (\Throwable $e) {
                // The document set is decoration on the letter, not its point.
            }

            $html = view('pdf.purchase_work_start_letter', [
                'onboarding'   => $onboarding,
                'vendor'       => $vendor,
                'tenant'       => $vendor->tenant ?? null,
                'approvedDocs' => $approvedDocs,
                'ref'          => $this->reference($onboarding),
                'issuedAt'     => now(),
            ])->render();

            $path = "purchase-letters/{$vendor->id}/work-start-letter-".now()->format('Ymd-His').'.html';
            Storage::disk(self::DISK)->put($path, $html);

            $onboarding->forceFill(['work_start_letter_path' => $path])->save();

            Log::channel('purchase')->info('Purchase work start letter issued', [
                'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'path' => $path,
            ]);

            return $path;
        } catch (\Throwable $e) {
            Log::channel('purchase')->error('Purchase work start letter generation failed', [
                'onboarding_id' => $onboarding->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Inline streamed response for the stored letter. */
    public function stream(PurchaseOnboarding $onboarding): StreamedResponse
    {
        $path = $onboarding->work_start_letter_path;

        // Generate on first request when the approval predates this feature —
        // otherwise every vendor approved before today would have a permanently
        // empty Download button with no way to fill it.
        if ((! $path || ! Storage::disk(self::DISK)->exists($path))
            && $onboarding->status === PurchaseOnboardingStatus::APPROVED) {
            $path = $this->generate($onboarding);
        }

        abort_unless(
            $path && Storage::disk(self::DISK)->exists($path),
            404,
            'Work start letter is not available yet — it is issued when the vendor is approved.'
        );

        return Storage::disk(self::DISK)->response(
            $path,
            "Work-Start-Letter-{$this->reference($onboarding)}.html",
            ['Content-Type' => 'text/html; charset=UTF-8'],
            'inline'
        );
    }

    /** Whether a letter exists — drives the Download affordance. */
    public function exists(PurchaseOnboarding $onboarding): bool
    {
        return $onboarding->work_start_letter_path
            && Storage::disk(self::DISK)->exists($onboarding->work_start_letter_path);
    }

    private function reference(PurchaseOnboarding $onboarding): string
    {
        return $onboarding->registration_number
            ?: 'PWSL-'.str_pad((string) $onboarding->id, 5, '0', STR_PAD_LEFT);
    }
}
