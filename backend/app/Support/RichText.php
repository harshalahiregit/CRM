<?php

namespace App\Support;

/**
 * The one place text crosses between plain and rich in this codebase.
 *
 * Several columns hold either, depending on who wrote the row. Staff compose in
 * a rich editor, so a ticket reply, an NCR, a MOM action item is HTML. A vendor
 * or customer types into a plain input on their portal, so the same column
 * holds plain text. Nothing reconciled the two, and it broke in both directions:
 *
 *  - Reading. Portal screens printed the stored string as TEXT, so an agent's
 *    "hii" reached the vendor as the literal characters `<p>hii</p><p><br></p>`,
 *    and a governance action item arrived as a wall of `<span style=...>` and
 *    a base64 `<img>` src. Every rich-text field a portal user has ever been
 *    shown looked like that.
 *
 *  - Writing. The portals wrote straight past the controllers where
 *    HtmlSanitizer ran, so portal text was stored raw and then rendered with
 *    dangerouslySetInnerHTML in the staff console — a stored XSS from any
 *    account a stranger can register.
 *
 * Both directions go through here. `fromUntrusted()` normalises input on the
 * way in; `display()` normalises whatever is already stored on the way out, so
 * rows written before this existed are safe to render too, with no backfill.
 */
class RichText
{
    /**
     * Untrusted input, normalised for storage.
     *
     * Plain text is escaped and turned into HTML rather than left as text —
     * because the column's other author writes HTML, and one representation is
     * what makes every consumer correct at once.
     */
    public static function fromUntrusted(?string $input): string
    {
        return self::display($input);
    }

    /**
     * A stored body, as HTML that is safe to render.
     *
     * Applied on read as well as write so the fix reaches history: threads
     * written before this class existed hold raw text or unsanitized markup,
     * and neither should reach a browser unexamined.
     */
    public static function display(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        // Markup goes through the allowlist sanitizer; anything else is plain
        // text and is escaped, so `a < b` stays `a < b` and never becomes a tag.
        return self::hasMarkup($stored)
            ? HtmlSanitizer::clean($stored)
            : nl2br(e($stored), false);
    }

    /** A stored value as plain text — for previews, notifications and search. */
    public static function toText(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        if (! self::hasMarkup($stored)) {
            return trim($stored);
        }

        // Block-level tags become line breaks first, so a paragraph does not
        // run into the next one once the tags are gone.
        $text = preg_replace('#<(?:br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $stored);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whether the string carries real markup.
     *
     * Deliberately a tag test and not an entity test: "R&D" is plain text, and
     * treating it as HTML would leave it stored unescaped.
     */
    private static function hasMarkup(string $s): bool
    {
        return (bool) preg_match('/<[a-z][a-z0-9]*\b[^>]*>|<\/[a-z][a-z0-9]*\s*>/i', $s);
    }
}
