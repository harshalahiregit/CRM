<?php

namespace Sire\Services;

use Illuminate\Support\Facades\Storage;
use Sire\Contracts\SireAttachmentProvider;
use Sire\Dto\SireAttachment;
use Sire\Models\Report;

/**
 * SIRE — the screenshots, inside the brief.
 *
 * A screenshot is the most useful thing on a bug report and the hardest to carry
 * anywhere. Evidence lives on a private disk behind an authenticated route, so a
 * plain `![](url)` in a markdown file renders as a broken image everywhere except
 * a logged-in browser -- which is the one place the reader already had it.
 *
 * So the brief carries the picture ITSELF as a data URI. It survives being
 * pasted into an editor, a chat, or a model, because there is nothing left to
 * fetch and nothing to authenticate.
 *
 * THUMBNAILS, NOT THE ORIGINALS. Two reasons, and the second is the real one:
 *
 *   - Size. Base64 costs about a third on top, and a brief nobody can paste is a
 *     brief nobody uses. Screenshots arrive already downscaled to 1600px WebP by
 *     the capture path (~24 KB each here); re-encoding at 640px puts a typical
 *     one under 10 KB, so thirty issues stay well inside what a person can paste.
 *   - Reading. A 1600px screenshot inline is a full page of scrolling between one
 *     issue and the next. At thumbnail size you see WHICH screen broke, which is
 *     what the picture is for, and the full-size link is right underneath for
 *     when you need to read the error text in it.
 *
 * A BUDGET, NOT A LIMIT PER IMAGE. One enormous screenshot must not crowd out
 * twenty small ones, so the whole export shares a byte ceiling and the brief says
 * plainly when it ran out rather than quietly dropping the rest.
 *
 * GD IS OPTIONAL. Without it the originals embed as they are; they are already
 * modest. Nothing here is allowed to fail the export -- a brief with no pictures
 * is still the document somebody asked for.
 */
class SireExportImages
{
    /** Widest edge of an embedded thumbnail, in pixels. */
    private const THUMB_EDGE = 640;

    /** WebP quality for the thumbnail. Evidence, not print. */
    private const THUMB_QUALITY = 70;

    /** Everything the whole brief may spend on pictures, before base64. */
    private const BUDGET_BYTES = 3 * 1024 * 1024;

    /** Per issue. Six screenshots of one bug is already an unusual report. */
    private const MAX_PER_ISSUE = 4;

    private int $spent = 0;

    private bool $exhausted = false;

    public function __construct(private readonly SireAttachmentProvider $attachments)
    {
    }

    /** True once the budget ran out, so the header can say so. */
    public function exhausted(): bool
    {
        return $this->exhausted;
    }

