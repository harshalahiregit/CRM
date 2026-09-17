import ModuleShell from '@/components/layout/ModuleShell'
import { TPV_GROUPS } from './tpvNav'

// The nav tree itself moved to tpvNav.js, because the sidebar renders it now.
// This used to paint the same ten sections across the top of every TPV page
// while the sidebar listed those same ten a few inches to the left, and then
// opened a *second* strip underneath for whatever section was active — so
// "Vendors" appeared twice before you had clicked anything. ModuleShell keeps
// the breadcrumb, which says where you are without offering a second way to
// get there.
export default function TPVLayout() {
  return <ModuleShell label="Third-Party Vendors" badge="🦺" groups={TPV_GROUPS} />
}
