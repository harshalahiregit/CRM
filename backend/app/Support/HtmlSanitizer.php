<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Strict allowlist HTML sanitizer for user-authored rich text (helpdesk ticket
 * replies). The output is rendered as HTML both in the CRM ticket thread AND in
 * outbound email, so it must be XSS-safe.
 *
 * Uses DOMDocument (a real HTML parser) rather than regex — regex "sanitizers"
 * are notoriously bypassable. Everything not on the allowlist is dropped:
 *   - disallowed-but-safe wrapper tags (div, table, …) are UNWRAPPED (their text
 *     is kept, the tag discarded);
 *   - dangerous tags (script, style, iframe, …) are dropped WITH their subtree;
 *   - every attribute is stripped except href on <a> and src/alt on <img>, and
 *     those URLs are scheme-checked (no javascript:/vbscript:/data: on links).
 */
class HtmlSanitizer
{
    /** Tags that survive (attributes still stripped to the allowlist below). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a',
        'blockquote', 'code', 'pre', 'span', 'h1', 'h2', 'h3', 'h4', 'img',
    ];

    /** Tags dropped together with everything inside them. */
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'svg', 'math', 'link',
        'meta', 'base', 'template', 'noscript', 'title', 'head',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so multibyte text isn't mangled, and parse inside an explicit
        // <body> we can walk. LIBXML_NONET blocks any network access during parse.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $frag = $out->createElement('div');
        foreach (iterator_to_array($body->childNodes) as $child) {
            foreach (self::sanitizeNode($child, $out) as $clean) {
                $frag->appendChild($clean);
            }
        }

        // Inner HTML of the fragment — saveHTML() re-encodes text/entities safely.
        $result = '';
        foreach ($frag->childNodes as $node) {
            $result .= $out->saveHTML($node);
        }

        return trim($result);
    }

    /**
     * Sanitize a source node into zero or more destination-doc nodes.
     *
     * @return DOMNode[]
     */
    private static function sanitizeNode(DOMNode $node, DOMDocument $out): array
    {
        if ($node instanceof DOMText) {
            // Text is copied verbatim; serialization escapes <, >, & for us.
            return [$out->createTextNode($node->nodeValue)];
        }

        // Comments, processing instructions, etc. are discarded.
        if (! ($node instanceof DOMElement)) {
            return [];
        }

        $tag = strtolower($node->nodeName);

        // Dangerous element: drop it and everything inside it.
        if (in_array($tag, self::DROP_TAGS, true)) {
            return [];
        }

        // Sanitize children first (recursively).
        $children = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            foreach (self::sanitizeNode($child, $out) as $c) {
                $children[] = $c;
            }
        }

        // Not on the allowlist but not dangerous (div, table, font, …): unwrap —
        // keep the sanitized children, discard the tag itself.
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            return $children;
        }

        $el = $out->createElement($tag);
        self::copyAllowedAttributes($node, $el, $tag);
        foreach ($children as $c) {
            $el->appendChild($c);
        }

        return [$el];
    }

    private static function copyAllowedAttributes(DOMElement $src, DOMElement $dst, string $tag): void
    {
        if ($tag === 'a' && $src->hasAttribute('href')) {
            $href = self::safeUrl($src->getAttribute('href'), ['http', 'https', 'mailto']);
            if ($href !== null) {
                $dst->setAttribute('href', $href);
                // Harden outbound links (defends the CRM window; harmless in email).
                $dst->setAttribute('rel', 'noopener noreferrer nofollow');
                $dst->setAttribute('target', '_blank');
            }
        }

        if ($tag === 'img') {
            $imgSrc = self::safeImageSrc($src->getAttribute('src'));
            if ($imgSrc !== null) {
                $dst->setAttribute('src', $imgSrc);
                if ($src->hasAttribute('alt')) {
                    $dst->setAttribute('alt', $src->getAttribute('alt'));
                }
            }
        }

        // Every other attribute — style, class, id, on* handlers, srcset,
        // formaction, xlink:href, … — is intentionally not copied, so it's gone.
    }

    /**
     * Return the URL if its scheme is allowed (or it's scheme-relative), else null.
     * Whitespace/control chars are stripped before the scheme test so tricks like
     * "java\tscript:" or " javascript:" cannot smuggle a blocked scheme past it.
     */
    private static function safeUrl(string $url, array $schemes): ?string
    {
        $trimmed = trim($url);
        $normalized = strtolower(preg_replace('/[\s\x00-\x20]+/', '', $trimmed));
        if ($normalized === '') {
            return null;
        }
        // Has an explicit scheme? It must be on the allowlist.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalized, $m)) {
            return in_array($m[1], $schemes, true) ? $trimmed : null;
        }
        // No scheme — relative / anchor / query link. Safe for <a>.
        return $trimmed;
    }

    /**
     * Images may be http/https or a data:image URI (for pasted screenshots).
     * data:image/svg is rejected (SVG can carry script). Relative/other schemes
     * are rejected too.
     */
    private static function safeImageSrc(string $src): ?string
    {
        $trimmed = trim($src);
        $normalized = strtolower(preg_replace('/[\s\x00-\x20]+/', '', $trimmed));
        if ($normalized === '') {
            return null;
        }
        if (str_starts_with($normalized, 'data:')) {
            if (! str_starts_with($normalized, 'data:image/')) {
                return null;
            }
            // SVG data URIs can execute script — refuse them.
            if (str_starts_with($normalized, 'data:image/svg')) {
                return null;
            }

            return $trimmed;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalized, $m)) {
            return in_array($m[1], ['http', 'https'], true) ? $trimmed : null;
        }

        // No scheme (relative path) — reject for images.
        return null;
    }
}