    /**
     * Markdown for one issue's evidence.
     *
     * @return array<int, string>
     */
    public function blockFor(Report $report, bool $embed, ?string $baseUrl): array
    {
        try {
            $files = $this->attachments->listFor($report);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $images = array_slice(array_filter(
            $files,
            fn (SireAttachment $a) => str_starts_with((string) $a->mime, 'image/'),
        ), 0, self::MAX_PER_ISSUE);

        $others = array_filter(
            $files,
            fn (SireAttachment $a) => ! str_starts_with((string) $a->mime, 'image/'),
        );

        if ($images === [] && $others === []) {
            return [];
        }

        $out = ['', '**Evidence**', ''];

        foreach ($images as $index => $image) {
            $link = $this->linkTo($report, $image, $baseUrl);
            $caption = $this->caption($report, $image, $index + 1, count($images));
            $data = $embed ? $this->dataUri($image) : null;

            if ($data !== null) {
                // The picture, then the link to the original underneath it. The
                // thumbnail says which screen; the link is for reading the error
                // text in it.
                $out[] = '!['.$caption.']('.$data.')';
                $out[] = '';
                $out[] = '_'.$caption.' — ['.'full size'.']('.$link.')_';
            } else {
                $out[] = '- ['.$caption.']('.$link.')'
                    .($embed ? ' _(too large to embed)_' : '');
            }

            $out[] = '';
        }

        foreach ($others as $file) {
            $out[] = '- ['.$this->fileName($file).']('.$this->linkTo($report, $file, $baseUrl).')';
        }

        return $out;
    }

    /**
     * A base64 data URI for this image, or null when it cannot be produced.
     *
     * Null is a normal answer: no GD, an unreadable file, a budget already spent.
     * The caller falls back to a link, which is what it would have had anyway.
     */
    private function dataUri(SireAttachment $image): ?string
    {
        if ($this->spent >= self::BUDGET_BYTES) {
            $this->exhausted = true;

            return null;
        }

        try {
            $disk = Storage::disk((string) config('sire.attachments.disk'));
            $path = (string) $image->id;

            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        [$bytes, $mime] = $this->thumbnail($bytes, (string) $image->mime);

        // Charged AFTER the resize, because the resize is what decides the cost.
        if ($this->spent + strlen($bytes) > self::BUDGET_BYTES) {
            $this->exhausted = true;

            return null;
        }

        $this->spent += strlen($bytes);

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Downscale to THUMB_EDGE, or hand back the original untouched.
     *
     * @return array{0: string, 1: string} the bytes and the mime they now are
     */
    private function thumbnail(string $bytes, string $mime): array
    {
        if (! extension_loaded('gd')) {
            return [$bytes, $mime ?: 'image/png'];
        }

        try {
            $source = @imagecreatefromstring($bytes);

            if ($source === false) {
                return [$bytes, $mime ?: 'image/png'];
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::THUMB_EDGE / max($width, $height));

            // Already small enough: re-encoding would cost quality and save
            // nothing.
            if ($scale >= 1) {
                imagedestroy($source);

                return [$bytes, $mime ?: 'image/png'];
            }

            $thumb = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));

            // Screenshots of a UI are full of flat colour and text; keeping the
            // alpha channel avoids a black background on anything transparent.
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);

            imagecopyresampled(
                $thumb, $source,
                0, 0, 0, 0,
                imagesx($thumb), imagesy($thumb), $width, $height,
            );

            ob_start();
            $ok = function_exists('imagewebp')
                ? imagewebp($thumb, null, self::THUMB_QUALITY)
                : imagepng($thumb, null, 6);
            $encoded = (string) ob_get_clean();

            imagedestroy($source);
            imagedestroy($thumb);

            if (! $ok || $encoded === '') {
                return [$bytes, $mime ?: 'image/png'];
            }

            return [$encoded, function_exists('imagewebp') ? 'image/webp' : 'image/png'];
        } catch (\Throwable $e) {
            report($e);

            return [$bytes, $mime ?: 'image/png'];
        }
    }

    /**
     * The authenticated download URL.
     *
     * Only the FILENAME goes in the path. An attachment id is a path, and a path
     * in a URL segment encodes its slashes as %2F, which the production web
     * server answers with its own 404 before Laravel sees it.
     */
    private function linkTo(Report $report, SireAttachment $file, ?string $baseUrl): string
    {
        $path = '/api/sire/reports/'.$report->id.'/attachments/'.rawurlencode(basename((string) $file->id));

        return rtrim((string) $baseUrl, '/').$path;
    }

    /**
     * What the picture is OF, not what it is called on disk.
     *
     * Stored names are a timestamp and a hash -- correct, and useless as a
     * caption. "SIR-000013 screenshot 2 of 3" tells a reader which issue they are
     * looking at when the brief is thirty issues long and they have scrolled.
     */
    private function caption(Report $report, SireAttachment $file, int $position, int $total): string
    {
        $label = $report->report_number.' screenshot';

        return $total > 1 ? $label.' '.$position.' of '.$total : $label;
    }

    /** Non-image evidence keeps its real name: that is what identifies a log. */
    private function fileName(SireAttachment $file): string
    {
        $name = trim((string) $file->name) ?: 'attachment';

        return str_replace(['[', ']'], ['(', ')'], $name);
    }
}
