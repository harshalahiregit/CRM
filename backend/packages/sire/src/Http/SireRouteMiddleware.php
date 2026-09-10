<?php

namespace Sire\Http;

use Sire\Http\Middleware\SireDenyAll;
use Sire\Http\Middleware\SireRequireEngineeringLogin;

/**
 * SIRE — composes the middleware stack for every SIRE route, from config.
 *
 * WHY THIS IS A CLASS AND NOT A LITERAL ARRAY IN THE ROUTE FILE
 *
 * Three reasons, and the third is the important one.
 *
 * 1. The order matters and is easy to get wrong by hand. The group must run
 *    before authentication, authentication before the login-type gate.
 * 2. It is one place to read when asking "what actually protects SIRE".
 * 3. IT FAILS CLOSED. A host that installs SIRE and never sets
 *    `auth_middleware` — an empty array, a null, a typo — would otherwise get an
 *    engineering issue tracker on the open internet. Instead it gets a stack
 *    that denies every request and says why.
 *
 * That third case is not hypothetical: `auth_middleware` is exactly the sort of
 * key someone empties while debugging and forgets to restore.
 */
final class SireRouteMiddleware
{
    /**
     * The full stack, outermost first.
     *
     * @return array<int, string>
     */
    public static function stack(): array
    {
        $group = config('sire.host.middleware_group');
        $auth = self::normalise(config('sire.host.auth_middleware'));
        $role = self::normalise(config('sire.host.role_middleware'));

        if ($auth === []) {
            // Deny everything, loudly, rather than serve the backlog to anyone
            // who finds the URL.
            return array_values(array_filter([
                is_string($group) && $group !== '' ? $group : null,
                SireDenyAll::class,
            ]));
        }

        return array_values(array_filter(array_merge(
            [is_string($group) && $group !== '' ? $group : null],
            $auth,
            $role,
            // Always last, and always present: authentication proves WHO, this
            // decides whether that kind of account may see SIRE at all. It is
            // not optional and not configurable, because "customers can read the
            // defect backlog" must not be reachable through configuration.
            [SireRequireEngineeringLogin::class],
        )));
    }

    /**
     * Accepts a string or an array, ignores blanks.
     *
     * `'auth:sanctum'` and `['auth:sanctum']` are both things people write, and
     * rejecting one of them would be a support ticket rather than a lesson.
     *
     * @return array<int, string>
     */
    private static function normalise(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($m) => is_string($m) ? trim($m) : null, $value),
            static fn (?string $m) => $m !== null && $m !== '',
        ));
    }
}
