<?php

namespace App\Support;

/**
 * The one place that decides where the React SPA lives.
 *
 * Six call sites were each resolving this differently — `config('app.frontend_url')`,
 * `env('FRONTEND_URL', 'http://localhost:5173')`, `config('cors.frontend_url')`,
 * and combinations — so the same deployment produced links on different hosts
 * depending on which feature sent the email. Activation emails were pointing at
 * port 3000 while the SPA ran on 5173, because the FRAMEWORK's default for
 * `app.frontend_url` is `env('FRONTEND_URL', 'http://localhost:3000')` and this
 * project never set FRONTEND_URL.
 *
 * Resolution order, most explicit first:
 *   1. FRONTEND_URL          — what an operator sets, and what .env.example documents
 *   2. config('app.url')     — the real domain in production, where API and SPA
 *                              usually share an origin
 *   3. http://localhost:5173 — the Vite dev port, and ONLY reachable when neither
 *                              of the above is set, i.e. on a developer machine
 *
 * `config('app.frontend_url')` is deliberately NOT consulted: its framework
 * default is a hard-coded localhost:3000 that silently wins over APP_URL in
 * production, which is the bug this class exists to remove.
 */
class FrontendUrl
{
    private const DEV_FALLBACK = 'http://localhost:5173';

    /** The SPA origin, without a trailing slash. */
    public static function base(): string
    {
        $base = env('FRONTEND_URL') ?: config('app.url') ?: self::DEV_FALLBACK;

        return rtrim($base, '/');
    }

    /**
     * An absolute SPA link.
     *
     * @param  string  $path  leading slash optional — '/vendor-portal/login'
     * @param  array   $query appended as a query string, values URL-encoded
     */
    public static function to(string $path = '', array $query = []): string
    {
        $url = self::base().'/'.ltrim($path, '/');

        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }

    /** True when the resolved base is a localhost dev fallback. */
    public static function isDevFallback(): bool
    {
        return str_contains(self::base(), 'localhost') || str_contains(self::base(), '127.0.0.1');
    }
}
