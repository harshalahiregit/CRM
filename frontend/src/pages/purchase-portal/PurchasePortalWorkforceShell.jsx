import { useState, useEffect } from 'react'
import { LayoutDashboard, Users, CalendarCheck, HardHat, AlertOctagon } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * Purchase Vendor Portal workforce rail — the TPV shell, for Purchase.
 *
 * TPV's portal has had a workforce rail since it existed, each entry mounting the
 * same component the admin module uses. Purchase had a single flat "My Workforce"
 * page that re-implemented the worker list and a cut-down 5-step wizard in 541
 * lines, sitting beside a 2,244-line Purchase wizard the portal never used —
 * because the two API clients named the same endpoints differently.
 *
 * The vendor is resolved from the token, so URLs stay clean:
 * /purchase-portal/workforce/workers, not .../vendor/12/workers.
 *
 * One of TPV's five entries is absent, and deliberately rather than by
 * oversight — a nav entry that leads nowhere is worse than none:
 *   · Safety Strikes — Purchase has no strikes engine at all: no table, model,
 *                      service or controller. See PURCHASE-TPV-PARITY.md.
 * The gate log itself stays admin-side on both engines: it is the security
 * desk's scan record, and Attendance is the same information framed as the
 * vendor's own workers' presence.
 */
export default function PurchasePortalWorkforceShell() {
  const [vendorName, setVendorName] = useState('')

  useEffect(() => {
    let alive = true
    purchasePortalApi.me()
      .then(d => { if (alive) setVendorName(d?.vendor?.company_name || d?.company_name || '') })
      .catch(() => {})
    return () => { alive = false }
  }, [])

  const base = '/purchase-portal/workforce'
  const items = [
    { label: 'Dashboard',  path: `${base}/dashboard`,  icon: LayoutDashboard },
    { label: 'Workers',    path: `${base}/workers`,    icon: Users },
    { label: 'PPE',        path: `${base}/ppe`,        icon: HardHat },
    { label: 'Attendance', path: `${base}/attendance`, icon: CalendarCheck },
    // Read-only: a strike is the site's to issue, and the third one ends a
    // worker's access — so a vendor who cannot see the first two is blind.
    { label: 'Strikes',    path: `${base}/strikes`,    icon: AlertOctagon },
  ]

  return <ModuleShell label={`Workforce${vendorName ? ` · ${vendorName}` : ''}`} badge="🦺" items={items} />
}
