import {
  LayoutDashboard, ClipboardList, FileSignature, ShoppingBag,
  Truck, Receipt, FileX, FileText, Package, Building2, CalendarDays, HardHat, ShieldCheck, FileWarning, ClipboardCheck, TrendingUp, FolderLock, Megaphone, AlertOctagon, Gauge, RefreshCcw, LogOut, Stamp
} from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Ordered to follow the procurement workflow, left → right:
// Dashboard → Requests → Quotations → Orders → Goods Received →
// Invoices → Debit Notes → Contracts → Catalog.
const PURCHASE_NAV = [
  { label: 'Dashboard',      path: '/app/purchase/dashboard',      icon: LayoutDashboard },
  { label: 'Vendors',        path: '/app/purchase/vendors',        icon: Building2       },
  { label: 'Vendor Onboarding', path: '/app/purchase/onboarding',  icon: ClipboardList   },
  { label: 'Kickoff Meetings', path: '/app/purchase/kickoff',      icon: CalendarDays    },
  { label: 'Workforce',      path: '/app/purchase/workforce',      icon: HardHat         },
  { label: 'Compliance',     path: '/app/purchase/compliance-register', icon: ShieldCheck },
  { label: 'NCR',            path: '/app/purchase/ncr',            icon: FileWarning     },
  { label: 'CAPA',           path: '/app/purchase/capa',           icon: ClipboardCheck  },
  { label: 'Approvals',      path: '/app/purchase/approval-requests', icon: Stamp        },
  { label: 'Inspections',    path: '/app/purchase/inspections',    icon: ClipboardCheck  },
  { label: 'Violations',     path: '/app/purchase/violations',     icon: AlertOctagon    },
  { label: 'Performance',    path: '/app/purchase/vpi',            icon: Gauge           },
  { label: 'Renewals',       path: '/app/purchase/renewals',       icon: RefreshCcw      },
  { label: 'Offboarding',    path: '/app/purchase/offboarding',    icon: LogOut          },
  { label: 'Requests',       path: '/app/purchase/requests',       icon: ClipboardList   },
  { label: 'Quotations',     path: '/app/purchase/quotations',     icon: FileSignature   },
  { label: 'Orders',         path: '/app/purchase/orders',         icon: ShoppingBag     },
  { label: 'Goods Received', path: '/app/purchase/goods-received', icon: Truck           },
  { label: 'Invoices',       path: '/app/purchase/invoices',       icon: Receipt         },
  { label: 'Debit Notes',    path: '/app/purchase/debit-notes',    icon: FileX           },
  { label: 'Contracts',      path: '/app/purchase/contracts',      icon: FileText        },
  { label: 'Catalog',        path: '/app/purchase/catalog',        icon: Package         },
  { label: 'Analytics',      path: '/app/purchase/analytics',      icon: TrendingUp      },
  { label: 'Document Vault', path: '/app/purchase/document-vault', icon: FolderLock      },
  { label: 'Communications', path: '/app/purchase/communications', icon: Megaphone       },
]

export default function PurchaseLayout() {
  return <ModuleShell label="Purchase & Procurement" badge="🛒" items={PURCHASE_NAV} />
}
