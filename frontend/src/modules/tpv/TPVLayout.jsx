import {
  LayoutDashboard, CalendarDays, Rocket, FileText,
  UserCheck, CheckSquare, ScanLine, ShieldAlert, Clock, ShieldCheck
} from 'lucide-react'
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
  { label: 'Documents',   path: '/app/tpv/documents',  icon: FileText        },
  { label: 'Workforce',   path: '/app/tpv/workforce',  icon: UserCheck       },
  { label: 'Compliance',  path: '/app/tpv/compliance', icon: CheckSquare     },
  { label: 'Gate Log',    path: '/app/tpv/gate-log',   icon: ScanLine        },
  { label: 'Strikes',     path: '/app/tpv/strikes',    icon: ShieldAlert     },
]

export default function TPVLayout() {
  return <ModuleShell label="Third-Party Vendors" badge="🦺" items={TPV_NAV} />
}
