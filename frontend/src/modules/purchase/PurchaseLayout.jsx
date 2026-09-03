import {
  LayoutDashboard, ClipboardList, FileSignature, ShoppingBag,
  Truck, Receipt, FileX, Package, Building2, CalendarDays, HardHat,
  ShieldCheck, FileWarning, ClipboardCheck, TrendingUp, FolderLock, Megaphone,
  AlertOctagon, Gauge, RefreshCcw, LogOut, Siren, Award, Rocket, Boxes,
  CheckSquare, ShieldAlert, FileCheck2, UserCheck, HeartPulse, ScanLine, Eye,
  Landmark, SlidersHorizontal, RotateCcw,
} from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Purchase navigation — the same 9-cluster shape TPV uses (§38/§39), replacing
// the old flat 27-tab rail. The rail had grown past the point where it could be
// read: every tab sat in one horizontally-scrolling row, so half of them were
// off-screen and nothing showed which ones belonged together.
//
// Clusters are deliberately IDENTICAL to TPV's wherever the two modules do the
// same job, because they are the same vendor-governance engine on separate
// tables and a person who learns one should not have to relearn the other.
// Procurement is the one cluster with no TPV counterpart — it is Purchase's own
// commercial flow (request → quotation → order → receipt → invoice), which TPV
// has no equivalent of.
//
// Every path below is verified against a real route in app/routes.jsx; a nav
// entry that 404s is worse than a missing one.
const PURCHASE_GROUPS = [
  { label: 'Dashboard', icon: LayoutDashboard, items: [
    { label: 'Control Tower', path: '/app/purchase/dashboard', icon: LayoutDashboard },
  ] },
  { label: 'Vendors', icon: Building2, items: [
    { label: 'Vendor Master',    path: '/app/purchase/vendors',          icon: Building2 },
    { label: 'Prequalification', path: '/app/purchase/prequalification',  icon: CheckSquare },
    { label: 'Risk & Diligence', path: '/app/purchase/risk',             icon: ShieldAlert },
    { label: 'Contracts & WO',   path: '/app/purchase/contracts',        icon: FileCheck2 },
    { label: 'Vendor Items',     path: '/app/purchase/vendor-items',     icon: Package },
  ] },
  // Purchase-only: the commercial pipeline, left → right in the order the
  // documents are actually raised.
  { label: 'Procurement', icon: ShoppingBag, items: [
    { label: 'Requests',       path: '/app/purchase/requests',       icon: ClipboardList },
    { label: 'Quotations',     path: '/app/purchase/quotations',     icon: FileSignature },
    { label: 'Orders',         path: '/app/purchase/orders',         icon: ShoppingBag },
    { label: 'Goods Received', path: '/app/purchase/goods-received', icon: Truck },
    { label: 'Returns',        path: '/app/purchase/order-returns',  icon: RotateCcw },
    { label: 'Invoices',       path: '/app/purchase/invoices',       icon: Receipt },
    { label: 'Debit Notes',    path: '/app/purchase/debit-notes',    icon: FileX },
    { label: 'Catalog',        path: '/app/purchase/catalog',        icon: Package },
  ] },
  { label: 'Meetings', icon: CalendarDays, items: [
    { label: 'All Meetings',      path: '/app/purchase/kickoff',                          icon: CalendarDays },
    { label: 'Decision Register', path: '/app/purchase/meetings/registers/decisions',     icon: ShieldCheck },
    { label: 'Issue Register',    path: '/app/purchase/meetings/registers/issues',        icon: AlertOctagon },
    { label: 'Open Action Items', path: '/app/purchase/meetings/registers/actions',       icon: ClipboardList },
  ] },
  { label: 'Mobilisation', icon: Rocket, items: [
    { label: 'Onboarding',           path: '/app/purchase/onboarding',         icon: Rocket },
    { label: 'Work Packages',        path: '/app/purchase/work-packages',      icon: Boxes },
    { label: 'Onboarding Approvals', path: '/app/purchase/approval-requests',  icon: ShieldCheck },
  ] },
  { label: 'Workforce', icon: UserCheck, items: [
    { label: 'Workforce',       path: '/app/purchase/workforce',  icon: UserCheck },
    { label: 'Workers',         path: '/app/purchase/workers',    icon: HardHat },
    { label: 'Medical Fitness', path: '/app/purchase/medical',    icon: HeartPulse },
    { label: 'Competency',      path: '/app/purchase/competency', icon: Award },
    { label: 'PPE Matrix',      path: '/app/purchase/ppe/matrix', icon: ClipboardList },
    { label: 'Attendance',      path: '/app/purchase/attendance', icon: ClipboardCheck },
  ] },
  { label: 'Work Control', icon: FileCheck2, items: [
    { label: 'Authorization', path: '/app/purchase/work-authorization', icon: ShieldCheck },
    { label: 'Permits',       path: '/app/purchase/permits',            icon: FileCheck2 },
    { label: 'Gate Log',      path: '/app/purchase/gate-log',           icon: ScanLine },
    { label: 'Inspections',   path: '/app/purchase/inspections',        icon: ClipboardCheck },
    { label: 'Observations',  path: '/app/purchase/safety',             icon: Eye },
    { label: 'Registers',     path: '/app/purchase/site-registers',     icon: ClipboardList },
  ] },
  { label: 'Compliance', icon: CheckSquare, items: [
    { label: 'Register',   path: '/app/purchase/compliance-register', icon: ShieldCheck },
    { label: 'Incidents',  path: '/app/purchase/incidents',           icon: Siren },
    { label: 'NCR',        path: '/app/purchase/ncr',                 icon: FileWarning },
    { label: 'CAPA',       path: '/app/purchase/capa',                icon: ClipboardCheck },
    { label: 'Violations', path: '/app/purchase/violations',          icon: AlertOctagon },
  ] },
  { label: 'Performance', icon: TrendingUp, items: [
    { label: 'Performance Index', path: '/app/purchase/vpi',         icon: Gauge },
    { label: 'Renewals',          path: '/app/purchase/renewals',     icon: RefreshCcw },
    { label: 'Offboarding',       path: '/app/purchase/offboarding',  icon: LogOut },
  ] },
  { label: 'Intelligence', icon: Landmark, items: [
    { label: 'Reports',        path: '/app/purchase/reports',         icon: ClipboardList },
    { label: 'Analytics',      path: '/app/purchase/analytics',       icon: TrendingUp },
    { label: 'Governance',     path: '/app/purchase/governance',      icon: ShieldCheck },
    { label: 'Document Vault', path: '/app/purchase/document-vault',  icon: FolderLock },
    { label: 'Communications', path: '/app/purchase/communications',  icon: Megaphone },
    { label: 'Evidence',       path: '/app/purchase/evidence',        icon: FolderLock },
    { label: 'Authority',      path: '/app/purchase/authority-matrix', icon: Landmark },
  ] },
  { label: 'Configuration', icon: SlidersHorizontal, items: [
    { label: 'Settings', path: '/app/purchase/settings', icon: SlidersHorizontal },
  ] },
].filter(g => g.items.length > 0)

export default function PurchaseLayout() {
  return <ModuleShell label="Purchase & Procurement" badge="🛒" groups={PURCHASE_GROUPS} />
}
