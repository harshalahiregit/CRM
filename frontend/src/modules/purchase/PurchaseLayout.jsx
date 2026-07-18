import {
  LayoutDashboard, ClipboardList, FileSignature, ShoppingBag,
  Truck, Receipt, FileX, FileText, Package
} from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Ordered to follow the procurement workflow, left → right:
// Dashboard → Requests → Quotations → Orders → Goods Received →
// Invoices → Debit Notes → Contracts → Catalog.
const PURCHASE_NAV = [
  { label: 'Dashboard',      path: '/app/purchase/dashboard',      icon: LayoutDashboard },
  { label: 'Requests',       path: '/app/purchase/requests',       icon: ClipboardList   },
  { label: 'Quotations',     path: '/app/purchase/quotations',     icon: FileSignature   },
  { label: 'Orders',         path: '/app/purchase/orders',         icon: ShoppingBag     },
  { label: 'Goods Received', path: '/app/purchase/goods-received', icon: Truck           },
  { label: 'Invoices',       path: '/app/purchase/invoices',       icon: Receipt         },
  { label: 'Debit Notes',    path: '/app/purchase/debit-notes',    icon: FileX           },
  { label: 'Contracts',      path: '/app/purchase/contracts',      icon: FileText        },
  { label: 'Catalog',        path: '/app/purchase/catalog',        icon: Package         },
]

export default function PurchaseLayout() {
  return <ModuleShell label="Purchase & Procurement" badge="🛒" items={PURCHASE_NAV} />
}
