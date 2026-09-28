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

        $base = rtrim((string) $base, '/');

        self::warnIfUnreachable($base);

        return $base;
    }

    /**
     * Say so, once, when this deployment is about to build links nobody outside
     * it can open.
     *
     * The fallback is correct on a developer's machine and catastrophic
     * anywhere else: a vendor invitation goes out carrying
     * http://localhost:5173/auth/login, the Login button lands on the
     * recipient's OWN machine, and the same dead address is printed underneath
     * as the "paste this into your browser" link. From the sender's side
     * everything looks sent. That was reported as two separate faults — a
     * broken button and a localhost link in the footer — and it is one missing
     * environment variable.
     *
     * A warning rather than an exception: this is reached while sending mail,
     * creating vendors and rendering QR codes, and turning a misconfiguration
     * into a 500 on all of them replaces a bad link with a broken product.
     * Callers that must not ship a dead link use publicBase() instead.
     */
    private static function warnIfUnreachable(string $base): void
    {
        static $warned = false;

        if ($warned || ! self::looksLocal($base) || app()->environment(['local', 'testing'])) {
            return;
        }

        $warned = true;

        \Illuminate\Support\Facades\Log::error(
            'FRONTEND_URL is not set, so every link this deployment generates points at localhost. '
            .'Set FRONTEND_URL (or APP_URL) to the public address of the React app and run '
            .'`php artisan config:clear`. Until then, portal invitations, activation emails and '
            .'signing links are unusable for anyone outside this server.',
            ['resolved_base' => $base, 'environment' => app()->environment()],
        );
    }

    private static function looksLocal(string $base): bool
    {
        return str_contains($base, 'localhost') || str_contains($base, '127.0.0.1');
    }

    /**
     * The SPA origin, or null when it is one only this machine can reach.
     *
     * For links that are worse than useless when wrong — a vendor's portal
     * invitation, a signing link, anything mailed to somebody outside the
     * company. The caller decides what to do about it; what it must not do is
     * send the dead link and report success.
     */
    public static function publicBase(): ?string
    {
        $base = self::base();

        return self::looksLocal($base) && ! app()->environment(['local', 'testing']) ? null : $base;
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
        return self::looksLocal(self::base());
    }
}
