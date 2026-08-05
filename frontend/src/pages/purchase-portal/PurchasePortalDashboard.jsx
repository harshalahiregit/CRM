import RegistrationStatusCard from '@/components/vendor/RegistrationStatusCard'
import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Building2, ClipboardList, FileText, ShieldCheck, CalendarDays, ChevronRight,
  Hash, Tag, Activity, FileWarning, CalendarClock, Clock,
} from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE, StatusBadge as StatusPill } from '@/components/ui/kit3d'
import PurchaseRegistrationBadge from '@/modules/purchase/components/PurchaseRegistrationBadge'
import TemporaryVendorValidityBadge from '@/modules/purchase/components/TemporaryVendorValidityBadge'

const onbCfg = (s) => ({
  In_Progress:  { label: 'In Progress',  color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Submitted:    { label: 'Submitted',    color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Under_Review: { label: 'Under Review', color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Approved:     { label: 'Approved',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:     { label: 'Rejected',     color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  On_Hold:      { label: 'On Hold',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
}[s] || { label: s || 'Not started', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' })

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')

export default function PurchasePortalDashboard() {
  const navigate = useNavigate()
  const [vendor, setVendor] = useState(null)
  const [onb, setOnb]       = useState(null)
  const [progress, setProgress] = useState(null)
  const [checklist, setChecklist] = useState(null)
  const [kickoff, setKickoff] = useState(null)
  const [showWelcome, setShowWelcome] = useState(false)
  const [dismissing, setDismissing] = useState(false)

  // Hide immediately, then persist. If the call fails the banner returns on the
  // next load — better than pretending a dismissal we never stored.
  const dismissWelcome = async () => {
    setDismissing(true)
    try { await purchasePortalApi.dismissWelcomeBanner(); setShowWelcome(false) }
    catch { /* leave it visible */ }
    finally { setDismissing(false) }
  }

  useEffect(() => {
    purchasePortalApi.me()
      .then(d => { setVendor(d?.vendor ?? null); setShowWelcome(!!d?.vendor?.show_welcome_banner) })
      .catch(() => {})
    purchasePortalApi.onboarding.self().then(d => { setOnb(d?.onboarding ?? null); setProgress(d?.progress ?? null) }).catch(() => {})
    purchasePortalApi.documents.checklist().then(d => setChecklist(d ?? null)).catch(() => {})
    purchasePortalApi.kickoff.get().then(d => setKickoff(d?.meeting ?? null)).catch(() => {})
  }, [])

  const done = (progress?.steps || []).filter(s => s.complete).length
  const total = (progress?.steps || []).length || 6
  const pct = Math.round((done / total) * 100)

  // Pending documents — required items not yet approved.
  const required = checklist?.required || checklist?.data?.required || []
  const pendingDocs = Array.isArray(required)
    ? required.filter(r => String(r.status || '').toLowerCase() !== 'approved').length
    : 0

  const vendorCode = vendor?.purchase_vendor_code || vendor?.vendor_code || '—'
  const portalStatus = vendor?.portal_status || (vendor?.status_label ?? vendor?.status) || '—'

  const kpis = [
    { label: 'Vendor Code', value: vendorCode, icon: Hash, color: '#a78bfa' },
    { label: 'Category', value: vendor?.category || '—', icon: Tag, color: '#0ea5e9' },
    { label: 'Portal Status', value: cap(portalStatus), icon: Activity, color: '#10b981' },
    { label: 'Onboarding', value: `${pct}%`, icon: ClipboardList, color: '#8b5cf6' },
    { label: 'Pending Documents', value: pendingDocs, icon: FileWarning, color: pendingDocs > 0 ? '#f59e0b' : '#10b981' },
    { label: 'Upcoming Kickoff', value: kickoff?.scheduled_at ? fmtDateTime(kickoff.scheduled_at) : (kickoff ? cap(kickoff.status) : 'None'), icon: CalendarClock, color: '#7C3AED' },
  ]

  // Onboarding and Approval shortcuts removed — both are admin workflows, and the
  // approval status is now reported by the read-only card below rather than by a
  // screen the vendor could mistake for something they must action.
  const tiles = [
    { label: 'Documents',  desc: 'Upload statutory documents', icon: FileText,     to: '/purchase-portal/documents' },
    { label: 'Kickoff',    desc: 'Your kickoff meeting & MOM', icon: CalendarDays, to: '/purchase-portal/kickoff' },
  ]

  // Read-only registration status, from the onboarding progress already loaded.
  const regSteps = useMemo(() => {
    const step = onb?.current_step ?? 0
    const approved = onb?.status === 'Approved'
    const at = (n) => ({ done: approved || step > n, current: !approved && step === n })
    return [
      { key: 'company',    label: 'Company Details Completed', ...at(2) },
      { key: 'documents',  label: 'Documents Verified',        ...at(3) },
      { key: 'workforce',  label: 'Workforce Approved',        ...at(4) },
      { key: 'compliance', label: 'Compliance Approved',       ...at(5) },
      { key: 'activated',  label: 'Account Activated',         done: vendor?.status === 'Active', current: approved && vendor?.status !== 'Active' },
    ]
  }, [onb, vendor])

  return (
    <div style={{ padding: 24 }}>
      <style>{KIT3D_STYLE}</style>

      {/* Vendor header */}
      <div className="pr-glass" style={{ padding: '20px 22px', borderRadius: 16, marginBottom: 18, display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <div style={{ width: 52, height: 52, borderRadius: 15, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)' }}>
          <Building2 size={22} color="#fff" />
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 800, margin: 0 }}>{vendor?.company_name || 'Vendor'}</h1>
            <PurchaseRegistrationBadge type={vendor?.registration_type} label={vendor?.registration_type_label} />
            {/* Portal header card: remaining access (temporary only) */}
            <TemporaryVendorValidityBadge countdown={vendor?.validity_countdown} showLabel />
            <StatusPill cfg={onbCfg(onb?.status)} />
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '5px 0 0' }}>
            Registration Type: <strong style={{ color: 'var(--text-h)' }}>{vendor?.registration_type_label || 'Standard Vendor'}</strong>
          </p>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '3px 0 0' }}>
            {vendorCode}{onb?.registration_number ? ` · Reg. ${onb.registration_number}` : ''} · Onboarding {pct}% complete
          </p>
        </div>
      </div>

      {/* One-time post-activation welcome banner. The flag comes from the server
          and the dismissal is persisted there, so it cannot reappear elsewhere. */}
      {showWelcome && (
        <div className="pr-glass" style={{ padding: '18px 22px', borderRadius: 16, marginBottom: 18, display: 'flex', alignItems: 'flex-start', gap: 14, border: '1px solid rgba(16,185,129,0.35)', background: 'rgba(16,185,129,0.08)' }}>
          <div style={{ fontSize: 26, lineHeight: 1 }}>🎉</div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Welcome!</div>
            <p style={{ fontSize: 13.5, color: 'var(--text-muted)', margin: '4px 0 0', lineHeight: 1.6 }}>
              Your account has been activated successfully. You can now access all available portal features.
            </p>
          </div>
          <button onClick={dismissWelcome} disabled={dismissing}
            style={{ padding: '7px 14px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', flexShrink: 0 }}>
            {dismissing ? 'Dismissing…' : 'Dismiss'}
          </button>
        </div>
      )}

      {/* Temporary Access card — only for time-boxed accounts */}
      {vendor?.validity_countdown?.is_temporary && (
        <div className="pr-glass" style={{ padding: '16px 20px', borderRadius: 16, marginBottom: 18, display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
          <Clock size={20} style={{ color: '#f97316' }} />
          <div>
            <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>Temporary Access</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 3 }}>
              Remaining: <TemporaryVendorValidityBadge countdown={vendor.validity_countdown} />
            </div>
          </div>
          {vendor.validity_countdown.expires_at && (
            <span style={{ marginLeft: 'auto', fontSize: 12, color: 'var(--text-muted)' }}>
              Expires {new Date(vendor.validity_countdown.expires_at).toLocaleString()}
            </span>
          )}
        </div>
      )}

      {/* KPI grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14, marginBottom: 18 }}>
        {kpis.map(k => (
          <div key={k.label} className="pr-glass" style={{ padding: 16 }}>
            <div style={{ width: 36, height: 36, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${k.color}1f` }}><k.icon size={17} style={{ color: k.color }} /></div>
            <div style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)', marginTop: 10, lineHeight: 1.25, wordBreak: 'break-word' }}>{k.value}</div>
            <div style={{ fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)', marginTop: 3 }}>{k.label}</div>
          </div>
        ))}
      </div>

      {/* Read-only registration status — information only. */}
      <div style={{ marginBottom: 16 }}>
        <RegistrationStatusCard steps={regSteps} />
      </div>

      {/* Nav tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 14 }}>
        {tiles.map(t => (
          <div key={t.label} className="pr-glass pr-lift" onClick={() => navigate(t.to)} style={{ padding: 18, cursor: 'pointer' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)' }}><t.icon size={18} style={{ color: '#a78bfa' }} /></div>
              <ChevronRight size={16} style={{ color: 'var(--text-muted)' }} />
            </div>
            <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', marginTop: 12 }}>{t.label}</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 3 }}>{t.desc}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

const cap = (s) => (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1) : s)
