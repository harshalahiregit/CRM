<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Meta's resumable upload, which is the only way to get a logo into a template.
 *
 * Two things make this awkward enough to be worth isolating:
 *
 *   It hangs off the APP id, not the phone number or the WhatsApp Business
 *   account like everything else in this integration.
 *
 *   The second call authenticates with `Authorization: OAuth <token>` — not
 *   `Bearer`, which every other Graph call uses and which fails here with an
 *   unhelpful error.
 *
 * The handle it returns is what a template's header example is created with. It
 * is not a media id and cannot be used to send anything.
 */
class MediaUploader
{
    public function __construct(
        private string $token,
        private string $appId,
        private string $apiVersion = 'v21.0',
    ) {
    }

    /**
     * @return array{ok: bool, handle: ?string, error: ?string}
     */
    public function uploadForTemplate(string $path): array
    {
        if (! is_readable($path)) {
            return ['ok' => false, 'handle' => null, 'error' => "Cannot read {$path}"];
        }

        $bytes = file_get_contents($path);
        $type = mime_content_type($path) ?: 'image/png';

        $session = Http::post("https://graph.facebook.com/{$this->apiVersion}/{$this->appId}/uploads", [
            'file_name'    => basename($path),
            'file_length'  => strlen($bytes),
            'file_type'    => $type,
            'access_token' => $this->token,
        ]);

        $id = $session->json('id');
        if (! $id) {
            return ['ok' => false, 'handle' => null, 'error' => $this->reason($session, 'Could not start the upload')];
        }

        $upload = Http::withHeaders([
            // OAuth, not Bearer. This is the one call in the whole integration
            // that wants it, and Bearer here fails with a misleading message.
            'Authorization' => 'OAuth '.$this->token,
            'file_offset'   => '0',
            'Content-Type'  => 'application/octet-stream',
        ])->withBody($bytes, 'application/octet-stream')
          ->post("https://graph.facebook.com/{$this->apiVersion}/{$id}");

        $handle = $upload->json('h');
        if (! $handle) {
            return ['ok' => false, 'handle' => null, 'error' => $this->reason($upload, 'Upload did not return a handle')];
        }

        return ['ok' => true, 'handle' => $handle, 'error' => null];
    }

    private function reason($res, string $fallback): string
    {
        $err = $res->json('error') ?? [];

        return $err['message'] ?? ($fallback.' (HTTP '.$res->status().')');
    }
}
