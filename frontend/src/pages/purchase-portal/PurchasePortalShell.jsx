import { useNavigate } from 'react-router-dom'
import { HardHat, Gavel, HelpCircle, ShieldCheck, FileSignature, ListChecks } from 'lucide-react'
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
    try { await purchaseVendorAuthApi.logout() } finally { navigate('/auth/login?role=purchase_vendor') }
  }

  return (
    <PortalShell
      base="/purchase-portal"
      brandTitle="Purchase Vendor Portal"
      loadVendor={() => purchasePortalApi.me().then(d => d?.vendor ?? null)}
      onLogout={onLogout}
      notificationsApi={purchasePortalApi.notifications}
      builtRoutes={{
        dashboard:  'dashboard',
        onboarding: 'onboarding',
        overview:   'overview',
        profile:    'profile',
        contact:    'contacts',
        // External Medical Flow — upload certificates, answer the quality team.
        medical:    'medical',
        training:   'training',
        comply:     'compliance',
        documents:  'documents',
        kb:         'kb',
        meeting:    'kickoff',   // Purchase kickoff meeting
        // Commercial — read-only documents raised against this vendor.
        items:                'items',
        'quotation':          'quotations',
        'contracts':          'contracts',
        'purchase-order':     'orders',
        'purchase-invoice':   'invoices',
        'debit-notes':        'debit-notes',
        'purchase-statement': 'statement',
        'payments':           'payments',
        // Parity with the TPV portal (shared pages, Purchase api).
        customer:    'customers',
        project:     'projects',
        tasks:       'tasks',
        ticket:      'tickets',
        expenses:    'expenses',
        feedback:    'feedback',
        penalty:     'penalty',
        award:       'awards',
        referral:    'referrals',
        'pre-alert': 'pre-alert',
        packages:    'packages',
        shipping:    'shipping',
        'hsse-documents': 'documents',
        // Purchase-native greenfield: risk (lean tier/score), PTW, Incidents.
        'risk-score': 'risk-score',
        ptw:          'ptw',
        incidents:    'incidents',
      }}
      extraGroups={[
        {
          group: 'Workforce & Support',
          items: [
            { key: 'workforce',  label: 'My Workforce',    icon: HardHat,     to: 'workforce', gate: v => v?.status === 'Active' },
            { key: 'governance', label: 'Governance',      icon: Gavel,       to: 'governance' },
            // The Contract module's agreements. Distinct from the Commercial
            // section's Contracts view, which serves purchase_contracts — that
            // feature has live rows and is left exactly as it was.
            { key: 'agreements', label: 'Agreements',      icon: FileSignature, to: 'agreements' },
            // Tasks assigned by name to this vendor's own contacts. There is no
            // per-contact login on this portal — the vendor signs in as the
            // company — so it is the team's work, each row naming whose it is.
            { key: 'tasks',      label: 'Team Tasks',      icon: ListChecks, to: 'tasks' },
            { key: 'approval',   label: 'Approval Status', icon: ShieldCheck, to: 'approval' },
            { key: 'support',    label: 'Support',         icon: HelpCircle,  to: 'support' },
          ],
        },
      ]}
    />
  )
}
