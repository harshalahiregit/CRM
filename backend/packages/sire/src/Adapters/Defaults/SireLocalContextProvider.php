<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireContextProvider;
use Sire\Dto\SireScreenContext;
use Sire\Support\SireRouteMap;

/**
 * The shipped context provider: path → screen, from a registered route map.
 *
 * WHERE THE MAP COMES FROM, IN ORDER
 *
 *   1. config('sire.route_map') — a host that declares its own screens. This is
 *      the intended path for a real installation: it is data, it lives in the
 *      host's config, and it needs no SIRE code change.
 *   2. SireRouteMap::ROUTES — the map generated from the JavaScript one, which
 *      ships SEEDED rather than observed. Reconcile it with
 *      `node integration/sire-route-audit.mjs <routes-file>`.
 *
 * SPECIFICITY, NOT FILE ORDER
 *
 * /app/projects/:id and /app/projects/:projectId/tasks/:id both match a task
 * URL. Patterns are sorted by segment count and then by how many segments are
 * literal rather than parameters, so the most specific wins regardless of where
 * it sits in the map. That ordering is computed once and cached per request.
 *
 * NO MATCH IS A SUPPORTED OUTCOME
 *
 * An unmapped route returns confidence 'low' with nulls, and the modal shows an
 * editable context. Reporting is never blocked by a route nobody has mapped —
 * a user who cannot report a problem is a far worse outcome than an issue filed
 * against an unknown screen.
 */
class SireLocalContextProvider implements SireContextProvider
{
    /** @var array<int, array<string, mixed>>|null patterns, most specific first */
    private ?array $sorted = null;

    public function resolve(string $path): SireScreenContext
    {
        $segments = $this->segments($path);

        foreach ($this->patterns() as $route) {
            $captured = $this->match($route['segments'], $segments);

            if ($captured === null) {
                continue;
            }

            $entry = $route['entry'];
            $entityParam = $entry['entity_param'] ?? 'id';

            return new SireScreenContext(
                module: $entry['module'] ?? null,
                section: $entry['section'] ?? null,
                screen: $entry['screen'] ?? null,
                route: $entry['pattern'] ?? null,
                entityType: $entry['entity_type'] ?? null,
                entityId: $captured[$entityParam] ?? null,
                entityLabel: null,   // only the host knows a record's label
                confidence: $route['wildcard']
                    ? SireScreenContext::CONFIDENCE_MEDIUM
                    : SireScreenContext::CONFIDENCE_HIGH,
            );
        }

        return new SireScreenContext(route: $path, confidence: SireScreenContext::CONFIDENCE_LOW);
    }

    public function modules(): array
    {
        $labels = (array) (config('sire.module_labels') ?: SireRouteMap::MODULE_LABELS);

        return array_keys($labels);
    }

    public function moduleLabel(string $module): string
    {
        $labels = (array) (config('sire.module_labels') ?: SireRouteMap::MODULE_LABELS);

        return $labels[$module] ?? ucwords(str_replace(['_', '-'], ' ', $module));
    }

    public function routeMap(): array
    {
        $configured = config('sire.route_map');

        return is_array($configured) && $configured !== [] ? $configured : SireRouteMap::ROUTES;
    }

    /**
     * @param  array<int, string> $pattern
     * @param  array<int, string> $actual
     * @return array<string, string>|null captured params, or null when no match
     */
    private function match(array $pattern, array $actual): ?array
    {
        $captured = [];

        foreach ($pattern as $index => $segment) {
            if ($segment === '*') {
                return $captured;   // trailing wildcard swallows the rest
            }

            if (! isset($actual[$index])) {
                return null;
            }

            if (str_starts_with($segment, ':')) {
                $captured[substr($segment, 1)] = $actual[$index];

                continue;
            }

            if ($segment !== $actual[$index]) {
                return null;
            }
        }

        // An exact pattern must consume the whole path, or /app/sales would match
        // /app/sales/leads and report the wrong screen.
        return count($actual) === count($pattern) ? $captured : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function patterns(): array
    {
        if ($this->sorted !== null) {
            return $this->sorted;
        }

        $patterns = [];

        foreach ($this->routeMap() as $entry) {
            if (! isset($entry['pattern'])) {
                continue;   // a malformed host entry must not break resolution
            }

            $segments = $this->segments((string) $entry['pattern']);

            $patterns[] = [
                'entry'    => $entry,
                'segments' => $segments,
                'wildcard' => in_array('*', $segments, true),
                'depth'    => count($segments),
                'literals' => count(array_filter(
                    $segments,
                    static fn (string $s) => $s !== '*' && ! str_starts_with($s, ':'),
                )),
            ];
        }

        usort($patterns, static fn (array $a, array $b) => [$b['depth'], $b['literals']] <=> [$a['depth'], $a['literals']]);

        return $this->sorted = $patterns;
    }

    /** @return array<int, string> */
    private function segments(string $path): array
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;

        return array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
    }
}
