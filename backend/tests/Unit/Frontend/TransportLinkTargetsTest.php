<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Every /app/transport/... link must point at a route that exists.
 *
 * ── THE BUG THIS EXISTS FOR ──────────────────────────────────────────────
 * The trip detail page linked to `/app/transport/consignments/<id>`. That route
 * has never existed: consignments deliberately have no detail ROUTE, because
 * their detail is a Drawer on the list. Clicking the card showed "Page not
 * found". Nothing failed at build time, no test went red, and nothing in the
 * PHP suite could have noticed — it is a string in a JSX file that only becomes
 * a URL when a user clicks it.
 *
 * It was found by the owner, in a demo. That is the expensive way.
 *
 * ── WHY A PHP TEST FOR A REACT PROBLEM ───────────────────────────────────
 * The same reason BannedPatternsTest is one: this suite is what runs. A guard
 * nobody executes is a comment. It reads the files as text, which is all that
 * is needed — the targets and the route table are both literals.
 *
 * ── HOW IT MATCHES ───────────────────────────────────────────────────────
 * Dynamic segments are normalised on both sides, so `/app/transport/orders/
 * ${o.id}` matches the registered `orders/:id`. A query string is dropped
 * before matching, because `?open=<id>` is a parameter of the list route, not
 * a different route — that is exactly how the bug above was fixed.
 *
 * ── THE GUARD IS ITSELF GUARDED ──────────────────────────────────────────
 * Two tests assert the scanner found a sensible number of targets and of
 * registered routes. Without them a regex that silently stopped matching would
 * leave this passing forever while checking nothing, which is the failure mode
 * of every test that greps.
 */
class TransportLinkTargetsTest extends TestCase
{
    private const FRONTEND = __DIR__.'/../../../../frontend/src';

    /**
     * Files that may contain a transport link.
     *
     * The module itself, plus the two SHARED navigation files that carry
     * transport entries — a dead link in the sidebar is the same bug in a more
     * visible place.
     */
    private const SCANNED = [
        'modules/transport',
        'components/layout/Sidebar.jsx',
        'components/layout/sidebarSection.js',
    ];

    /** @return list<string> every `/app/transport/...` registered in routes.jsx */
    private function registeredPaths(): array
    {
        $routes = file_get_contents(self::FRONTEND.'/app/routes.jsx');

        // The transport block only: <Route path="transport" ...> up to its close.
        $start = strpos($routes, '<Route path="transport"');
        $this->assertNotFalse($start, 'routes.jsx no longer declares a transport block — this test is reading the wrong file');
        $block = substr($routes, $start, strpos($routes, '</Route>', $start) - $start);

        preg_match_all('/<Route\s+path="([^"]+)"/', $block, $m);

        $paths = ['/app/transport'];
        foreach ($m[1] as $path) {
            if ($path === 'transport') {
                continue;
            }
            $paths[] = '/app/transport/'.$path;
        }

        return array_values(array_unique($paths));
    }

    /** @return list<array{file:string,line:int,target:string,raw:string}> */
    private function linkTargets(): array
    {
        $files = [];

        foreach (self::SCANNED as $entry) {
            $path = self::FRONTEND.'/'.$entry;

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(jsx|js)$/', $f->getFilename())) {
                    $files[] = $f->getPathname();
                }
            }
        }

        $found = [];

        foreach ($files as $file) {
            foreach (file($file) as $i => $line) {
                // Everything up to the closing quote/backtick of the string.
                if (! preg_match_all('#/app/transport[^\'"`\s\)]*#', $line, $m)) {
                    continue;
                }

                foreach ($m[0] as $raw) {
                    $found[] = [
                        'file'   => str_replace(self::FRONTEND.'/', '', $file),
                        'line'   => $i + 1,
                        'raw'    => $raw,
                        'target' => $this->normalise($raw),
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * `/app/transport/orders/${o.id}` -> `/app/transport/orders/:id`
     * `/app/transport/consignments?open=${id}` -> `/app/transport/consignments`
     */
    private function normalise(string $raw): string
    {
        // A query string selects state within a route, not a different route.
        $path = explode('?', $raw)[0];

        // Any interpolated segment is a parameter, whatever it is called.
        $path = preg_replace('/\$\{[^}]*\}/', ':id', $path);

        return rtrim($path, '/');
    }

    public function test_every_transport_link_points_at_a_registered_route(): void
    {
        $registered = $this->registeredPaths();
        $broken     = [];

        foreach ($this->linkTargets() as $t) {
            if (! in_array($t['target'], $registered, true)) {
                $broken[] = sprintf(
                    '%s:%d  %s  (resolves to %s)',
                    $t['file'], $t['line'], $t['raw'], $t['target'],
                );
            }
        }

        $this->assertSame([], $broken, sprintf(
            "These transport links point at routes that do not exist:\n\n  %s\n\n"
            ."Registered transport routes are:\n  %s\n\n"
            ."Either register the route in app/routes.jsx, or link somewhere that exists. "
            ."If the destination is a drawer rather than a page, deep-link into its list "
            ."(see /app/transport/consignments?open=<id>).",
            implode("\n  ", $broken),
            implode("\n  ", $registered),
        ));
    }

    /* ══════════ guarding the guard ══════════ */

    public function test_the_scanner_actually_finds_links(): void
    {
        // If a regex change made this collect nothing, the test above would pass
        // while checking nothing at all.
        $targets = $this->linkTargets();

        $this->assertGreaterThanOrEqual(15, count($targets),
            'the link scanner found almost nothing — it has stopped matching, not the module');

        $files = array_unique(array_column($targets, 'file'));
        $this->assertGreaterThanOrEqual(5, count($files),
            'links were found in suspiciously few files');
    }

    public function test_the_route_table_is_actually_being_read(): void
    {
        $registered = $this->registeredPaths();

        $this->assertGreaterThanOrEqual(8, count($registered),
            'routes.jsx parsing returned almost nothing — the block shape has changed');

        // Spot-check both shapes: a list route and a dynamic one.
        $this->assertContains('/app/transport/consignments', $registered);
        $this->assertContains('/app/transport/trips/:id', $registered);
    }

    public function test_dynamic_segments_normalise_the_same_on_both_sides(): void
    {
        // The matcher's one piece of real logic, tested directly rather than
        // only through the sweep.
        $this->assertSame('/app/transport/orders/:id', $this->normalise('/app/transport/orders/${o.id}'));
        $this->assertSame('/app/transport/trips/:id', $this->normalise('/app/transport/trips/${trip.order.id}'));
        $this->assertSame('/app/transport/consignments', $this->normalise('/app/transport/consignments?open=${id}'));
        $this->assertSame('/app/transport/consignments', $this->normalise('/app/transport/consignments/'));
    }
}
