import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Building2, ClipboardList, FileText, ShieldCheck, CalendarDays, ChevronRight,
  Hash, Tag, Activity, FileWarning, CalendarClock,
} from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE, StatusBadge as StatusPill } from '@/components/ui/kit3d'

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

  useEffect(() => {
    purchasePortalApi.me().then(d => setVendor(d?.vendor ?? null)).catch(() => {})
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

  const tiles = [
    { label: 'Onboarding', desc: 'Complete your onboarding steps', icon: ClipboardList, to: '/purchase-portal/onboarding' },
    { label: 'Documents',  desc: 'Upload statutory documents',      icon: FileText,      to: '/purchase-portal/documents' },
    { label: 'Approval',   desc: 'Track your approval status',       icon: ShieldCheck,   to: '/purchase-portal/approval' },
    { label: 'Kickoff',    desc: 'Your kickoff meeting & MOM',       icon: CalendarDays,  to: '/purchase-portal/kickoff' },
  ]

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
            <StatusPill cfg={onbCfg(onb?.status)} />
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '5px 0 0' }}>
            {vendorCode}{onb?.registration_number ? ` · Reg. ${onb.registration_number}` : ''} · Onboarding {pct}% complete
          </p>
        </div>
      </div>

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
