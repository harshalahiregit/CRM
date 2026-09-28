<?php

namespace Sire\Discovery\Detectors;

use Sire\Discovery\Finding;

/**
 * SIRE — authentication guards, and the middleware that enforces them.
 *
 * SIRE needs to know what to put in `auth_middleware`, and the honest answer
 * comes from the host's own guard configuration rather than from a guess.
 *
 * WHY THE DEFAULT GUARD IS ONLY MEDIUM CONFIDENCE
 *
 * `auth.defaults.guard` says what an unqualified `auth` middleware resolves to.
 * It does NOT say which guard the host's API routes actually use, and in most
 * Laravel applications with an SPA those differ — `web` for sessions, `sanctum`
 * for the API. SIRE's routes are API routes, so proposing the default guard is
 * a reasonable start and a poor certainty. The installer asks.
 */
class AuthDetector implements Detector
{
    /** Packages whose presence implies a guard, and what to use with it. */
    private const PACKAGE_GUARDS = [
        'laravel/sanctum'  => 'auth:sanctum',
        'laravel/passport' => 'auth:api',
        'tymon/jwt-auth'   => 'jwt.auth',
        'php-open-source-saver/jwt-auth' => 'jwt.auth',
    ];

    public function __construct(private readonly ComposerReader $composer)
    {
    }

    public function name(): string
    {
        return 'auth';
    }

    public function detect(): array
    {
        $guards = array_keys((array) config('auth.guards', []));
        $default = config('auth.defaults.guard');

        $out = [
            'auth.guards'  => Finding::found($guards, Finding::HIGH, ["config('auth.guards')"]),
            'auth.default_guard' => $default
                ? Finding::found($default, Finding::MEDIUM, ["config('auth.defaults.guard')"],
                    ['the API guard is often different from the default'])
                : Finding::absent(["config('auth.defaults.guard') is unset"]),
        ];

        // A package that is installed is stronger evidence than a guard that is
        // merely configured: Laravel ships a 'sanctum' guard entry in the
        // default config whether or not Sanctum is required.
        $middleware = null;
        $confidence = Finding::LOW;
        $evidence = [];

        foreach (self::PACKAGE_GUARDS as $package => $candidate) {
            if ($this->composer->requires($package)) {
                $middleware = $candidate;
                $confidence = Finding::MEDIUM;
                $evidence[] = "composer.json requires {$package}";
                break;
            }
        }

        if ($middleware === null && $default !== null) {
            $middleware = 'auth:'.$default;
            $evidence[] = "derived from config('auth.defaults.guard')";
        }

        $out['auth.middleware'] = $middleware === null
            ? Finding::absent(['no guard package and no default guard'])
            : Finding::found([$middleware], $confidence, $evidence, [
                'auth:sanctum', 'auth:web', 'auth:api',
            ]);

        $out['auth.packages'] = Finding::found(
            array_values(array_filter(array_keys(self::PACKAGE_GUARDS), fn ($p) => $this->composer->requires($p))),
            Finding::HIGH,
            ['composer.json'],
        );

        return $out;
    }
}
