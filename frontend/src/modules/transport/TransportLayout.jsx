import ModuleShell from '@/components/layout/ModuleShell'
// `Users` is imported but currently referenced only by the commented-out
// Drivers entry below (D-62). Kept so restoring that entry is one line rather
// than two.
import { Package, Truck, Users, Boxes, Container,
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
  { label: 'Transport Orders', path: '/app/transport/orders',       icon: Package },
  { label: 'Trips',            path: '/app/transport/trips',        icon: Truck },
  // STOS-CTD §8 — the commercial shipment, distinct from the container.
  { label: 'Consignments',     path: '/app/transport/consignments', icon: Boxes },
  { label: 'Containers',       path: '/app/transport/containers',   icon: Container },
  // ── VEHICLES AND DRIVERS ARE HIDDEN, NOT REMOVED — D-62 ────────────────
  // Hidden because Person 2's Fleet module is now the visible one; removed
  // entirely only when allocation has been repointed at it.
  //
  //   { label: 'Vehicles', path: '/app/transport/vehicles', icon: Truck },
  //   { label: 'Drivers',  path: '/app/transport/drivers',  icon: Users },
  //
  // The pages, routes, API, models and services all still exist and still work
  // by URL. Allocation, pre-trip and dispatch read `transport_vehicles` and
  // `transport_drivers` TODAY, so deleting them would break the dispatch chain.
  // Do not uncomment these to "fix" a missing screen, and do not delete the
  // code behind them, until D-62 is closed and allocation reads Fleet.
]

export default function TransportLayout() {
  return <ModuleShell label="Transport OS" badge="🚛" items={TRANSPORT_ITEMS} />
}
