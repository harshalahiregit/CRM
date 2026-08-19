import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ShieldCheck, Siren, AlertTriangle, Building2, HardHat, FileWarning,
  Loader2, RefreshCw, TrendingUp, Ban,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

const sevColor = (s) => ({ Minor: '#10b981', Moderate: '#f59e0b', Serious: '#f97316', Fatal: '#ef4444' }[s] || '#94a3b8')
const bandColor = (b) => ({ A: '#10b981', B: '#0ea5e9', C: '#f59e0b', D: '#ef4444' }[b] || '#94a3b8')
const pretty = (s) => (s || '').replace(/_/g, ' ')

/**
 * HSSE Governance Dashboard (Doc 6). A single command view over everything the
 * program produces — open incidents by severity, the vendor-rating spread,
 * statutory-document & medical currency, active strikes and workforce.
 */
export default function GovernanceDashboard() {
  const navigate = useNavigate()
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = () => {
    setLoading(true)
    tpvApi.governance.dashboard().then(setD).catch(() => setD(null)).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  if (loading) return <div style={{ padding: 48, textAlign: 'center', color: 'var(--text-muted)' }}><Loader2 size={20} className="rfq-spin" /> Loading…</div>
  if (!d) return <div style={{ padding: 48, textAlign: 'center', color: 'var(--text-muted)' }}>Could not load the governance dashboard.</div>

  const inc = d.incidents, ven = d.vendors, rat = d.ratings, wf = d.workforce
  const kpis = [
    { label: 'Open Incidents', value: inc.open, icon: Siren, color: inc.open ? '#ef4444' : '#10b981', to: '/app/tpv/incidents' },
    { label: 'Stop-Works', value: inc.stop_works, icon: Ban, color: inc.stop_works ? '#f97316' : '#94a3b8', to: '/app/tpv/incidents' },
    { label: 'Suspended Vendors', value: ven.suspended, icon: AlertTriangle, color: ven.suspended ? '#f97316' : '#10b981' },
    { label: 'Active Vendors', value: ven.active, icon: Building2, color: '#0ea5e9' },
    { label: 'Docs Expiring (30d)', value: ven.expiring_docs, icon: FileWarning, color: ven.expiring_docs ? '#f59e0b' : '#10b981' },
    { label: 'Medicals Expiring (30d)', value: wf.medical_expiring, icon: FileWarning, color: wf.medical_expiring ? '#f59e0b' : '#10b981' },
    { label: 'Active Strikes', value: wf.active_strikes, icon: ShieldCheck, color: wf.active_strikes ? '#f97316' : '#10b981', to: '/app/tpv/strikes' },
    { label: 'Active Workers', value: wf.active_workers, icon: HardHat, color: '#a78bfa' },
  ]

  return (
    <div style={{ padding: 24, maxWidth: 1150, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ShieldCheck size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>HSSE Governance</h1>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Live command view · incidents · ratings · compliance · workforce</p>
          </div>
        </div>
        <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}><RefreshCw size={14} /></button>
      </div>

      {/* KPI grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 12, marginBottom: 20 }}>
        {kpis.map(k => (
          <div key={k.label} onClick={k.to ? () => navigate(k.to) : undefined}
            className="pr-glass" style={{ borderRadius: 14, padding: 16, cursor: k.to ? 'pointer' : 'default' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <span style={{ fontSize: 10.5, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{k.label}</span>
              <k.icon size={16} style={{ color: k.color }} />
            </div>
            <div style={{ fontSize: 28, fontWeight: 800, color: k.color, marginTop: 6 }}>{k.value ?? 0}</div>
          </div>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 16 }}>
        {/* Open incidents by severity */}
        <Panel title="Open Incidents by Severity" icon={Siren}>
          {Object.entries(inc.open_by_severity).every(([, v]) => !v) ? (
            <Empty text="No open incidents. 🎉" />
          ) : Object.entries(inc.open_by_severity).map(([sev, n]) => (
            <Bar key={sev} label={sev} value={n} max={inc.open || 1} color={sevColor(sev)} />
          ))}
        </Panel>

        {/* Vendor ratings */}
        <Panel title="Vendor Ratings (live)" icon={TrendingUp}>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
            <span style={{ fontSize: 30, fontWeight: 900, color: 'var(--text-h)' }}>{rat.avg_score ?? '—'}</span>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>avg across {rat.rated} active vendor(s)</span>
          </div>
          {['A', 'B', 'C', 'D'].map(b => (
            <Bar key={b} label={`Band ${b}`} value={rat.bands?.[b] ?? 0} max={rat.rated || 1} color={bandColor(b)} />
          ))}
        </Panel>

        {/* Recent incidents */}
        <Panel title="Recent Incidents" icon={AlertTriangle}>
          {(inc.recent || []).length === 0 ? <Empty text="None reported." /> : (inc.recent || []).map(r => (
            <div key={r.id} onClick={() => navigate('/app/tpv/incidents')} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0', borderBottom: '1px solid var(--border)', cursor: 'pointer' }}>
              <span style={{ width: 8, height: 8, borderRadius: 999, background: sevColor(r.severity), flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.title}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.reference} · {r.vendor?.company_name || '—'} · {pretty(r.status)}</div>
              </div>
            </div>
          ))}
        </Panel>
      </div>
    </div>
  )
}

function Panel({ title, icon: Icon, children }) {
  return (
    <div className="pr-glass" style={{ borderRadius: 14, padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <Icon size={15} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
      </div>
      {children}
    </div>
  )
}
function Bar({ label, value, max, color }) {
  return (
    <div style={{ marginBottom: 9 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 3 }}>
        <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{label}</span>
        <span style={{ color, fontWeight: 800 }}>{value}</span>
      </div>
      <div style={{ height: 6, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden' }}>
        <div style={{ width: `${Math.min(100, (value / max) * 100)}%`, height: '100%', background: color, borderRadius: 999 }} />
      </div>
    </div>
  )
}
function Empty({ text }) {
  return <div style={{ padding: '16px 0', textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted)' }}>{text}</div>
}
