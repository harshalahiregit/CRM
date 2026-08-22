import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  Building2, LayoutDashboard, Receipt, CreditCard, FileX, Wallet, ClipboardList,
  FileText, FileSignature, FolderKanban, LifeBuoy, Paperclip, StickyNote,
  Users2, UserCircle2, LogOut, MessageSquareHeart,
} from 'lucide-react'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * The customer portal frame.
 *
 * The navigation is built from the contact's own permissions, which the server
 * returns and also enforces. Hiding a link is a courtesy, not the control —
 * every endpoint refuses on its own, so a hand-typed URL gets a 403 rather than
 * data. That is why this can safely be driven by client-side state.
 */
const SECTIONS = [
  { group: null, items: [
    { to: '/portal/dashboard', label: 'Overview', icon: LayoutDashboard, perm: null },
  ]},
  { group: 'Finance', items: [
    { to: '/portal/invoices',     label: 'Invoices',     icon: Receipt,      perm: 'invoice' },
    { to: '/portal/payments',     label: 'Payments',     icon: CreditCard,   perm: 'invoice' },
    { to: '/portal/credit-notes', label: 'Credit Notes', icon: FileX,        perm: 'invoice' },
    { to: '/portal/statement',    label: 'Statement',    icon: Wallet,       perm: 'invoice' },
  ]},
  { group: 'Commercial', items: [
    { to: '/portal/estimates', label: 'Estimates', icon: ClipboardList,  perm: 'estimate' },
    { to: '/portal/proposals', label: 'Proposals', icon: FileText,       perm: 'proposal' },
    { to: '/portal/contracts', label: 'Contracts', icon: FileSignature,  perm: 'contract' },
  ]},
  { group: 'Work', items: [
    { to: '/portal/projects', label: 'Projects', icon: FolderKanban, perm: 'project' },
    { to: '/portal/tickets',  label: 'Support',  icon: LifeBuoy,     perm: 'support' },
  ]},
  { group: 'Your say', items: [
    // §10 — the customer answers the survey themselves. No permission gate:
    // being asked what you think of the service is not a privilege somebody
    // grants you, and gating it would silence exactly the accounts whose
    // opinion matters most.
    { to: '/portal/feedback', label: 'Feedback', icon: MessageSquareHeart, perm: null },
  ]},
  { group: 'Information', items: [
    { to: '/portal/files',    label: 'Files',    icon: Paperclip,    perm: null },
    { to: '/portal/notes',    label: 'Notes',    icon: StickyNote,   perm: null },
    { to: '/portal/contacts', label: 'Contacts', icon: Users2,       perm: null },
    { to: '/portal/profile',  label: 'My Profile', icon: UserCircle2, perm: null },
  ]},
]

export default function ClientPortalShell() {
  const navigate = useNavigate()
  const [me, setMe] = useState(null)

  useEffect(() => {
    clientPortalApi.me().then(setMe).catch(() => setMe(null))
  }, [])

  const can = (perm) => !perm || (me?.permissions ?? []).includes(perm)

  const signOut = async () => {
    await clientPortalApi.logout()
    navigate('/portal/login', { replace: true })
  }

  const link = ({ isActive }) => ({
    display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px',
    borderRadius: 10, fontSize: 13, fontWeight: 600, textDecoration: 'none',
    color: isActive ? '#fff' : 'var(--text-muted,#9ca3af)',
    background: isActive ? 'rgba(124,58,237,0.18)' : 'transparent',
    border: `1px solid ${isActive ? 'rgba(124,58,237,0.4)' : 'transparent'}`,
  })

  return (
    <div style={{ minHeight: '100vh', display: 'flex', background: 'var(--bg-global,#0b0d12)' }}>
      <aside style={{ width: 250, flexShrink: 0, borderRight: '1px solid var(--border,#2a2f3a)', padding: 18, display: 'flex', flexDirection: 'column' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 20 }}>
          <div style={{ width: 34, height: 34, borderRadius: 10, display: 'grid', placeItems: 'center', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Building2 size={17} style={{ color: '#fff' }} />
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h,#fff)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {me?.company || 'Customer Portal'}
            </div>
            <div style={{ fontSize: 11, color: 'var(--text-muted,#9ca3af)' }}>Customer Portal</div>
          </div>
        </div>

        <nav style={{ display: 'flex', flexDirection: 'column', gap: 3, flex: 1, overflowY: 'auto' }}>
          {SECTIONS.map((sec, i) => {
            const visible = sec.items.filter((it) => can(it.perm))
            // A group whose every item is hidden must not leave a stray heading.
            if (visible.length === 0) return null
            return (
              <div key={sec.group ?? i} style={{ marginBottom: 6 }}>
                {sec.group && (
                  <div style={{ fontSize: 10, fontWeight: 800, letterSpacing: '.08em', textTransform: 'uppercase', color: 'var(--text-faint,#6b7280)', padding: '10px 12px 4px' }}>
                    {sec.group}
                  </div>
                )}
                {visible.map((it) => (
                  <NavLink key={it.to} to={it.to} style={link}>
                    <it.icon size={15} /> {it.label}
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>

        <button onClick={signOut} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '9px 12px', borderRadius: 10, background: 'transparent', border: '1px solid var(--border,#2a2f3a)', color: 'var(--text-muted,#9ca3af)', fontSize: 13, fontWeight: 600, cursor: 'pointer', marginTop: 12 }}>
          <LogOut size={15} /> Sign out
        </button>
      </aside>

      <main style={{ flex: 1, padding: 26, minWidth: 0, overflowX: 'auto' }}>
        <Outlet context={{ me }} />
      </main>
    </div>
  )
}
