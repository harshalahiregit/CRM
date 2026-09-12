<?php

namespace Tests\Feature\Transport;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A tripwire for D-46, not a style check.
 *
 * ── WHAT D-46 IS ─────────────────────────────────────────────────────────
 * TransportPermission's matrix grants ROLE_CUSTOMER => SCOPE_OWN on
 * ORDER_VIEW and TRIP_VIEW, and ROLE_SUPPLIER => SCOPE_ASSIGNED on TRIP_VIEW.
 * None of those narrow anything. TransportPermissionService::scope() says so
 * outright — "'own' and 'assigned' narrowing arrives with the tickets that own
 * it" — and the returned scope string is never used to filter a query anywhere
 * in the codebase. A holder who reached the endpoint would receive the tenant's
 * entire list, not their own rows.
 *
 * ── WHY THERE IS NO LEAK TODAY ───────────────────────────────────────────
 * Every transport route sits inside one `role:admin,staff` group. A user with
 * role='client' or 'vendor' is refused at that coarse door before any
 * permission is evaluated, so the unenforced grants can never be reached.
 *
 * That single group is therefore the only thing standing between a declarative
 * grant and a cross-customer disclosure inside a tenant — and STOS-CTD's
 * customer-facing Digital Passport is exactly the route that would remove it.
 *
 * ── WHY THIS IS A TEST AND NOT A PARAGRAPH IN THE REGISTER ───────────────
 * A register entry is read by whoever goes looking. A red suite is read by
 * whoever breaks it. The risk here lands on a developer who has no reason to
 * suspect it: they add a customer-facing endpoint, see a declared SCOPE_OWN
 * grant, and reasonably assume it narrows something.
 *
 * This test fails the moment that route is registered, and says what to do.
 */
class TransportRouteExposureTest extends TestCase
{
    /** The coarse door. Removing it is what makes D-46 exploitable. */
    private const REQUIRED_GATE = 'role:admin,staff';

    /**
     * Routes that legitimately carry no permission key, each with its reason.
     *
     * This is an allow-list of ONE and it should stay small. A route added here
     * is a route any staff user may call regardless of their STOS role, so the
     * reason has to survive being read aloud in a review.
     */
    private const NO_PERMISSION_KEY_REQUIRED = [
        // The capability endpoint. It answers "which permissions do I hold?",
        // so gating it on holding a permission is circular — the frontend calls
        // it precisely to discover which buttons to render. It returns only the
        // caller's OWN grants and reads no business data.
        'api/transport/permissions',
    ];

    /** @return array<int,\Illuminate\Routing\Route> */
    private function transportRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/transport'))
            ->values()
            ->all();
    }

    public function test_there_are_transport_routes_to_check(): void
    {
        // Guards the guard: a filter that silently matched nothing would make
        // every assertion below vacuously true.
        $this->assertGreaterThan(
            30, count($this->transportRoutes()),
            'the route filter matched almost nothing — this test would pass vacuously',
        );
    }

    public function test_every_transport_route_is_behind_the_staff_only_gate(): void
    {
        $exposed = [];

        foreach ($this->transportRoutes() as $route) {
            if (! in_array(self::REQUIRED_GATE, $route->gatherMiddleware(), true)) {
                $exposed[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $exposed, $this->explain($exposed));
    }

    public function test_every_transport_route_requires_authentication(): void
    {
        $open = [];

        foreach ($this->transportRoutes() as $route) {
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                $open[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $open, 'these transport routes accept unauthenticated requests: '.implode(', ', $open));
    }

    public function test_every_transport_route_carries_a_permission_key(): void
    {
        // auth + role is the door; the permission key is the lock. A route with
        // no key is open to every staff user regardless of their STOS role.
        $unkeyed = [];

        foreach ($this->transportRoutes() as $route) {
            $hasKey = collect($route->gatherMiddleware())
                ->contains(fn ($m) => str_starts_with($m, 'transport.permission:'));

            if (! $hasKey && ! in_array($route->uri(), self::NO_PERMISSION_KEY_REQUIRED, true)) {
                $unkeyed[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [], $unkeyed,
            'these transport routes carry no transport.permission key, so any staff user may call them: '
            .implode(', ', $unkeyed),
        );
    }

    public function test_the_permission_key_exemption_list_stays_minimal(): void
    {
        // Every entry here is a route any staff user may call. If this list
        // grows, the growth should be argued for, not absorbed.
        $this->assertCount(
            1, self::NO_PERMISSION_KEY_REQUIRED,
            'a route was exempted from carrying a permission key — say why in review',
        );

        // And the exemption must still be a real route, so a rename cannot
        // leave a dead entry silently excusing something else later.
        $uris = array_map(fn ($r) => $r->uri(), $this->transportRoutes());

        foreach (self::NO_PERMISSION_KEY_REQUIRED as $exempt) {
            $this->assertContains($exempt, $uris, "{$exempt} is exempted but no longer exists");
        }
    }

    /** @param array<int,string> $exposed */
    private function explain(array $exposed): string
    {
        return <<<TEXT

            ══ D-46 TRIPWIRE ══════════════════════════════════════════════════

            These transport routes are NOT behind `role:admin,staff`:

              ─ {$this->bullets($exposed)}

            STOP before shipping this.

            TransportPermission grants ROLE_CUSTOMER => SCOPE_OWN on ORDER_VIEW
            and TRIP_VIEW, and ROLE_SUPPLIER => SCOPE_ASSIGNED on TRIP_VIEW.
            THOSE SCOPES NARROW NOTHING. TransportPermissionService::scope()
            states that "'own' and 'assigned' narrowing arrives with the tickets
            that own it", and the scope string is never used to filter a query.

            A customer or supplier reaching a transport endpoint therefore
            receives EVERY row in the tenant, not their own — cross-customer
            disclosure inside a tenant.

            Until now the only thing preventing that was the coarse
            `role:admin,staff` group at routes/transport.php. This route leaves it.

            What to do:
              1. Implement the SCOPE_OWN / SCOPE_ASSIGNED narrowing in the
                 repository layer, so a scoped grant actually filters.
              2. Add a test proving a customer sees ONLY their own rows.
              3. Then update this tripwire's allow-list, with the review that
                 change deserves.

            Do not simply delete this assertion. See docs/transport/registry-defects.md,
            D-46 (Critical, owner: Security).

            ═══════════════════════════════════════════════════════════════════

            TEXT;
    }

    /** @param array<int,string> $items */
    private function bullets(array $items): string
    {
        return implode("\n              ─ ", $items);
    }
}
