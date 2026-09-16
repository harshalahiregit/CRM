<?php

namespace App\Support\Medical;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a medical examination's evidence is kept.
 *
 * "Evidence" here means the three pictures that make a certificate believable:
 * the doctor's signature, the signature taken at the examination, and the
 * camera photo proving the doctor was with that person. Those two capture
 * fields are mandatory precisely so a certificate is evidence rather than an
 * assertion — which is exactly why where they live matters.
 *
 * ── Why they moved off the public disk ──────────────────────────────────
 * They were written to `storage/app/public`, which is served at /storage/**
 * with no authentication, under names built from `uniqid()`. uniqid() is
 * derived from the clock, not from randomness: thirteen hex characters of
 * seconds and microseconds. Knowing roughly when a record was written reduces
 * the search to a small, walkable space, and the doctor-profile names helpfully
 * carried the sequential user id as well. So "public but nobody can find it"
 * was not true.
 *
 * A downloadable doctor's signature is a forgeable certificate, and that is the
 * one thing this whole module exists to prevent. They now live on the PRIVATE
 * disk under cryptographically random names and are served only through an
 * authenticated route.
 *
 * ── Why stored paths did not change ─────────────────────────────────────
 * The columns hold a path RELATIVE to a disk, so moving the file from
 * `storage/app/public/medical/x.png` to `storage/app/medical/x.png` keeps the
 * relative path identical. Nothing in the database had to be rewritten, which
 * is what makes the migration a file move rather than a data migration.
 *
 * `absolutePath()` still falls back to the public disk, so a record written
 * before the move keeps rendering even if its file was missed.
 */
final class MedicalEvidence
{
    /** The private disk. Reachable by the app, never by a URL. */
    public const DISK = 'local';

    /**
     * Store a base64 data URL and return the path to keep on the record.
     *
     * Returns null for anything that is not a data URL or does not decode, so a
     * malformed capture leaves the column empty rather than pointing at rubbish.
     */
    public static function put(?string $dataUrl, string $prefix, string $extension = 'png'): ?string
    {
        if (! $dataUrl || ! str_contains($dataUrl, 'base64,')) {
            return null;
        }

        $binary = base64_decode(explode('base64,', $dataUrl)[1], true);

        if ($binary === false) {
            return null;
        }

        // 40 random characters, not uniqid(). The name is no longer a secret
        // that matters — the route is authenticated — but a guessable name
        // should never be the only thing between a signature and the world.
        $path = $prefix.Str::random(40).'.'.$extension;
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    /** Store already-decoded bytes (the doctor profile decodes its own). */
    public static function putBinary(string $binary, string $prefix, string $extension = 'png'): string
    {
        $path = $prefix.Str::random(40).'.'.$extension;
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    /**
     * The file on disk, for the PDF renderer.
     *
     * Private first, then public — a record written before the move still has
     * its file on the old disk, and a certificate that silently loses its
     * signature is worse than one extra existence check.
     */
    public static function absolutePath(?string $relative): ?string
    {
        if (! $relative) {
            return null;
        }

        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                return Storage::disk($disk)->path($relative);
            }
        }

        return null;
    }

    /** Which disk actually holds it, for streaming. Null when it is gone. */
    public static function diskFor(?string $relative): ?string
    {
        if (! $relative) {
            return null;
        }

        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * Guard against a path that tries to climb out of the medical folders.
     *
     * The streaming route takes a stored path from a record rather than from
     * the URL, so this is belt and braces — but a file-serving endpoint is
     * exactly where a traversal bug is worth being paranoid about.
     */
    public static function isSafe(?string $relative): bool
    {
        return (bool) $relative
            && ! str_contains($relative, '..')
            && ! str_starts_with($relative, '/')
            && ! preg_match('#^[a-zA-Z]:#', $relative);
    }
}
