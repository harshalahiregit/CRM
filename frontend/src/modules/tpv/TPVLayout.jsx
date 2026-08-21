import {
  LayoutDashboard, CalendarDays, Rocket, Building2,
  UserCheck, CheckSquare, ScanLine, ShieldAlert, Clock, ShieldCheck, HardHat, ClipboardList, Siren, FileCheck2, Eye, FolderLock, Landmark, TrendingUp } from 'lucide-react'
import ModuleShell from '@/components/layout/ModuleShell'

// Sangoe TPV navigation — the doc's 9-cluster structure (§38/§39), replacing the
// old flat 20-tab rail. Kickoff is no longer a top tab: it is Meetings → New →
// Type = Kickoff, under Mobilisation.
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
  { label: 'Mobilisation', icon: Rocket, items: [
    { label: 'Meetings',   path: '/app/tpv/kickoff',    icon: CalendarDays },
    { label: 'Onboarding', path: '/app/tpv/onboarding', icon: Rocket },
    { label: 'Approvals',  path: '/app/tpv/approvals',  icon: ShieldCheck },
  ] },
  { label: 'Workforce', icon: UserCheck, items: [
    { label: 'Workforce',  path: '/app/tpv/workforce',  icon: UserCheck },
    { label: 'PPE',        path: '/app/tpv/ppe',        icon: HardHat },
    { label: 'PPE Matrix', path: '/app/tpv/ppe/matrix', icon: ClipboardList },
  ] },
  { label: 'Work Control', icon: FileCheck2, items: [
    { label: 'Permits',      path: '/app/tpv/permits',          icon: FileCheck2 },
    { label: 'Gate Log',     path: '/app/tpv/gate-log',         icon: ScanLine },
    { label: 'Observations', path: '/app/tpv/safety-engagement', icon: Eye },
    { label: 'Registers',    path: '/app/tpv/site-registers',   icon: ClipboardList },
  ] },
  { label: 'Compliance', icon: CheckSquare, items: [
    { label: 'Compliance', path: '/app/tpv/compliance', icon: CheckSquare },
    { label: 'Incidents',  path: '/app/tpv/incidents',  icon: Siren },
    { label: 'Strikes',    path: '/app/tpv/strikes',    icon: ShieldAlert },
  ] },
  { label: 'Performance', icon: TrendingUp, items: [
    { label: 'Performance', path: '/app/tpv/performance', icon: TrendingUp },
  ] },
  { label: 'Intelligence', icon: Landmark, items: [
    { label: 'Reports',    path: '/app/tpv/reports',          icon: ClipboardList },
    { label: 'Governance', path: '/app/tpv/governance',       icon: ShieldCheck },
    { label: 'Evidence',   path: '/app/tpv/evidence',         icon: FolderLock },
    { label: 'Authority',  path: '/app/tpv/authority-matrix', icon: Landmark },
  ] },
  // Ecosystem (Vendor Portal / Settings) renders once Settings lands (Phase 3).
].filter(g => g.items.length > 0)

export default function TPVLayout() {
  return <ModuleShell label="Third-Party Vendors" badge="🦺" groups={TPV_GROUPS} />
}
