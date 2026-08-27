import { useNavigate } from 'react-router-dom'
import { HardHat, Gavel, HelpCircle } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { portalApi } from '@/services/portalApi'
import TemporaryAccessBanner from '@/modules/tpv/components/TemporaryAccessBanner'
import PortalShell from './PortalShell'

/**
 * TPV vendor portal — a thin descriptor over the shared PortalShell. The nav
 * tree, chrome, theming and gating all live in PortalShell / portalSections.js;
 * this file only says "which DB, which built routes, which extras" for the TPV
 * side. The Purchase portal is the same shell with its own descriptor.
 *
 * builtRoutes maps canonical section keys → existing TPV portal route segments.
 * Everything else in the tree renders a ComingSoon placeholder until built.
 */
export default function VendorPortalShell() {
  const { logout } = useAuth()
  const navigate = useNavigate()

  const onLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  return (
    <PortalShell
      base="/vendor-portal"
      brandTitle="Vendor Portal"
      loadVendor={() => portalApi.me().then(d => d?.vendor ?? null)}
      onLogout={onLogout}
      renderBanner={(vendor) => <TemporaryAccessBanner vendor={vendor} />}
      builtRoutes={{
        dashboard:  'dashboard',
        onboarding: 'onboarding',
        profile:    'registration',   // "My Company"
        contact:    'contacts',
        comply:     'compliance',
        documents:  'documents',      // statutory documents / files
        kb:         'kb',
        // Execution — my-work read lists (Projects / Tasks / Tickets).
        project:    'projects',
        tasks:      'tasks',
        ticket:     'tickets',
        // Performance — own risk score, rating, penalties, awards, referrals.
        'risk-score': 'risk-score',
        feedback:     'feedback',
        penalty:      'penalty',
        award:        'awards',
        referral:     'referrals',
      }}
      extraGroups={[
        {
          group: 'Workforce & Support',
          items: [
            { key: 'workforce',  label: 'My Workforce', icon: HardHat, to: 'workforce', gate: v => v?.status === 'Active' },
            { key: 'governance', label: 'Governance',   icon: Gavel,   to: 'governance' },
            { key: 'support',    label: 'Support',      icon: HelpCircle, to: 'support' },
          ],
        },
      ]}
    />
  )
}
