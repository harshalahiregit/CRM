import { useNavigate } from 'react-router-dom'
import { HardHat, Gavel, HelpCircle, ShieldCheck, Package } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import PortalShell from '@/pages/vendor-portal/PortalShell'

/**
 * Purchase vendor portal — the SAME shared PortalShell as the TPV side, with a
 * Purchase descriptor: its own independent PurchaseVendor token/DB, its own
 * built routes, and Purchase-specific extras (Kickoff, PPE, Approval). Parity
 * with TPV is guaranteed because the nav tree comes from the shared registry.
 */
export default function PurchasePortalShell() {
  const navigate = useNavigate()

  const onLogout = async () => {
    try { await purchaseVendorAuthApi.logout() } finally { navigate('/purchase-portal/login') }
  }

  return (
    <PortalShell
      base="/purchase-portal"
      brandTitle="Purchase Vendor Portal"
      loadVendor={() => purchasePortalApi.me().then(d => d?.vendor ?? null)}
      onLogout={onLogout}
      builtRoutes={{
        dashboard:  'dashboard',
        onboarding: 'onboarding',
        profile:    'profile',
        comply:     'compliance',
        documents:  'documents',
        meeting:    'kickoff',   // Purchase kickoff meeting
        // Commercial — read-only documents raised against this vendor.
        'quotation':          'quotations',
        'contracts':          'contracts',
        'purchase-order':     'orders',
        'purchase-invoice':   'invoices',
        'debit-notes':        'debit-notes',
        'purchase-statement': 'statement',
        'payments':           'payments',
      }}
      extraGroups={[
        {
          group: 'Workforce & Support',
          items: [
            { key: 'workforce',  label: 'My Workforce',    icon: HardHat,     to: 'workforce', gate: v => v?.status === 'Active' },
            { key: 'ppe',        label: 'PPE Stock',       icon: Package,     to: 'ppe' },
            { key: 'governance', label: 'Governance',      icon: Gavel,       to: 'governance' },
            { key: 'approval',   label: 'Approval Status', icon: ShieldCheck, to: 'approval' },
            { key: 'support',    label: 'Support',         icon: HelpCircle,  to: 'support' },
          ],
        },
      ]}
    />
  )
}
