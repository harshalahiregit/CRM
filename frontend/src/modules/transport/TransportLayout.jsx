import ModuleShell from '@/components/layout/ModuleShell'
import { Package, Truck, Users, Boxes } from 'lucide-react'

/**
 * Sangoe Transport OS module shell.
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
  // Master data (SNG-TRN-003 / 004). The pages are Person 2's domain under
  // TM-001 §8; this is only their nav entry, which belongs to the module shell.
  { label: 'Vehicles',         path: '/app/transport/vehicles',     icon: Truck },
  { label: 'Drivers',          path: '/app/transport/drivers',      icon: Users },
]

export default function TransportLayout() {
  return <ModuleShell label="Transport OS" badge="🚛" items={TRANSPORT_ITEMS} />
}
