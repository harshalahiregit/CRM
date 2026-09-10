<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Models\ReportContext;
use Sire\Support\SireContextSchema;
use Illuminate\Support\Str;

/**
 * SIRE — normalise, re-redact and persist Report Issue context.
 *
 * The client already redacts. This redacts again. Client-side redaction protects
 * a token from travelling; server-side redaction is what actually keeps it out of
 * the database, because the client is not a trust boundary — anyone can POST to
 * /api/sire/reports with a hand-written body.
 */
class SireContextService
{
    /**
     * @param  array  $raw  the untrusted `context` object from the request
     */
    public function capture(Report $report, array $raw, int $tenantId): ?ReportContext
    {
        $clean = $this->sanitise($raw);
        if ($clean === []) {
            return null;
        }

        // Placement is promoted onto the report itself so the register can filter
        // and group by it. Diagnostics stay in the child row so the hot register
        // query never reads them.
        $report->forceFill(array_filter([
            'module'             => $clean['module']  ?? null,
            'section'            => $clean['section'] ?? null,
            'screen'             => $clean['screen']  ?? null,
            'route'              => $clean['route']   ?? null,
            'context_confidence' => $clean['context_confidence'] ?? null,
        ], static fn ($v) => $v !== null));

        // The entity the issue is about reuses the existing logical link — no new
        // columns, and it lines up with every other cross-module reference.
        if (! empty($clean['entity_type']) && ! empty($clean['entity_id']) && ! $report->related_type) {
            $report->related_type = $clean['entity_type'];
            $report->related_id   = $clean['entity_id'];
        }

        $report->save();

        return ReportContext::create([
            // tenant_id is taken from the report, never from the payload. In a
            // codebase where tenancy is opt-in per query, a client-supplied
            // tenant id is a leak waiting for a missing forTenant().
            'tenant_id'          => $tenantId,
            'report_id'          => $report->id,
            'url'                => $clean['url'] ?? null,
            'browser'            => $clean['browser'] ?? null,
            'os'                 => $clean['os'] ?? null,
            'viewport'           => $clean['viewport'] ?? null,
            'locale'             => $clean['locale'] ?? null,
            'timezone'           => $clean['timezone'] ?? null,
            'app_version'        => $clean['app_version'] ?? null,
            'session_ref'        => $clean['session_ref'] ?? null,
            'context_source'     => $clean['context_source'] ?? null,
            'context_confidence' => $clean['context_confidence'] ?? null,
            'entity_source'      => $clean['entity_source'] ?? null,
            'captured_at'        => $clean['captured_at'] ?? now(),
            'failed_requests'    => $clean['failed_requests'] ?? null,
            'page_context'       => $clean['page_context'] ?? null,
        ]);
    }

    /** Allowlist, truncate, re-redact. Everything unlisted is dropped silently. */
    public function sanitise(array $raw): array
    {
        $out = [];

        foreach (SireContextSchema::SCALARS as $key => $maxLength) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $value = Str::limit((string) $value, $maxLength, '');
            if ($value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        if (isset($out['url'])) {
            $out['url'] = $this->redactUrl($out['url']);
        }
        if (isset($out['context_confidence']) && ! in_array($out['context_confidence'], SireContextSchema::CONFIDENCE, true)) {
            unset($out['context_confidence']);
        }
        foreach (['context_source', 'entity_source'] as $key) {
            if (isset($out[$key]) && ! in_array($out[$key], SireContextSchema::SOURCES, true)) {
                unset($out[$key]);
            }
        }

        $failed = $this->sanitiseFailedRequests($raw['failed_requests'] ?? null);
        if ($failed !== []) {
            $out['failed_requests'] = $failed;
        }

        $page = $this->sanitisePageContext($raw['page_context'] ?? null);
        if ($page !== []) {
            $out['page_context'] = $page;
        }

        return $out;
    }

    protected function sanitiseFailedRequests($input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach (array_slice($input, 0, SireContextSchema::MAX_FAILED_REQUESTS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $method = strtoupper((string) ($entry['method'] ?? ''));
            if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }

            // Path only. A query string is dropped outright, never redacted —
            // there is no diagnostic value in it that justifies the risk.
            $path = (string) ($entry['path'] ?? '');
            $path = Str::limit(explode('?', $path)[0], 255, '');
            if ($path === '') {
                continue;
            }

            $out[] = [
                'method'          => $method,
                'path'            => $this->redactPath($path),
                'status'          => (int) ($entry['status'] ?? 0),
                'occurred_at'     => Str::limit((string) ($entry['occurred_at'] ?? ''), 40, '') ?: null,
                'correlation_ref' => Str::limit((string) ($entry['correlation_ref'] ?? ''), 64, '') ?: null,
            ];
        }

        return $out;
    }

    protected function sanitisePageContext($input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $key => $value) {
            if (count($out) >= SireContextSchema::MAX_PAGE_CONTEXT_KEYS) {
                break;
            }
            if (! is_string($key) || preg_match(SireContextSchema::FORBIDDEN_KEY_PATTERN, $key)) {
                continue;
            }
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $value = (string) $value;
            if (mb_strlen($value) > SireContextSchema::MAX_PAGE_VALUE_LENGTH) {
                continue;
            }
            $out[Str::limit($key, 64, '')] = $value;
        }

        return $out;
    }

    /**
     * PHP mirror of frontend/src/lib/sire/redact.js. Kept deliberately in step —
     * if you change the heuristic in one, change it in the other.
     */
    public function redactUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $this->redactPath(explode('?', $url)[0]);
        }

        $origin = '';
        if (! empty($parts['scheme']) && ! empty($parts['host'])) {
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        $path  = $this->redactPath($parts['path'] ?? '');
        $query = $this->redactQuery($parts['query'] ?? '');

        return $origin.$path.$query; // fragment dropped entirely
    }

    public function redactPath(string $path): string
    {
        $segments = array_map(function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }
            // '{id}-{email_token}' — the public ticket shape. Keep the id.
            if (preg_match('/^(\d+)-([A-Za-z0-9_-]{16,})$/', $segment, $m)) {
                return $m[1].'-:token';
            }

            return $this->looksLikeSecret($segment) ? ':token' : $segment;
        }, explode('/', $path));

        return implode('/', $segments);
    }

    protected function redactQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $out = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $lower = strtolower($key);

            $sensitive = false;
            foreach (SireContextSchema::SENSITIVE_PARAM_HINTS as $hint) {
                if (str_contains($lower, $hint)) {
                    $sensitive = true;
                    break;
                }
            }

            $out[] = ($sensitive || $this->looksLikeSecret((string) $value))
                ? $key.'=[redacted]'
                : ($value === null ? $key : $key.'='.$value);
        }

        return $out ? '?'.implode('&', $out) : '';
    }

    /**
     * Long, opaque-charactered, not a plain number, and either very long or
     * digit-dense. The density test separates a 20-hex widget key from a human
     * slug like 'annual-safety-review-2026'.
     */
    protected function looksLikeSecret(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $length = strlen($value);
        if ($length < 16) {
            return false;
        }
        if (! preg_match('/^[A-Za-z0-9_\-.]+$/', $value)) {
            return false;
        }
        if (ctype_digit($value)) {
            return false;
        }
        if ($length >= 32) {
            return true;
        }

        return (preg_match_all('/\d/', $value) / $length) >= 0.25;
    }
}
