<?php

namespace Tests\Unit\Customer;

use PHPUnit\Framework\TestCase;

/**
 * Every /app/... link the Customer module hands the frontend must resolve to a
 * real route.
 *
 * The Customer 360 screens link out to other modules rather than duplicating
 * them (§6 of the document), so the backend emits paths like
 * `/app/projects/12`. Nothing validated those paths: they were written from the
 * module's NAME, and a browser test that only checks each tab renders will
 * never notice, because the link is never followed.
 *
 * Two shipped broken as a result — `/app/meetings` (the shared meeting engine
 * had only module-scoped mounts) and `/app/tickets/:id` (ticket detail lives
 * under /app/helpdesk). Both 404'd for real users.
 *
 * This parses the React router and checks each emitted link against it, so the
 * two files cannot drift apart again.
 */
class CustomerLinkTargetsTest extends TestCase
{
    private const ROUTER = __DIR__.'/../../../../frontend/src/app/routes.jsx';

    /** Directories whose emitted links must resolve. */
    private const SOURCES = [
        __DIR__.'/../../../app/Services/Customer',
        __DIR__.'/../../../app/Http/Controllers/Api/Customer',
    ];

    /**
     * Full paths declared in routes.jsx, mapped to the component they render.
     *
     * <Route path> nests, so the file is walked keeping a stack of parents;
     * a self-closing tag contributes a leaf without opening a level.
     *
     * The element matters as much as the path. `/app/tickets` resolves to a
     * route — but that route is `<ComingSoon name="Tickets" />`, a 🚧
     * placeholder, while the real grid lives at `/app/helpdesk/tickets`. An
     * earlier version of this test only asked "does a route exist" and passed
     * happily on a link that told users the helpdesk was unbuilt.
     *
     * @return array<string,string>  path => element
     */
    private function routes(): array
    {
        $stack = [];
        $out   = [];

        foreach (file(self::ROUTER) as $line) {
            $closes = substr_count($line, '</Route>');
            for ($i = 0; $i < $closes; $i++) {
                array_pop($stack);
            }

            if (! preg_match('/<Route\s+path="([^"]*)"/', $line, $m)) {
                continue;
            }

            $segments = array_values(array_filter([...$stack, $m[1]], fn ($s) => $s !== ''));
            $path     = '/'.implode('/', $segments);

            preg_match('/element=\{<(?:S><)?([A-Za-z0-9_]+)/', $line, $e);
            $element = $e[1] ?? '';

            // A real destination wins over a stub declared at another depth.
            if (! isset($out[$path]) || $out[$path] === 'ComingSoon') {
                $out[$path] = $element;
            }

            if (! str_ends_with(rtrim($line), '/>')) {
                $stack[] = $m[1];
            }
        }

        return $out;
    }

    /** Destinations that exist but are not a real screen. */
    private const STUBS = ['ComingSoon'];

    /**
     * Which route a concrete path matches, treating :params as wildcards.
     *
     * @return string|null  the element rendered, or null if nothing matches
     */
    private function resolves(string $link, array $routes): ?string
    {
        $path = explode('?', $link)[0];
        $want = array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));

        foreach ($routes as $route => $element) {
            $have = array_values(array_filter(explode('/', trim($route, '/')), fn ($s) => $s !== ''));

            if (count($have) !== count($want)) {
                continue;
            }

            foreach ($have as $i => $segment) {
                if (! str_starts_with($segment, ':') && $segment !== $want[$i]) {
                    continue 2;
                }
            }

            return $element;
        }

        return null;
    }

    public function test_every_app_link_the_customer_module_emits_resolves_to_a_route(): void
    {
        $routes = $this->routes();
        $this->assertNotEmpty($routes, 'Could not parse routes.jsx — has it moved?');

        $broken = [];

        foreach (self::SOURCES as $dir) {
            foreach (glob($dir.'/*.php') as $file) {
                foreach (file($file) as $lineNo => $line) {
                    // '/app/projects/'.$client->id  →  probe as /app/projects/1
                    //
                    // The character class must include ? = & : an earlier
                    // version stopped at the query string, so every
                    // '/app/tickets?customer=' link was silently never
                    // extracted and the test passed on links it never saw.
                    if (! preg_match_all("#'(/app/[a-z0-9\-/?=&_]*)'#i", $line, $m)) {
                        continue;
                    }

                    foreach ($m[1] as $link) {
                        $probe   = str_ends_with($link, '/') ? $link.'1' : $link;
                        $element = $this->resolves($probe, $routes);

                        if ($element === null) {
                            $broken[] = sprintf('%s:%d  %s  → no route', basename($file), $lineNo + 1, $link);
                        } elseif (in_array($element, self::STUBS, true)) {
                            $broken[] = sprintf('%s:%d  %s  → %s placeholder, not a real screen',
                                basename($file), $lineNo + 1, $link, $element);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $broken, sprintf(
            "These links do not reach a working screen:\n\n  %s\n",
            implode("\n  ", $broken)
        ));
    }
}
