<?php

namespace App\Support;

/**
 * Render a rich-text column for display.
 *
 * Fields like proposal notes, estimate client notes and contract descriptions
 * used to be plain textareas and only recently became notepad (rich text) fields.
 * Rows written before that are plain text whose line breaks came from the
 * surrounding `white-space: pre-wrap`. Printing them through `{!! !!}` would
 * collapse every one of those breaks into a single run-on paragraph.
 *
 * So: a value with no markup is escaped and its newlines turned into <br>, which
 * reproduces exactly what the old rendering looked like. A value that already
 * contains markup was sanitized by HtmlSanitizer on save and is returned as-is.
 *
 * This is a DISPLAY helper, not a security boundary — HtmlSanitizer on write is.
 * It deliberately does not re-sanitize: a stored value is already clean, and
 * cleaning twice would strip legitimately escaped entities.
 */
class RichText
{
    public static function toHtml(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        // No tags at all → legacy plain text.
        if (strip_tags($value) === $value) {
            return nl2br(e($value), false);
        }

        return $value;
    }
}
