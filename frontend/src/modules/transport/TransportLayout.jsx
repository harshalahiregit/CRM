import ModuleShell from '@/components/layout/ModuleShell'
import { LayoutDashboard, Package, Truck, Users, Boxes } from 'lucide-react'

/**
 * Sangoe Transport OS module shell.
 *
 * Uses the shared ModuleShell, same as Purchase and TPV, so the nav behaves
 * identically across modules.
 *
 * Only the two screens this release actually has. The clusters Purchase and TPV
 * carry (Compliance, Meetings, Workforce, Performance…) are not stubbed in here:
 * a nav entry that 404s is worse than a missing one, and every later cluster
 * arrives with the ticket that builds it.
 */
const TRANSPORT_GROUPS = [
  { label: 'Operations', icon: LayoutDashboard, items: [
    { label: 'Transport Orders', path: '/app/transport/orders', icon: Package },
    { label: 'Trips',            path: '/app/transport/trips',  icon: Truck },
    // STOS-CTD §8 — the commercial shipment. Sits with Orders and Trips
    // because it is a step in the same workflow, not a master record.
    { label: 'Consignments',     path: '/app/transport/consignments', icon: Boxes },
  ] },
  // Master data (SNG-TRN-003 / 004). Separate group because these are the
  // resources trips consume, not steps in a trip's own workflow.
  { label: 'Master data', icon: Truck, items: [
    { label: 'Vehicles', path: '/app/transport/vehicles', icon: Truck },
    { label: 'Drivers',  path: '/app/transport/drivers',  icon: Users },
  ] },
]

export default function TransportLayout() {
  return <ModuleShell label="Transport OS" badge="🚛" groups={TRANSPORT_GROUPS} />
}
