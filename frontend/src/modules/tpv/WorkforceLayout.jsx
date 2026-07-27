import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { LayoutDashboard, Users, ScanLine, CalendarCheck, ShieldAlert } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'
import { tpvApi } from '@/services/tpvApi'

// Vendor-scoped Workforce workspace — its own rail, entered from Onboarding
// Step-6 "Start Workforce". Everything nested here is scoped to :vendorId.
// Reuses the shared ModuleShell chrome (same as TPVLayout) so the workspace
// looks and behaves like every other module rail. Frontend-only: no new APIs.
export default function WorkforceLayout() {
  const { vendorId } = useParams()
  const [vendor, setVendor] = useState(null)

  useEffect(() => {
    let alive = true
    tpvApi.vendors.get(vendorId)
      .then(res => { if (alive) setVendor(res?.data ?? res ?? null) })
      .catch(() => {})
    return () => { alive = false }
  }, [vendorId])

  const base = `/app/tpv/workforce/vendor/${vendorId}`
  const items = [
    { label: 'Dashboard',      path: `${base}/dashboard`,  icon: LayoutDashboard },
    { label: 'Workers',        path: `${base}/workers`,    icon: Users },
    { label: 'Gate Log',       path: `${base}/gate-log`,   icon: ScanLine },
    { label: 'Attendance',     path: `${base}/attendance`, icon: CalendarCheck },
    { label: 'Safety Strikes', path: `${base}/strikes`,    icon: ShieldAlert },
  ]

  const name = vendor?.company_name || vendor?.legal_name || 'Vendor'
  return <ModuleShell label={`Workforce · ${name}`} badge="🦺" items={items} />
}
