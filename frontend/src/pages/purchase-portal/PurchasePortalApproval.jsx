import { useState, useEffect } from 'react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE, InfoBox, StatusBadge as StatusPill } from '@/components/ui/kit3d'

const cfg = (s) => ({
  In_Progress:  { label: 'In Progress',  color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Submitted:    { label: 'Submitted',    color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Under_Review: { label: 'Under Review', color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Approved:     { label: 'Approved',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:     { label: 'Rejected',     color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  On_Hold:      { label: 'On Hold',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
}[s] || { label: s || 'Not started', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' })

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—')

export default function PurchasePortalApproval() {
  const [onb, setOnb]   = useState(null)
  const [loading, setL] = useState(true)

  useEffect(() => {
    purchasePortalApi.onboarding.self()
      .then(d => setOnb(d?.onboarding ?? null))
      .catch(() => {})
      .finally(() => setL(false))
  }, [])

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading…</div>

  const history = (onb?.audit_logs || []).filter(a => /Onboarding|Approved|Rejected|Hold|Resubmit|Submitted/.test(a.action))

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '0 0 16px' }}>Approval Status</h1>

      {!onb ? <InfoBox>No onboarding submitted yet.</InfoBox> : (
        <>
          <div className="pr-glass" style={{ padding: 20, borderRadius: 16, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
              <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Current Status</span>
              <StatusPill cfg={cfg(onb.status)} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
              {[['Submitted', fmt(onb.submitted_at)], ['Approved', fmt(onb.approved_at)], ['Registration No.', onb.registration_number || '—'], ['Remarks', onb.remarks || '—']].map(([k, v]) => (
                <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
                  <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
                  <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right' }}>{v}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
            <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa', margin: '0 0 12px' }}>Timeline</p>
            {history.length === 0 ? <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>No activity yet.</p> : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {history.map(a => (
                  <div key={a.id} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '8px 12px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{a.action}</span>
                    {a.comment && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>— {a.comment}</span>}
                    <span style={{ marginLeft: 'auto', fontSize: 11, color: 'var(--text-muted)' }}>{fmt(a.created_at)}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
