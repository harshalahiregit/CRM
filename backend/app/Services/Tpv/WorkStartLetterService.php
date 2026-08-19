<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvOnboarding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates and serves the Vendor HSSE & Work Start Letter (Doc_4 wireframe /
 * Leo_Enterprises_HSSE_Work_Start_Letter). Issued once, on approval, when the
 * vendor is activated: it is the formal "approval to commence work" that the
 * plain welcome e-mail never was. Stored privately per vendor and served inline
 * to the admin and to the vendor in their portal.
 *
 * Rendered as a self-contained, print-ready HTML document (the blade carries all
 * its own styling) rather than a binary PDF, so it works without a PDF engine
 * dependency; the browser's "Print → Save as PDF" produces the paper artefact.
 */
class WorkStartLetterService
{
    private const DISK = 'vendor_docs';

    /**
     * Render the letter to a private PDF and record its path on the onboarding.
     * Idempotent-ish: a fresh letter is generated each call (approvals can be
     * re-issued), and the newest path wins. Never throws into the approval flow —
     * a letter failure must not roll back an activation.
     */
    public function generate(TpvOnboarding $onboarding): ?string
    {
        try {
            $onboarding->loadMissing(['vendor.documents', 'vendor.tenant']);
            $vendor = $onboarding->vendor;
            if (! $vendor) {
                return null;
            }

            $approvedDocs = $vendor->documents
                ? $vendor->documents->where('status', 'Approved')->pluck('type')->filter()->values()->all()
                : [];

            $html = view('pdf.tpv_work_start_letter', [
                'onboarding'   => $onboarding,
                'vendor'       => $vendor,
                'tenant'       => $vendor->tenant ?? null,
                'approvedDocs' => $approvedDocs,
                'ref'          => $this->reference($onboarding),
                'issuedAt'     => now(),
            ])->render();

            $path = "letters/{$vendor->id}/work-start-letter-".now()->format('Ymd-His').'.html';
            Storage::disk(self::DISK)->put($path, $html);

            $onboarding->forceFill(['work_start_letter_path' => $path])->save();

            Log::channel('tpv')->info('TPV work start letter issued', [
                'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'path' => $path,
            ]);

            return $path;
        } catch (\Throwable $e) {
            // Non-fatal: the vendor is still activated; the letter can be re-issued.
            Log::channel('tpv')->error('TPV work start letter generation failed', [
                'onboarding_id' => $onboarding->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Inline (viewer-friendly) streamed response for the stored letter. */
    public function stream(TpvOnboarding $onboarding): StreamedResponse
    {
        $path = $onboarding->work_start_letter_path;

        // Lazily generate on first request if approval predates this feature.
        if ((! $path || ! Storage::disk(self::DISK)->exists($path)) && $onboarding->status === \App\Support\Tpv\TpvOnboardingStatus::APPROVED) {
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

    /** Whether a letter has been issued (drives the "Download" affordance). */
    public function exists(TpvOnboarding $onboarding): bool
    {
        return $onboarding->work_start_letter_path
            && Storage::disk(self::DISK)->exists($onboarding->work_start_letter_path);
    }

    private function reference(TpvOnboarding $onboarding): string
    {
        return $onboarding->registration_number
            ?: 'WSL-'.str_pad((string) $onboarding->id, 5, '0', STR_PAD_LEFT);
    }
}
