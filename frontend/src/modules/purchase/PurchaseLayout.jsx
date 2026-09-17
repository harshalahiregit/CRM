import ModuleShell from '@/components/layout/ModuleShell'
import { PURCHASE_GROUPS } from './purchaseNav'

// The nav tree itself moved to purchaseNav.js, because the sidebar renders it
// now — see the note there, and the matching one in TPVLayout.
export default function PurchaseLayout() {
  return <ModuleShell label="Purchase & Procurement" badge="🛒" groups={PURCHASE_GROUPS} />
}
