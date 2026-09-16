<?php

namespace App\Services\Hr;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where every HR upload goes, and the only place that decides how.
 *
 * Announcements, expense receipts, advance proofs and correction evidence all
 * take the same kinds of file and all had their own idea of storing them. One
 * store means one answer to "was it converted?", "what is it called?" and "how
 * big did it end up" — and one place to change when that answer changes.
 *
 * Photographs are re-encoded to WebP. A phone camera hands over 2-4 MB of JPEG
 * for something that will only ever be looked at on a screen; the same picture
 * as WebP is a fraction of that, and the server fills up with receipts a great
 * deal more slowly. Documents are stored exactly as they arrived — a PDF is
 * already compressed and re-encoding one would be destructive nonsense.
 *
 * Conversion is best effort. A photo that will not re-encode is stored as it
 * came rather than rejected: somebody has already taken it, and losing their
 * receipt to a codec is worse than storing a larger file.
 */
class AttachmentStore
{
    /** Big enough to read a printed receipt, small enough not to be a photograph album. */
    private const MAX_EDGE = 1920;

    private const WEBP_QUALITY = 82;

    /**
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $folder): array
    {
        $original = $file->getClientOriginalName();

        if ($this->isConvertibleImage($file)) {
            $converted = $this->toWebp($file, $folder);
            if ($converted) {
                return $converted;
            }
        }

        $path = $file->store($folder, 'local');

        return [
            'path' => $path,
            // The name the person chose. The stored path is randomised, so
            // without this every attachment reads as a hash.
            'name' => $original,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * @param  array<UploadedFile>  $files
     * @return array<array{path: string, name: string, mime: string, size: int}>
     */
    public function storeMany(array $files, string $folder): array
    {
        $stored = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $stored[] = $this->store($file, $folder);
            }
        }

        return $stored;
    }

    private function isConvertibleImage(UploadedFile $file): bool
    {
        return function_exists('imagewebp')
            && in_array(strtolower((string) $file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true);
    }

    /** @return array{path: string, name: string, mime: string, size: int}|null */
    private function toWebp(UploadedFile $file, string $folder): ?array
    {
        try {
            $raw = @file_get_contents($file->getRealPath());
            $image = $raw ? @imagecreatefromstring($raw) : false;

            if (! $image) {
                return null;
            }

            $image = $this->resize($image);

            // PNGs may carry transparency; without this it comes out black.
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::WEBP_QUALITY);
            $binary = (string) ob_get_clean();
            imagedestroy($image);

            if (! $ok || $binary === '') {
                return null;
            }

            $path = rtrim($folder, '/').'/'.Str::random(40).'.webp';
            Storage::disk('local')->put($path, $binary);

            return [
                'path' => $path,
                // The extension follows the bytes. A file called receipt.jpg
                // that is actually WebP is how a viewer refuses to open it.
                'name' => pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME).'.webp',
                'mime' => 'image/webp',
                'size' => strlen($binary),
            ];
        } catch (\Throwable $e) {
            Log::warning('Attachment could not be converted to WebP; storing as sent.', [
                'file' => $file->getClientOriginalName(), 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param \GdImage $image */
    private function resize($image)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $longest = max($w, $h);

        if ($longest <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longest;
        $resized = imagescale($image, (int) round($w * $scale), (int) round($h * $scale));

        if (! $resized) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }
}
