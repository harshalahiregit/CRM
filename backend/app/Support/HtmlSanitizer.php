<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Whitelist-based HTML sanitizer for user-authored rich content (customer
 * notes, proposal/contract pages, email bodies). Deliberately dependency-free;
 * if edge cases outgrow it, mews/purifier is the upgrade path.
 *
 * Allowed: structural/text tags + tables + links (http/https only) + images
 * (data:image/* or http/https only). Everything else is unwrapped to its text
 * content; all attributes except the whitelisted ones are stripped.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'h2', 'h3',
        'ul', 'ol', 'li', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img', 'div', 'span',
    ];

    private const ALLOWED_ATTRS = [
        'a'   => ['href'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
    ];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument();
        // Suppress warnings from malformed fragments; wrap so the fragment
        // parses with a known root and UTF-8 is honored.
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__root');
        if (! $root) {
            return '';
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        // Iterate over a static copy — we mutate the tree while walking.
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                // Drop scriptable/embedding elements entirely (content too).
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta'], true)) {
                    $node->removeChild($child);
                    continue;
                }

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Unwrap: hoist children in place, drop the tag itself.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                self::filterAttributes($child, $tag);
                self::walk($child);
            }
        }
    }

    private static function filterAttributes(DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);

            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }

            $value = trim($attr->value);

            if ($name === 'href' && ! preg_match('~^https?://~i', $value)) {
                $el->removeAttribute($attr->name);
            }

            if ($name === 'src' && ! preg_match('~^(https?://|data:image/(png|jpe?g|gif|webp);base64,)~i', $value)) {
                $el->removeAttribute($attr->name);
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer');
            $el->setAttribute('target', '_blank');
        }
    }
}
