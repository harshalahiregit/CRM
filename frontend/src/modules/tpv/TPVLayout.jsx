import {
  LayoutDashboard, CalendarDays, Rocket, Building2, Boxes, GraduationCap, FileWarning, ClipboardCheck, AlertOctagon, RefreshCcw, LogOut,
  UserCheck, CheckSquare, ScanLine, ShieldAlert, Clock, ShieldCheck, HardHat, ClipboardList, Siren, FileCheck2, Eye, FolderLock, Landmark, TrendingUp, Gauge, Megaphone, SlidersHorizontal } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Sangoe TPV navigation — the doc's 9-cluster structure (§38/§39), replacing the
// old flat 20-tab rail. Kickoff is no longer a top tab: it is Meetings → New →
// Type = Kickoff, and Meetings is its own cluster (Meeting.docx's nav change).
//
// Only pages that exist today are listed; each cluster grows as later build-plan
// phases land (Prequalification, Risk, Contracts, Work Packages, Competency,
// Medical, NCR, Inspections, Renewal, Offboarding, Documents, Communications,
// Settings). Empty clusters are not rendered.
const TPV_GROUPS = [
  { label: 'Dashboard', icon: LayoutDashboard, items: [
    { label: 'Control Tower', path: '/app/tpv/dashboard', icon: LayoutDashboard },
  ] },
  { label: 'Vendors', icon: Building2, items: [
    { label: 'Vendor Master',    path: '/app/tpv/vendors',         icon: Building2 },
    { label: 'Prequalification', path: '/app/tpv/prequalification', icon: CheckSquare },
    { label: 'Risk & Diligence', path: '/app/tpv/risk',            icon: ShieldAlert },
    { label: 'Contracts & WO',   path: '/app/tpv/contracts',       icon: FileCheck2 },
    { label: 'Temporary',        path: '/app/tpv/temporary',       icon: Clock },
  ] },
  // Meeting.docx's nav: "Replace Kickoff Meeting with Meetings", and inside it
  // All Meetings / Calendar / Pending MOM / Open Action Items / Types &
  // Templates. The list itself carries the quick-view chips and the calendar
  // toggle; the three registers are their own screens because they read across
  // every meeting rather than filtering one list.
  { label: 'Meetings', icon: CalendarDays, items: [
    { label: 'All Meetings',      path: '/app/tpv/kickoff',                              icon: CalendarDays },
    { label: 'Decision Register', path: '/app/tpv/meetings/registers/decisions',         icon: ShieldCheck },
    { label: 'Issue Register',    path: '/app/tpv/meetings/registers/issues',            icon: AlertOctagon },
    { label: 'Open Action Items', path: '/app/tpv/meetings/registers/actions',           icon: ClipboardList },
  ] },
  { label: 'Mobilisation', icon: Rocket, items: [
    { label: 'Onboarding',    path: '/app/tpv/onboarding',    icon: Rocket },
    { label: 'Work Packages', path: '/app/tpv/work-packages', icon: Boxes },
    { label: 'Onboarding Approvals', path: '/app/tpv/approvals', icon: ShieldCheck },
    { label: 'Approval Register',    path: '/app/tpv/approval-register', icon: CheckSquare },
  ] },
  { label: 'Workforce', icon: UserCheck, items: [
    { label: 'Workforce',   path: '/app/tpv/workforce',  icon: UserCheck },
    { label: 'Competency',  path: '/app/tpv/competency',  icon: GraduationCap },
    { label: 'PPE',         path: '/app/tpv/ppe',        icon: HardHat },
    { label: 'PPE Matrix',  path: '/app/tpv/ppe/matrix', icon: ClipboardList },
  ] },
  { label: 'Work Control', icon: FileCheck2, items: [
    { label: 'Authorization', path: '/app/tpv/work-authorization', icon: ShieldCheck },
    { label: 'Permits',      path: '/app/tpv/permits',          icon: FileCheck2 },
    { label: 'Gate Log',     path: '/app/tpv/gate-log',         icon: ScanLine },
    { label: 'Inspections',  path: '/app/tpv/inspections',      icon: ClipboardCheck },
    { label: 'Observations', path: '/app/tpv/safety-engagement', icon: Eye },
    { label: 'Registers',    path: '/app/tpv/site-registers',   icon: ClipboardList },
  ] },
  { label: 'Compliance', icon: CheckSquare, items: [
    { label: 'Compliance', path: '/app/tpv/compliance', icon: CheckSquare },
    { label: 'Register',   path: '/app/tpv/compliance-register', icon: ShieldCheck },
    { label: 'Incidents',  path: '/app/tpv/incidents',  icon: Siren },
    { label: 'NCR',        path: '/app/tpv/ncr',        icon: FileWarning },
    { label: 'CAPA',       path: '/app/tpv/capa',       icon: ClipboardCheck },
    { label: 'Violations', path: '/app/tpv/violations', icon: AlertOctagon },
    { label: 'Strikes',    path: '/app/tpv/strikes',    icon: ShieldAlert },
  ] },
  { label: 'Performance', icon: TrendingUp, items: [
    { label: 'Performance', path: '/app/tpv/performance', icon: TrendingUp },
    { label: 'Performance Index', path: '/app/tpv/vpi',   icon: Gauge },
    { label: 'Renewal',     path: '/app/tpv/renewals',    icon: RefreshCcw },
    { label: 'Offboarding', path: '/app/tpv/offboarding', icon: LogOut },
  ] },
  { label: 'Intelligence', icon: Landmark, items: [
    { label: 'Reports',    path: '/app/tpv/reports',          icon: ClipboardList },
    { label: 'Analytics',  path: '/app/tpv/analytics',        icon: TrendingUp },
    { label: 'Governance', path: '/app/tpv/governance',       icon: ShieldCheck },
    { label: 'Document Vault', path: '/app/tpv/document-vault', icon: FolderLock },
    { label: 'Communications', path: '/app/tpv/communications', icon: Megaphone },
    { label: 'Evidence',   path: '/app/tpv/evidence',         icon: FolderLock },
    { label: 'Authority',  path: '/app/tpv/authority-matrix', icon: Landmark },
  ] },
  { label: 'Configuration', icon: SlidersHorizontal, items: [
    { label: 'Settings', path: '/app/tpv/settings', icon: SlidersHorizontal },
  ] },
].filter(g => g.items.length > 0)

export default function TPVLayout() {
  return <ModuleShell label="Third-Party Vendors" badge="🦺" groups={TPV_GROUPS} />
}
