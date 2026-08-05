<?php

namespace App\Support\Email;

/**
 * Everything a merge-field resolver may need. Passing a context object (rather
 * than a growing argument list) is what lets new merge fields plug in without
 * changing the engine's signatures.
 *
 * `mode` carries the ESCAPING CONTRACT and is the security-critical part: the
 * template body is authored HTML and is trusted (it is sanitised on save), but the
 * VALUES merged into it — customer names, ticket subjects, vendor-supplied text —
 * are untrusted and must be escaped when rendering to HTML.
 */
final class MergeContext
{
    public const MODE_HTML = 'html';

    public const MODE_TEXT = 'text';

    public function __construct(
        public readonly int $tenantId,
        /** Caller-supplied entity data, e.g. ['customer' => ['name' => 'Acme']]. */
        public readonly array $data = [],
        public readonly string $mode = self::MODE_HTML,
    ) {
    }

    /** Dot-path lookup into the supplied data, e.g. 'customer.name'. */
    public function value(string $path, string $default = ''): string
    {
        $value = data_get($this->data, $path);

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value) || is_object($value)) {
            return $default;
        }

        return $value === null || $value === '' ? $default : (string) $value;
    }

    public function isHtml(): bool
    {
        return $this->mode === self::MODE_HTML;
    }

    public function withMode(string $mode): self
    {
        return new self($this->tenantId, $this->data, $mode);
    }
}
