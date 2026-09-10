<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * A role that is turned away from a shell must have somewhere else to go.
 *
 * Three separate functions decide where a signed-in person lands:
 *
 *   homeFor()      frontend/src/router/ProtectedRoute.jsx  — the bounce target
 *   roleHome()     frontend/src/pages/auth/LoginPage.jsx   — straight after login
 *   RootRedirect() frontend/src/app/routes.jsx             — opening "/"
 *
 * They have to agree, and every role in the /app denylist has to appear in all
 * three with a portal to go to. `vendor` appeared in none of them: it was
 * blocked from /app and fell through to the default, which is /app — so the
 * bounce sent it back to the shell that had just refused it.
 *
 *     /app/dashboard -> blocked -> homeFor('vendor') -> /app/dashboard -> ...
 *
 * That is not a wrong landing page, it is an infinite redirect, and three real
 * users could not sign in at all. Nothing catches it: each function reads
 * perfectly sensibly on its own, the API is fine, and the loop only exists in
 * the relationship between the denylist and the default.
 *
 * A comment in routes.jsx made it look deliberate -- "No 'vendor' branch: a
 * Purchase Vendor is never a User session" -- which is true of purchase_vendor
 * and has nothing to do with `vendor`. Two different roles, conflated.
 */
class EveryBlockedRoleHasSomewhereToGoTest extends TestCase
{
    /** The three places that answer "where does this role belong?". */
    private const RESOLVERS = [
        'homeFor'      => 'frontend/src/router/ProtectedRoute.jsx',
        'roleHome'     => 'frontend/src/pages/auth/LoginPage.jsx',
        'RootRedirect' => 'frontend/src/app/routes.jsx',
    ];

    /** JS line comments, JSDoc bodies and JSX {/* … *&#47;} openers. */
    private function isComment(string $line): bool
    {
        $t = ltrim($line);

        return $t === ''
            || str_starts_with($t, '//')
            || str_starts_with($t, '*')
            || str_starts_with($t, '/*')
            || str_starts_with($t, '{/*');
    }

    private function source(string $relative): string
    {
        $path = base_path('../'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    /** The roles /app turns away, read from the route itself. */
    private function blockedRoles(): array
    {
        $routes = $this->source(self::RESOLVERS['RootRedirect']);

        $this->assertSame(1, preg_match('/blockRoles=\{\[(.*?)\]\}/s', $routes, $m),
            'the /app denylist could not be found — it is the whole premise of this test');

        preg_match_all("/'([a-z_]+)'/", $m[1], $roles);

        $found = $roles[1];
        $this->assertNotEmpty($found);

        return $found;
    }

    public function test_the_denylist_is_what_we_think_it_is(): void
    {
        // Named explicitly so that widening the denylist without giving the new
        // role a home fails here rather than in somebody's browser.
        $this->assertEqualsCanonicalizing(
            ['third_party_vendor', 'vendor', 'company', 'doctor'],
            $this->blockedRoles(),
        );
    }

    public function test_every_blocked_role_is_sent_to_a_portal_by_all_three_resolvers(): void
    {
        $failures = [];

        foreach (self::RESOLVERS as $fn => $file) {
            $src = $this->source($file);

            foreach ($this->blockedRoles() as $role) {
                // The quoted literal, so 'vendor' does not match inside
                // 'third_party_vendor' -- which is exactly how this hid.
                $needle = "'{$role}'";

                $lands = false;
                foreach (explode("\n", $src) as $line) {
                    if (! str_contains($line, $needle)) {
                        continue;
                    }
                    // A branch that names the role and a portal on the same
                    // line is a role that has been given an answer.
                    if (str_contains($line, '-portal/')) {
                        $lands = true;
                        break;
                    }
                }

                if (! $lands) {
                    $failures[] = "{$fn}() in {$file} has no portal for '{$role}'";
                }
            }
        }

        $this->assertSame([], $failures,
            "a role is blocked from /app but sent there anyway, which is an infinite redirect:\n  "
            .implode("\n  ", $failures));
    }

    public function test_no_resolver_sends_a_blocked_role_to_the_app_shell(): void
    {
        foreach (self::RESOLVERS as $fn => $file) {
            foreach (explode("\n", $this->source($file)) as $line) {
                // Comments describing the bug are not the bug. This guard
                // failed on the very paragraph explaining the redirect loop.
                if ($this->isComment($line)) {
                    continue;
                }

                foreach ($this->blockedRoles() as $role) {
                    if (str_contains($line, "'{$role}'") && str_contains($line, '/app/')) {
                        $this->fail("{$fn}() sends '{$role}' to /app, which blocks it: ".trim($line));
                    }
                }
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Both vendor spellings are one destination.
     *
     * They are two names for the same thing and the portal route admits both.
     * If they ever diverge, one of the two sets of people silently stops
     * arriving where their colleagues do.
     */
    public function test_both_vendor_spellings_reach_the_same_portal(): void
    {
        foreach (self::RESOLVERS as $fn => $file) {
            $src = $this->source($file);

            foreach (["'vendor'", "'third_party_vendor'"] as $needle) {
                $line = collect(explode("\n", $src))
                    ->first(fn ($l) => str_contains($l, $needle) && str_contains($l, '-portal/'));

                $this->assertNotNull($line, "{$fn}() gives {$needle} no portal");
                $this->assertStringContainsString('/vendor-portal/dashboard', $line,
                    "{$fn}() sends {$needle} somewhere other than the vendor portal");
            }
        }
    }

    /** The login form has to offer every role somebody actually holds. */
    public function test_the_login_dropdown_offers_the_vendor_role(): void
    {
        $login = $this->source(self::RESOLVERS['roleHome']);

        $this->assertMatchesRegularExpression("/value:\s*'vendor'/", $login,
            'people holding the vendor role have nothing to pick on the login form');
    }
}
