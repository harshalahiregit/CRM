import ModuleShell from '@/components/layout/ModuleShell'
import { Package, Truck, Users, Boxes, Container, Wrench, Search, Disc3,
} from 'lucide-react'

/**
 * Sangoe Transport OS module shell.
 *
 * ── WHERE A NEW TRANSPORT SCREEN MUST BE REGISTERED ──────────────────────
 * Four places, and missing one costs a round of "it still isn't showing".
 * Adding Consignments took three rounds before this list existed.
 *
 *   1. frontend/src/app/routes.jsx            lazy import + <Route>  (SHARED)
 *   2. THIS FILE                              the in-module rail below
 *   3. components/layout/Sidebar.jsx          TRANSPORT_SUB_ITEMS    (SHARED)
 *   4. — free —                               SUBMODULE_SEARCH in that same
 *      file spreads TRANSPORT_SUB_ITEMS, so the "Search modules…" box picks
 *      the entry up with no extra edit.
 *
 * NOT a registration point today: components/CommandPalette.jsx indexes
 * helpdesk, projects, tasks and KB only — no transport record of any kind is
 * reachable from ⌘K. That is Block 5's job (universal search), not a nav fix.
 * modules/registry.js is decorative and gates nothing (ARCHITECTURE-PRIMER §3).
 *
 * Both SHARED files are append-only: add your line, read the diff BEFORE
 * staging, and stage by explicit path — never `git add -A`. A formatter once
 * swept 115 unrelated lines of routes.jsx into a two-line change.
 *
 * Uses the shared ModuleShell, same as Purchase and TPV, so the nav behaves
 * identically across modules.
 *
 * ── ONE FLAT RAIL, NOT TWO CLUSTERS ──────────────────────────────────────
 * `items` rather than `groups`: ModuleShell renders a single row for `items`
 * and a two-level cluster nav for `groups`. A single group with a blank label
 * would still draw the cluster row with an empty button in it, which is worse
 * than not having the row.
 *
 * Ordered as the work is done — an order becomes a trip, a trip carries a
 * consignment — with the two master records the trips consume at the end.
 *
 * Only the screens this release actually has. The clusters Purchase and TPV
 * carry (Compliance, Meetings, Workforce, Performance…) are not stubbed in here:
 * a nav entry that 404s is worse than a missing one, and every later cluster
 * arrives with the ticket that builds it.
 */
const TRANSPORT_ITEMS = [
  // CTD §4's "preferred entry point", first on the rail. `end` so it is only
  // highlighted on /app/transport itself and not on every child route.
  { label: 'Find',             path: '/app/transport',              icon: Search, end: true },
  { label: 'Transport Orders', path: '/app/transport/orders',       icon: Package },
  { label: 'Trips',            path: '/app/transport/trips',        icon: Truck },
  // STOS-CTD §8 — the commercial shipment, distinct from the container.
  { label: 'Consignments',     path: '/app/transport/consignments', icon: Boxes },
  { label: 'Containers',       path: '/app/transport/containers',   icon: Container },
  // Master data — Person 2's Fleet, merged in 2026-09-17 (D-62). These were
  // `/transport/vehicles` and `/transport/drivers`, P1's placeholders under
  // TEAM-CONTRACTS §1a; Fleet's screens replace them at the same rail position
  // so the nav a user learned does not move under them.
  { label: 'Fleet',            path: '/app/transport/fleet',        icon: Truck },
  // T-54 — its own rail entry because a trailer is its own master, not a kind
  // of vehicle. Putting it inside Fleet would say the opposite.
  { label: 'Trailers',         path: '/app/transport/trailers',     icon: Container },
  { label: 'Drivers',          path: '/app/transport/drivers',      icon: Users },
  { label: 'Workshop',         path: '/app/transport/workshop',     icon: Wrench },
  // T-36 — the casing register. Beside Workshop because that is who uses it.
  { label: 'Tyres',            path: '/app/transport/tyres',        icon: Disc3 },
]

export default function TransportLayout() {
  return <ModuleShell label="Transport OS" badge="🚛" items={TRANSPORT_ITEMS} />
}
