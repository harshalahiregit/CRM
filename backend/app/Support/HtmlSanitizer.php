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
 * (data:image/* or http/https) + native media (<audio>/<video> with https src)
 * + a STRICT allowlist of embed iframes (YouTube / Vimeo only, forced-sandboxed).
 * A small, whitelisted subset of inline style (text-align, width) is kept for
 * layout/alignment; everything else — tags, attributes, styles, schemes — is
 * stripped. Unknown tags are unwrapped to their text content.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'h2', 'h3',
        'blockquote', 'pre', 'code',
        'ul', 'ol', 'li', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img', 'div', 'span', 'figure', 'figcaption',
        'audio', 'video', 'source', 'iframe',
    ];

    private const ALLOWED_ATTRS = [
        'a'      => ['href'],
        'img'    => ['src', 'alt', 'width', 'height', 'style'],
        'td'     => ['colspan', 'rowspan', 'align', 'width', 'style'],
        'th'     => ['colspan', 'rowspan', 'align', 'width', 'style'],
        'table'  => ['width', 'align', 'border', 'cellpadding', 'cellspacing', 'style'],
        'p'      => ['style'],
        'div'    => ['style'],
        'h2'     => ['style'],
        'h3'     => ['style'],
        'span'   => ['style'],
        'figure' => ['style'],
        'audio'  => ['controls', 'src'],
        'video'  => ['controls', 'width', 'height', 'src', 'poster', 'style'],
        'source' => ['src', 'type'],
        'iframe' => ['src', 'width', 'height', 'allowfullscreen', 'title', 'style'],
    ];

    /** Only these hosts/paths may be embedded via <iframe>. */
    private const IFRAME_ALLOWLIST = '~^https://(www\.)?(youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)~i';

    /** Style properties we keep, each with a value pattern it must match. */
    private const ALLOWED_STYLES = [
        'text-align'  => '~^(left|right|center|justify)$~i',
        'width'       => '~^\d{1,4}(px|%)?$~i',
        'max-width'   => '~^\d{1,4}(px|%)?$~i',
        'height'      => '~^(auto|\d{1,4}(px|%)?)$~i',
        'margin'      => '~^[\d.\s a-z%]{1,40}$~i',
        // Rich text formatting (Word-like) — colours, sizes and font family.
        // Values are strictly validated so no url()/expression() can sneak in.
        'color'            => '~^(#[0-9a-f]{3,8}|rgba?\([\d.,\s%]{1,40}\)|[a-z]{3,20})$~i',
        'background-color' => '~^(#[0-9a-f]{3,8}|rgba?\([\d.,\s%]{1,40}\)|[a-z]{3,20})$~i',
        'font-size'        => '~^(xx-small|x-small|small|medium|large|x-large|xx-large|smaller|larger|\d{1,3}(px|pt|em|rem|%))$~i',
        'font-family'      => '~^[\w\s,\-\'"]{1,80}$~',
        'font-weight'      => '~^(normal|bold|bolder|lighter|[1-9]00)$~i',
        'font-style'       => '~^(normal|italic)$~i',
        'text-decoration'  => '~^(none|underline|line-through)$~i',
    ];

    /**
     * Clean several rich-text keys of a request payload in one call.
     *
     * Services accumulated one `if (isset($data['x'])) { … }` per rich field, and
     * every new field meant remembering to add another — a field silently missed
     * is stored unsanitized. Listing the keys in one place makes the set of rich
     * fields obvious at the call site.
     *
     * Keys absent from `$data` are left absent (so a partial update doesn't blank
     * a column), and a null value stays null rather than becoming an empty string.
     */
    public static function cleanFields(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $data[$key] = self::clean((string) $data[$key]);
            }
        }

        return $data;
    }

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument();
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
        $unwrapped = false;
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if (! ($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->nodeName);

            // Scriptable/dangerous elements: drop entirely (content too).
            if (in_array($tag, [
                'script', 'style', 'object', 'embed', 'form', 'link', 'meta', 'base',
                // Form controls and script-carrying containers (svg/math can hold
                // handlers; noscript/template can hide a payload from the parser).
                'input', 'button', 'textarea', 'select', 'option',
                'svg', 'math', 'template', 'noscript', 'title', 'head',
            ], true)) {
                $node->removeChild($child);
                continue;
            }

            // iframe survives ONLY if its src is on the embed allowlist.
            if ($tag === 'iframe') {
                $src = trim($child->getAttribute('src'));
                if (! preg_match(self::IFRAME_ALLOWLIST, $src)) {
                    $node->removeChild($child);
                    continue;
                }
                self::filterAttributes($child, $tag);
                // Force a locked-down sandbox regardless of what was submitted.
                $child->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
                $child->setAttribute('loading', 'lazy');
                continue; // no children worth walking
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap: hoist children in place, drop the tag itself.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $unwrapped = true;
                continue;
            }

            self::filterAttributes($child, $tag);
            self::walk($child);
        }

        // Hoisted children were inserted AFTER the snapshot above was taken, so this
        // pass never inspected them — which previously let anything nested inside an
        // unknown tag escape every filter (e.g. <h1><script>… survived verbatim,
        // because h1 is not in ALLOWED_TAGS). Re-scan until nothing new is hoisted;
        // each re-scan strictly removes at least one element, so this terminates.
        if ($unwrapped) {
            self::walk($node);
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

            // URL-bearing attributes: enforce safe schemes per element.
            // Links: normalise rather than drop. A user who typed a bare domain
            // ("example.com", "www.example.com") in the editor produced a
            // schemeless href — previously we deleted it, so the link silently
            // reverted to plain text on reload. Now we default it to https://,
            // keep mailto:/tel:, and still drop dangerous schemes (javascript:, …).
            if ($name === 'href') {
                $safe = self::normalizeHref($value);
                if ($safe === null) {
                    $el->removeAttribute($attr->name);
                } elseif ($safe !== $attr->value) {
                    $el->setAttribute('href', $safe);
                }
            }
            if ($name === 'src') {
                $ok = match ($tag) {
                    'img'    => (bool) preg_match('~^(https?://|data:image/(png|jpe?g|gif|webp);base64,)~i', $value),
                    'iframe' => (bool) preg_match(self::IFRAME_ALLOWLIST, $value),
                    default  => (bool) preg_match('~^https://~i', $value), // audio/video/source: https only
                };
                if (! $ok) {
                    $el->removeAttribute($attr->name);
                }
            }
            if ($name === 'align' && ! preg_match('~^(left|right|center|justify)$~i', $value)) {
                $el->removeAttribute($attr->name);
            }
            if (in_array($name, ['width', 'height'], true) && ! preg_match('~^\d{1,4}%?$~', $value)) {
                $el->removeAttribute($attr->name);
            }
            if ($name === 'style') {
                $clean = self::cleanStyle($value);
                if ($clean === '') {
                    $el->removeAttribute($attr->name);
                } else {
                    $el->setAttribute('style', $clean);
                }
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer');
            $el->setAttribute('target', '_blank');
        }
    }

    /**
     * Normalise an <a href> to a safe, working URL — or null to drop it.
     *  • http(s):// … kept as-is
     *  • mailto: / tel: … kept (useful, safe)
     *  • schemeless web address (example.com, www.x.com, x.com/path, x.com:8080)
     *    … defaulted to https:// so the link actually works after save
     *  • anything else (javascript:, data:, vbscript:, file:, #frag, /relative, bare word)
     *    … dropped
     */
    private static function normalizeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        if (preg_match('~^(mailto:|tel:)~i', $href)) {
            return $href;
        }
        // A schemeless domain (one or more dot-separated labels + a 2+ letter TLD,
        // with an optional port/path/query/fragment) → default the scheme to https.
        if (preg_match('~^(?:[\w-]+\.)+[a-z]{2,}(?:[:/?#].*)?$~i', $href)) {
            return 'https://'.$href;
        }

        return null;
    }

    /** Keep only whitelisted style properties whose value matches its pattern. */
    private static function cleanStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $val = trim($val);
            if (isset(self::ALLOWED_STYLES[$prop]) && preg_match(self::ALLOWED_STYLES[$prop], $val)) {
                $kept[] = "{$prop}: {$val}";
            }
        }

        return implode('; ', $kept);
    }
}
