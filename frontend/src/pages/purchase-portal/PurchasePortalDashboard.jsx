import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Building2, ClipboardList, FileText, ShieldCheck, CalendarDays, ChevronRight } from 'lucide-react'
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

export default function PurchasePortalDashboard() {
  const navigate = useNavigate()
  const [vendor, setVendor] = useState(null)
  const [onb, setOnb]       = useState(null)
  const [progress, setProgress] = useState(null)

  useEffect(() => {
    purchasePortalApi.me().then(d => setVendor(d?.vendor ?? null)).catch(() => {})
    purchasePortalApi.onboarding.self().then(d => { setOnb(d?.onboarding ?? null); setProgress(d?.progress ?? null) }).catch(() => {})
  }, [])

  const done = (progress?.steps || []).filter(s => s.complete).length
  const total = (progress?.steps || []).length || 6
  const pct = Math.round((done / total) * 100)

  const tiles = [
    { label: 'Onboarding', desc: 'Complete your 6-step onboarding', icon: ClipboardList, to: '/purchase-portal/onboarding' },
    { label: 'Documents',  desc: 'Upload statutory documents',       icon: FileText,      to: '/purchase-portal/documents' },
    { label: 'Approval',   desc: 'Track your approval status',        icon: ShieldCheck,   to: '/purchase-portal/approval' },
    { label: 'Kickoff',    desc: 'Your kickoff meeting & MOM',        icon: CalendarDays,  to: '/purchase-portal/kickoff' },
  ]

  return (
    <div style={{ padding: 24 }}>
      <style>{KIT3D_STYLE}</style>

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
            {vendor?.vendor_code}{vendor?.registration_number ? ` · Reg. ${vendor.registration_number}` : ''} · Onboarding {pct}% complete
          </p>
        </div>
      </div>

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
