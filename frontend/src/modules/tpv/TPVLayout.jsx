import {
  LayoutDashboard, CalendarDays, Rocket,
  UserCheck, CheckSquare, ScanLine, ShieldAlert, Clock, ShieldCheck, HardHat, ClipboardList, Siren, FileCheck2, Eye } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Ordered to follow the vendor lifecycle, left → right:
// Dashboard → Kickoff → Onboarding → Documents → Workforce →
// Compliance → Gate Log → Strikes.
// Vendor master records live at /app/vendors (shared registry), not here —
// this module is the HSSE/onboarding workflow over those vendors.
const TPV_NAV = [
  { label: 'Dashboard',   path: '/app/tpv/dashboard',  icon: LayoutDashboard },
  { label: 'Kickoff',     path: '/app/tpv/kickoff',    icon: CalendarDays    },
  { label: 'Onboarding',  path: '/app/tpv/onboarding', icon: Rocket          },
  { label: 'Temporary',   path: '/app/tpv/temporary',  icon: Clock           },
  { label: 'Approvals',   path: '/app/tpv/approvals',  icon: ShieldCheck     },
  // 'Documents' removed: the module-level route was a ComingSoon placeholder that
  // rendered nothing. Per-vendor documents live on the vendor detail sidebar.
  { label: 'Workforce',   path: '/app/tpv/workforce',  icon: UserCheck       },
  { label: 'PPE',         path: '/app/tpv/ppe',        icon: HardHat         },
  { label: 'PPE Matrix',  path: '/app/tpv/ppe/matrix', icon: ClipboardList   },
  { label: 'Compliance',  path: '/app/tpv/compliance', icon: CheckSquare     },
  { label: 'Gate Log',    path: '/app/tpv/gate-log',   icon: ScanLine        },
  { label: 'Strikes',     path: '/app/tpv/strikes',    icon: ShieldAlert     },
  { label: 'Permits',     path: '/app/tpv/permits',    icon: FileCheck2      },
  { label: 'Observations', path: '/app/tpv/safety-engagement', icon: Eye     },
  { label: 'Registers',   path: '/app/tpv/site-registers', icon: ClipboardList },
  { label: 'Incidents',   path: '/app/tpv/incidents',  icon: Siren           },
  { label: 'Governance',  path: '/app/tpv/governance', icon: ShieldCheck     },
  { label: 'Reports',     path: '/app/tpv/reports',    icon: ClipboardList   },
]

export default function TPVLayout() {
  return <ModuleShell label="Third-Party Vendors" badge="🦺" items={TPV_NAV} />
}
