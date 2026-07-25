<?php

namespace App\Support;

/**
 * Sanitises a proposal / template cover-page payload:
 *   { enabled, image, title, heading, body }
 * Image must be an https URL or an inline data:image; title/heading are stripped
 * to plain text; body is run through the shared HTML sanitizer (fonts/colours/
 * images kept, scripts/unsafe styles stripped). Returns null when the cover is
 * effectively empty. Shared by the proposal and template services so both cover
 * editors behave identically.
 */
class CoverSanitizer
{
    public static function clean($cover): ?array
    {
        if (! is_array($cover)) {
            return null;
        }

        $image = (string) ($cover['image'] ?? '');
        if ($image !== '' && ! preg_match('~^(https?://|data:image/(png|jpe?g|gif|webp);base64,)~i', $image)) {
            $image = '';
        }

        $clean = [
            'enabled' => (bool) ($cover['enabled'] ?? false),
            'image'   => $image,
            'title'   => trim(strip_tags((string) ($cover['title'] ?? ''))),
            'heading' => trim(strip_tags((string) ($cover['heading'] ?? ''))),
            'body'    => HtmlSanitizer::clean((string) ($cover['body'] ?? '')),
        ];

        if (! $clean['enabled'] && $clean['image'] === '' && $clean['title'] === '' && $clean['heading'] === '' && $clean['body'] === '') {
            return null;
        }

        return $clean;
    }
}
