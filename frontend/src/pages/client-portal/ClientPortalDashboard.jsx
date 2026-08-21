import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { AlertCircle, Info, Wallet, Receipt, FolderKanban, LifeBuoy, ArrowUpRight } from 'lucide-react'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * The portal landing page.
 *
 * The old CRM showed a greeting, a projects summary and an invoice figure. This
 * keeps that shape and adds the thing a customer actually opens the portal to
 * find out: what is outstanding, what is overdue, and what is waiting on them.
 *
 * What it deliberately does NOT show is anything we think ABOUT them — health
 * scores, risk ratings, internal notes. Those belong on our side of the glass.
 */
const money = (n, cur = 'INR') =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(Number(n) || 0)

function Tile({ icon: Icon, label, value, sub, to }) {
  const body = (
    <>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <Icon size={15} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.07em', textTransform: 'uppercase', color: 'var(--text-muted,#9ca3af)' }}>{label}</span>
        {to && <ArrowUpRight size={13} style={{ color: 'var(--text-faint,#6b7280)', marginLeft: 'auto' }} />}
      </div>
      <div style={{ fontSize: 25, fontWeight: 900, color: 'var(--text-h,#fff)', lineHeight: 1, marginTop: 10, fontVariantNumeric: 'tabular-nums' }}>{value}</div>
      {sub && <div style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)', marginTop: 5 }}>{sub}</div>}
    </>
  )
  const style = { display: 'block', padding: 18, borderRadius: 14, background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', textDecoration: 'none' }
  return to ? <Link to={to} style={style}>{body}</Link> : <div style={style}>{body}</div>
}

export default function ClientPortalDashboard() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    clientPortalApi.dashboard().then(setD).catch((e) => setErr(e?.response?.data?.message || 'Could not load your dashboard.'))
  }, [])

  if (err) return <div style={{ color: '#ef4444', fontSize: 13 }}>{err}</div>
  if (!d) return <div style={{ color: 'var(--text-muted,#9ca3af)', fontSize: 13 }}>Loading…</div>

  const cur = d.company?.currency || 'INR'

  return (
    <div style={{ display: 'grid', gap: 16, maxWidth: 1100 }}>
      <div>
        <h1 style={{ fontSize: 23, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>
          {d.greeting}, {d.contact?.name?.split(' ')[0] || 'there'}
        </h1>
        <p style={{ fontSize: 13, color: 'var(--text-muted,#9ca3af)', margin: '4px 0 0' }}>
          {d.company?.name}
          {d.contact?.last_login && ` · last signed in ${new Date(d.contact.last_login).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}`}
        </p>
      </div>

      {/* Only what needs the customer to do something. Nothing to show is good news. */}
      {d.actions?.length > 0 && (
        <div style={{ display: 'grid', gap: 8 }}>
          {d.actions.map((a) => {
            const high = a.urgency === 'high'
            const Icon = high ? AlertCircle : Info
            return (
              <Link key={a.key} to={a.link} style={{
                display: 'flex', alignItems: 'center', gap: 10, padding: '11px 15px', borderRadius: 12, textDecoration: 'none',
                background: high ? 'rgba(239,68,68,0.10)' : 'rgba(59,130,246,0.10)',
                border: `1px solid ${high ? 'rgba(239,68,68,0.35)' : 'rgba(59,130,246,0.30)'}`,
              }}>
                <Icon size={15} style={{ color: high ? '#ef4444' : '#3b82f6', flexShrink: 0 }} />
                <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h,#fff)' }}>{a.message}</span>
                <ArrowUpRight size={13} style={{ color: 'var(--text-faint,#6b7280)', marginLeft: 'auto' }} />
              </Link>
            )
          })}
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
        {d.finance && (
          <>
            <Tile icon={Wallet} label="Outstanding" value={money(d.finance.outstanding, cur)}
              sub={d.finance.overdue > 0 ? `${money(d.finance.overdue_amount, cur)} overdue` : 'Nothing overdue'}
              to="/portal/invoices" />
            <Tile icon={Receipt} label="Paid (12 months)" value={money(d.finance.paid_last_12m, cur)} to="/portal/payments" />
          </>
        )}
        {d.projects && (
          <Tile icon={FolderKanban} label="Active Projects" value={d.projects.active}
            sub={d.projects.finished > 0 ? `${d.projects.finished} completed` : null} to="/portal/projects" />
        )}
        {d.tickets && (
          <Tile icon={LifeBuoy} label="Open Tickets" value={d.tickets.open}
            sub={d.tickets.closed > 0 ? `${d.tickets.closed} closed` : null} to="/portal/tickets" />
        )}
      </div>

      {d.permissions?.length === 0 && (
        <div style={{ padding: 18, borderRadius: 12, background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)' }}>
          <p style={{ fontSize: 13, color: 'var(--text-h,#fff)', margin: 0, fontWeight: 600 }}>No sections have been shared with you yet.</p>
          <p style={{ fontSize: 12, color: 'var(--text-muted,#9ca3af)', margin: '5px 0 0' }}>
            Your account manager decides which parts of your account you can see. Ask them to enable the ones you need.
          </p>
        </div>
      )}
    </div>
  )
}
