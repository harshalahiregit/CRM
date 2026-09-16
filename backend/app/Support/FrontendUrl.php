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
 *   1. config('app.frontend_url') — FRONTEND_URL, read where config:cache can see it
 *   2. env('FRONTEND_URL')        — the same value on a box with no cached config
 *   3. config('app.url')          — the real domain in production, where API and
 *                                   SPA usually share an origin
 *   4. http://localhost:5173      — the Vite dev port, and ONLY reachable when
 *                                   none of the above is set, i.e. on a developer
 *                                   machine
 *
 * Step 1 is the whole point, and it is why `env()` must not be called at a call
 * site. `php artisan config:cache` stops loading .env at all, so `env('X')`
 * returns null in production and every `env('FRONTEND_URL', 'http://localhost:5173')`
 * quietly resolves to localhost — which is how signing links and QR codes went
 * out pointing at a machine the recipient does not have. `app.frontend_url` is
 * defined in config/app.php with NO default, so an unset value falls through to
 * APP_URL instead of pinning localhost the way the framework's own
 * `env('FRONTEND_URL', 'http://localhost:3000')` idiom would.
 */
class FrontendUrl
{
    private const DEV_FALLBACK = 'http://localhost:5173';

    /** The SPA origin, without a trailing slash. */
    public static function base(): string
    {
        $base = config('app.frontend_url')
            ?: env('FRONTEND_URL')
            ?: config('app.url')
            ?: self::DEV_FALLBACK;

        return rtrim((string) $base, '/');
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
