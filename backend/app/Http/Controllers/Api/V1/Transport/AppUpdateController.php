<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STOS — over-the-air updates for the Sangoé Driver app (self-hosted).
 *
 * This is our own implementation of the Expo Updates protocol (v1), so the app
 * updates its JavaScript over the air from THIS server — no Expo account, no
 * third-party service, nothing running on anyone's laptop. When we change the
 * app's code we export a new bundle and drop it in one folder; the phone picks
 * it up on next launch. No re-download of the APK.
 *
 * Native changes (a new permission, a new native module, an SDK bump) still
 * need a fresh APK — OTA covers the JavaScript layer only, which is the vast
 * majority of day-to-day changes.
 *
 * Both endpoints are PUBLIC: the update check runs before anyone logs in.
 *
 * Layout on disk (the `local` filesystem disk, kept out of git and out of the
 * public web root):
 *   storage/app/private/app-updates/android/<runtimeVersion>/
 *       manifest.json     — describes this update (generated at export time)
 *       bundle.hbc        — the JavaScript bundle
 *       assets/<hash>     — images and other bundled assets
 */
class AppUpdateController extends Controller
{
    // Where updates live, relative to the "local" filesystem disk (storage/app).
    private const ROOT = 'app-updates';

    // A runtime version is a short token; keep it to safe characters so it can
    // never escape the updates folder.
    private const RUNTIME_PATTERN = '/^[A-Za-z0-9._-]{1,40}$/';

    /**
     * The Expo Updates manifest. The app GETs this on launch, compares the
     * update `id` with what it is already running, and downloads only when it
     * differs. Returned as multipart/mixed per Expo protocol version 1.
     */
    public function manifest(Request $request): Response
    {
        $platform = strtolower((string) $request->header('expo-platform', 'android'));
        $runtime  = (string) $request->header('expo-runtime-version', '');

        // No runtime header (a browser hitting the URL, say) — nothing to serve.
        if (! preg_match(self::RUNTIME_PATTERN, $runtime)) {
            return response()->json(['error' => 'A valid expo-runtime-version header is required.'], 400);
        }

        // We only ship an Android app today.
        if ($platform !== 'android') {
            return $this->noUpdate();
        }

        $manifest = $this->readManifest($runtime);
        if ($manifest === null) {
            // Endpoint is live but no update has been published yet for this
            // runtime — the app keeps running its embedded bundle. Not an error.
            return $this->noUpdate();
        }

        // Turn the stored, relative asset references into absolute URLs the phone
        // can fetch — built from THIS request's host, so the same files work on
        // any domain (local test or live) without regenerating the manifest.
        $manifest['launchAsset'] = $this->withUrl($manifest['launchAsset'], $runtime);
        $manifest['assets']      = array_map(
            fn ($a) => $this->withUrl($a, $runtime),
            $manifest['assets'] ?? []
        );

        // Strip the on-disk filename before sending — the phone only needs the URL.
        unset($manifest['launchAsset']['file']);
        foreach ($manifest['assets'] as &$asset) {
            unset($asset['file']);
        }
        unset($asset);

        // Force these to JSON objects — an empty PHP array would encode as `[]`,
        // but the Expo manifest schema requires objects here.
        $manifest['metadata'] = (object) ($manifest['metadata'] ?? []);
        $manifest['extra']    = (object) ($manifest['extra'] ?? []);

        return $this->multipart('manifest', json_encode($manifest, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stream one file of an update (the JS bundle, or a bundled asset), looked
     * up by the key the manifest advertised. Reading the key from the manifest
     * keeps callers from ever naming a path on disk directly.
     */
    public function asset(Request $request): Response
    {
        $runtime = (string) $request->query('runtime', '');
        $key     = (string) $request->query('key', '');

        if (! preg_match(self::RUNTIME_PATTERN, $runtime) || $key === '') {
            abort(400, 'runtime and key are required.');
        }

        $manifest = $this->readManifest($runtime);
        if ($manifest === null) {
            abort(404, 'No update published for this runtime.');
        }

        // Find the manifest entry whose key was asked for — that entry, and
        // nothing else, decides which file on disk is served.
        $entry = null;
        foreach (array_merge([$manifest['launchAsset']], $manifest['assets'] ?? []) as $candidate) {
            if (($candidate['key'] ?? null) === $key) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            abort(404, 'Unknown asset key.');
        }

        $path = self::ROOT . '/android/' . $runtime . '/' . $entry['file'];
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            abort(404, 'Asset file missing on the server.');
        }

        $contentType = $entry['contentType'] ?? 'application/octet-stream';

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) $disk->size($path),
            'Cache-Control'  => 'public, max-age=31536000, immutable',
        ]);
    }

    /** Read and decode the stored manifest for a runtime, or null if none. */
    private function readManifest(string $runtime): ?array
    {
        $path = self::ROOT . '/android/' . $runtime . '/manifest.json';
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }
        $decoded = json_decode($disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Attach the absolute download URL to a manifest asset entry. */
    private function withUrl(array $asset, string $runtime): array
    {
        $asset['url'] = url('/api/app-updates/asset') . '?' . http_build_query([
            'runtime' => $runtime,
            'key'     => $asset['key'],
        ]);

        return $asset;
    }

    /**
     * Tell the app "you are up to date" the way protocol v1 expects: a directive
     * of type noUpdateAvailable, in a multipart body. The app keeps its current
     * bundle and does not download anything.
     */
    private function noUpdate(): Response
    {
        return $this->multipart('directive', json_encode([
            'type' => 'noUpdateAvailable',
        ], JSON_UNESCAPED_SLASHES));
    }

    /** Wrap a JSON body as a single-part multipart/mixed Expo response. */
    private function multipart(string $partName, string $json): Response
    {
        $boundary = 'sangoeupdates' . bin2hex(random_bytes(10));

        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"{$partName}\"\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "\r\n"
            . $json . "\r\n"
            . "--{$boundary}--\r\n";

        return response($body, 200, [
            'Content-Type'          => "multipart/mixed; boundary={$boundary}",
            'expo-protocol-version' => '1',
            'expo-sfv-version'      => '0',
            'Cache-Control'         => 'private, max-age=0',
        ]);
    }
}
