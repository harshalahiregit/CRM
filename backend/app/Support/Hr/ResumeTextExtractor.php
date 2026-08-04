<?php

namespace App\Support\Hr;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Review comment #15 — the second source for PRESENT CO. / DEPT / DESIGNATION /
 * REFERENCE, alongside the LinkedIn profile parse.
 *
 * `smalot/pdfparser` has been a dependency of this project since it was set up
 * and was never called. Resumes are already uploaded, validated and stored
 * (ResumeService), so the text has been sitting on disk unread.
 *
 * DOC/DOCX are handled separately from PDF: a .docx is a zip whose
 * word/document.xml holds the text, which needs no extra library. A legacy .doc
 * is a binary format with no reliable pure-PHP reader, so it returns null rather
 * than a mangled string — a wrong extraction is worse than none, because it
 * silently populates a candidate's record with rubbish.
 */
class ResumeTextExtractor
{
    /** Enough of the document to carry the header block; keeps parsing bounded. */
    private const MAX_CHARS = 20000;

    /**
     * Plain text from a stored resume, or null when it cannot be read.
     *
     * Never throws: an unreadable resume must not fail the upload that triggered
     * it. Callers treat null as "no second source".
     */
    public static function fromStoredPath(?string $path, string $disk = 'hr_resumes'): ?string
    {
        if (blank($path) || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            $text = match ($extension) {
                'pdf'  => self::fromPdf(Storage::disk($disk)->path($path)),
                'docx' => self::fromDocx(Storage::disk($disk)->path($path)),
                // .doc is a binary OLE container; there is no dependable reader
                // here, and a partial read would be worse than none.
                default => null,
            };
        } catch (\Throwable $e) {
            Log::channel('hr')->info('Resume text could not be extracted', [
                'path' => $path, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        $text = trim(preg_replace('/[ \t]+/u', ' ', (string) $text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARS);
    }

    private static function fromPdf(string $absolutePath): ?string
    {
        return (new PdfParser())->parseFile($absolutePath)->getText();
    }

    /** A .docx is a zip; the text lives in word/document.xml. No library needed. */
    private static function fromDocx(string $absolutePath): ?string
    {
        $zip = new \ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        // Paragraph and line breaks become newlines BEFORE tags are stripped, or
        // the whole document collapses into one line and every line-anchored
        // pattern below stops matching.
        //
        // A LOOKAHEAD, not a consuming class: matching the '>' of `<w:p>` and not
        // putting it back leaves `<w:p ` unterminated, and strip_tags then treats
        // the rest of the document as one unclosed tag and returns nothing at all.
        $xml = preg_replace('/<w:p(?=[ >\/])/', "\n<w:p", $xml);
        $xml = preg_replace('/<w:(?:br|tab)\b[^>]*>/', ' ', $xml);
        $xml = preg_replace('/<\/w:(?:tc|tr)>/', "\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
