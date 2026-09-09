<?php

namespace App\Support;

/**
 * What this system is called, and what it looks like, on anything that leaves it.
 *
 * A contract, a set of minutes, an offer letter and an invitation e-mail all go
 * to people outside the company, and every one of them had the framework's
 * default name on it -- config('app.name') resolves to "Laravel" unless APP_NAME
 * is set, and .env is gitignored, so any deploy without one was issuing
 * paperwork branded after a PHP framework.
 *
 * The name now has a real default in config/app.php. This class exists for the
 * other half: the mark. Reading the file and embedding it is not something a
 * Blade template should be doing inline, and every template that tried would
 * read the same file again per render.
 */
final class Brand
{
    /** Files tried in order, relative to public/. */
    private const CANDIDATES = ['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg'];

    private static ?string $cached = null;

    private static bool $looked = false;

    public static function name(): string
    {
        return (string) config('app.name', 'Sangoe OS');
    }

    /**
     * The logo as a data URI, or null when there is none.
     *
     * Embedded rather than linked: a PDF is generated on a server that the
     * reader's machine may never be able to reach, and a mail client that
     * fetches a remote image either blocks it or reports the open to us. Both
     * end in a broken-image icon on a legal document.
     *
     * Resolved once per request -- a 100KB file base64-encoded on every render
     * of a multi-page document is pure waste.
     */
    public static function logoDataUri(): ?string
    {
        if (self::$looked) {
            return self::$cached;
        }
        self::$looked = true;

        foreach (self::CANDIDATES as $name) {
            $path = public_path($name);

            if (is_file($path) && is_readable($path)) {
                $mime = match (pathinfo($name, PATHINFO_EXTENSION)) {
                    'svg'         => 'image/svg+xml',
                    'jpg', 'jpeg' => 'image/jpeg',
                    default       => 'image/png',
                };

                return self::$cached = 'data:'.$mime.';base64,'
                    .base64_encode((string) file_get_contents($path));
            }
        }

        return self::$cached = null;
    }

    /** Test seam: forget what was found, so a fixture can change the file. */
    public static function forget(): void
    {
        self::$looked = false;
        self::$cached = null;
    }
}
