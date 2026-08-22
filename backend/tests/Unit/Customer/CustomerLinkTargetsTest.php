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
     * Full paths declared in routes.jsx.
     *
     * <Route path> nests, so the file is walked keeping a stack of parents;
     * a self-closing tag contributes a leaf without opening a level.
     *
     * @return array<int,string>
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
            $out[]    = '/'.implode('/', $segments);

            if (! str_ends_with(rtrim($line), '/>')) {
                $stack[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /** Does a concrete path match a route pattern, treating :params as wildcards? */
    private function resolves(string $link, array $routes): bool
    {
        $path = explode('?', $link)[0];
        $want = array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));

        foreach ($routes as $route) {
            $have = array_values(array_filter(explode('/', trim($route, '/')), fn ($s) => $s !== ''));

            if (count($have) !== count($want)) {
                continue;
            }

            foreach ($have as $i => $segment) {
                if (! str_starts_with($segment, ':') && $segment !== $want[$i]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
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
                    if (! preg_match_all("#'(/app/[a-z0-9\-/]*)'#i", $line, $m)) {
                        continue;
                    }

                    foreach ($m[1] as $link) {
                        $probe = str_ends_with($link, '/') ? $link.'1' : $link;

                        if (! $this->resolves($probe, $routes)) {
                            $broken[] = sprintf('%s:%d  %s', basename($file), $lineNo + 1, $link);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $broken, sprintf(
            "These links have no matching route in routes.jsx and will 404:\n\n  %s\n",
            implode("\n  ", $broken)
        ));
    }
}
