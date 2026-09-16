<?php

namespace Sire\Support;

/**
 * SIRE — sanitising the free text a reporter types.
 *
 * WHY NOT THE HOST'S HtmlSanitizer
 *
 * The host has one and it is good, but it solves a different problem: it
 * allow-lists a rich subset of HTML for content that is meant to BE HTML --
 * customer notes, proposal pages, email bodies. SIRE's title, description and
 * reproduction fields are plain text. They are typed into an <input> and a
 * <textarea>, stored as text, and rendered by React, which escapes on output.
 * Running them through a rich-text allow-list would answer a question nobody
 * asked. (SIRE also cannot import App\Support\* -- one host symbol, enforced by
 * tests/sdk.test.mjs.)
 *
 * WHY NOT STRIP EVERY TAG
 *
 * Because this is a BUG REPORT. "The API returns <div class=x> unclosed" is a
 * perfectly good description, and a sanitiser that eats it has destroyed the
 * evidence the report exists to carry. Stripping all markup optimises for a
 * threat that the render path already handles, at the cost of the one thing the
 * field is for.
 *
 * WHAT THIS ACTUALLY DOES
 *
 * Removes the constructs that can only ever be an attack -- script, style,
 * iframe, object, embed and their contents, inline event handlers, and
 * javascript:/vbscript: URLs -- and leaves every other character exactly as it
 * was typed. Defence in depth: React escapes on render and the mail path escapes
 * with e(), so nothing here is the only line. It is the line that survives
 * someone later rendering a description somewhere that forgot to escape.
 *
 * Control characters go too. They are never meaningful in a bug report and they
 * corrupt terminal output, CSV exports and log lines downstream.
 */
final class SireText
{
    /**
     * Elements whose CONTENT is dropped along with the tags.
     *
     * Unwrapping these would be worse than useless: the text inside a <script>
     * is the payload, not prose.
     */
    private const EXECUTABLE = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'noscript'];

    /** Schemes that execute when someone clicks them. */
    private const DANGEROUS_SCHEMES = '~\b(?:javascript|vbscript|data\s*:\s*text/html)\s*:~i';

    /** on<event>= handlers, quoted or bare. */
    private const EVENT_HANDLER = '~\son[a-z]{3,20}\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i';

    /**
     * Sanitise one free-text field.
     *
     * Null in, null out: an absent optional field must stay absent rather than
     * becoming an empty string, which reads as "the reporter left it blank".
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = $value;

        foreach (self::EXECUTABLE as $tag) {
            // Both the well-formed pair and a lone unclosed opening tag, which is
            // the shape that slips past a naive pattern.
            $clean = preg_replace("~<{$tag}\b[^>]*>.*?</{$tag}\s*>~is", '', $clean) ?? $clean;
            $clean = preg_replace("~</?{$tag}\b[^>]*>~i", '', $clean) ?? $clean;
        }

        $clean = preg_replace(self::EVENT_HANDLER, '', $clean) ?? $clean;
        $clean = preg_replace(self::DANGEROUS_SCHEMES, '', $clean) ?? $clean;

        // Control characters, except the three that carry meaning in a textarea.
        $clean = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Sanitise the named keys of a payload in place, leaving absent keys absent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function cleanKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = self::clean($data[$key]);
            }
        }

        return $data;
    }
}
