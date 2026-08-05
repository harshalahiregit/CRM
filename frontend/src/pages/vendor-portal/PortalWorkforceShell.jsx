import { useState, useEffect } from 'react'
import { LayoutDashboard, Users, ScanLine, CalendarCheck, ShieldAlert, HardHat } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'
import { portalApi } from '@/services/portalApi'

/**
 * Vendor Portal workforce rail — mirrors WorkforceLayout but resolves the
 * vendor name from the authenticated user's own portal profile rather than from
 * a :vendorId URL param. URLs stay clean: /vendor-portal/workforce/dashboard etc.
 */
export default function PortalWorkforceShell() {
  const [vendorName, setVendorName] = useState('')

  useEffect(() => {
    let alive = true
    portalApi.me()
      .then(d => { if (alive) setVendorName(d?.vendor?.company_name || '') })
      .catch(() => {})
    return () => { alive = false }
  }, [])

  const base  = '/vendor-portal/workforce'
  const items = [
    { label: 'Dashboard',      path: `${base}/dashboard`,  icon: LayoutDashboard },
    { label: 'Workers',        path: `${base}/workers`,    icon: Users },
    { label: 'PPE',            path: `${base}/ppe`,        icon: HardHat },
    // Gate Log is the security desk's scan record, not a vendor task — it stays
    // in the admin TPV module. The vendor still sees Attendance, which is the
    // same information framed as their own workers' presence.
    { label: 'Attendance',     path: `${base}/attendance`, icon: CalendarCheck },
    { label: 'Safety Strikes', path: `${base}/strikes`,    icon: ShieldAlert },
  ]

  return <ModuleShell label={`Workforce${vendorName ? ` · ${vendorName}` : ''}`} badge="🦺" items={items} />
}
