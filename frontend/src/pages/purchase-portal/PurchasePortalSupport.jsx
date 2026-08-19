import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { HelpCircle, Mail, Phone, User, FileText, ClipboardCheck, Loader2, Copy, Check } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * In-app Support & Help page for the Purchase Vendor Portal.
 *
 * Replaces the old dead `mailto:support@company.com` link in the sidebar with
 * real, visible content (so it always "responds", unlike a mailto that silently
 * no-ops without a mail client): the vendor's assigned account manager as the
 * primary contact, a general support address, and quick links back into the
 * portal. All data comes from the existing GET /portal/purchase/me — no new
 * endpoint. Mirrors the TPV portal's PortalSupport for consistency.
 */
export default function PurchasePortalSupport() {
  const [vendor, setVendor] = useState(null)
  const [loading, setLoading] = useState(true)
  const [copied, setCopied] = useState('')

  useEffect(() => {
    purchasePortalApi.me()
      .then(d => setVendor(d?.vendor ?? null))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const am = vendor?.account_manager || vendor?.accountManager || null
  const supportEmail = am?.email || 'support@company.com'
  const supportName = am?.name || 'Support Team'

  const copy = (text, key) => {
    navigator.clipboard?.writeText(text).then(() => {
      setCopied(key); setTimeout(() => setCopied(''), 1500)
    }).catch(() => {})
  }

  if (loading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'var(--text-muted)', padding: '40px 0', justifyContent: 'center' }}>
        <Loader2 size={18} className="rfq-spin" /> Loading support…
      </div>
    )
  }

  return (
    <div style={{ maxWidth: 860, margin: '0 auto' }}>
      {/* Hero */}
      <div className="portal-card portal-card-padded" style={{ marginBottom: 18 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <div style={{ width: 52, height: 52, borderRadius: 15, background: 'rgba(99,102,241,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <HelpCircle size={24} style={{ color: '#6366f1' }} />
          </div>
          <div>
            <h1 style={{ fontSize: 19, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Support &amp; Help</h1>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '4px 0 0', lineHeight: 1.5 }}>
              We're here to help with onboarding, documents, orders and compliance questions.
            </p>
          </div>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 16 }}>
        {/* Primary contact */}
        <div className="portal-card portal-card-padded">
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 14 }}>
            {am ? 'Your Account Manager' : 'Support Contact'}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
            <div style={{ width: 44, height: 44, borderRadius: 12, background: 'rgba(99,102,241,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <User size={20} style={{ color: '#818cf8' }} />
            </div>
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--text-h)' }}>{supportName}</div>
              <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{am ? 'Dedicated point of contact' : 'General support desk'}</div>
            </div>
          </div>

          <ContactRow icon={Mail} label="Email" value={supportEmail} copyKey="email" copied={copied} onCopy={copy} href={`mailto:${supportEmail}`} />
          {am?.phone && (
            <ContactRow icon={Phone} label="Phone" value={am.phone} copyKey="phone" copied={copied} onCopy={copy} href={`tel:${am.phone}`} />
          )}

          <a
            href={`mailto:${supportEmail}?subject=${encodeURIComponent('Support request — ' + (vendor?.company_name || 'Purchase Vendor Portal'))}`}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#6366f1,#4f46e5)', color: '#fff', fontWeight: 700, fontSize: 13, textDecoration: 'none', marginTop: 14 }}
          >
            <Mail size={15} /> Email Support
          </a>
        </div>

        {/* Self-service quick links */}
        <div className="portal-card portal-card-padded">
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 14 }}>
            Quick Help
          </div>
          <QuickLink to="/purchase-portal/onboarding" icon={ClipboardCheck} title="My Onboarding" desc="Check your onboarding status and next steps" />
          <QuickLink to="/purchase-portal/documents" icon={FileText} title="My Documents" desc="Upload or review your statutory documents" />
          <QuickLink to="/purchase-portal/approval" icon={User} title="Approval Status" desc="Track your company approval progress" />

          <div style={{ marginTop: 14, padding: '12px 14px', background: 'var(--bg-input)', borderRadius: 10, border: '1px solid var(--border)', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
            When contacting support, please quote your vendor code
            {' '}<strong style={{ color: 'var(--text-h)' }}>{vendor?.purchase_vendor_code || vendor?.vendor_code || '—'}</strong>
            {' '}so we can find your account quickly.
          </div>
        </div>
      </div>
    </div>
  )
}

function ContactRow({ icon: Icon, label, value, copyKey, copied, onCopy, href }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', background: 'var(--bg-input)', borderRadius: 10, border: '1px solid var(--border)', marginBottom: 8 }}>
      <Icon size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{label}</div>
        <a href={href} style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h)', textDecoration: 'none', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{value}</a>
      </div>
      <button
        onClick={() => onCopy(value, copyKey)}
        title="Copy"
        style={{ background: 'none', border: 'none', cursor: 'pointer', color: copied === copyKey ? '#22c55e' : 'var(--text-muted)', display: 'flex', padding: 4 }}
      >
        {copied === copyKey ? <Check size={15} /> : <Copy size={15} />}
      </button>
    </div>
  )
}

function QuickLink({ to, icon: Icon, title, desc }) {
  return (
    <Link to={to} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 12px', borderRadius: 10, textDecoration: 'none', marginBottom: 6, transition: 'background 0.15s' }}
      onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
      <div style={{ width: 36, height: 36, borderRadius: 10, background: 'rgba(99,102,241,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
        <Icon size={17} style={{ color: '#818cf8' }} />
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>{title}</div>
        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{desc}</div>
      </div>
    </Link>
  )
}
