import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { ShieldCheck, AlertTriangle, RefreshCw, ArrowRight, Search } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

/**
 * Top-level qualification queue (Sangoe TPV §6 Prequalification / §7 Risk &
 * Due-Diligence). The scoring engine and the per-vendor assessment panels already
 * exist on the vendor workspace; this surfaces them at module level — one place to
 * see every vendor's status/score and jump straight to the assessment tab.
 *
 * mode: 'prequalification' → qualification_status/score, tab ?tab=prequalification
 *       'risk'             → risk_level/risk_score,     tab ?tab=risk-score
 */
const CONFIG = {
  prequalification: {
    title: 'Prequalification',
    caption: 'Is this vendor suitable before engagement? Score → Qualified / Conditional / Not Qualified.',
    tab: 'prequalification',
    statusKey: 'qualification_status',
    scoreKey: 'qualification_score',
    atKey: 'qualification_assessed_at',
    unset: 'Not assessed',
    order: ['Not_Qualified', 'Conditional', 'Pending', 'Qualified'],
    tones: {
      Qualified: '#10b981', Conditional: '#f59e0b', Pending: '#94a3b8',
      Not_Qualified: '#ef4444', _unset: '#94a3b8',
    },
    labels: { Not_Qualified: 'Not Qualified' },
  },
  risk: {
    title: 'Risk & Due Diligence',
    caption: 'How much risk does this vendor carry? Tier drives onboarding depth and monitoring.',
    tab: 'risk-score',
    statusKey: 'risk_level',
    scoreKey: 'risk_score',
    atKey: 'risk_assessed_at',
    unset: 'Unclassified',
    order: ['Critical', 'High', 'Medium', 'Low'],
    tones: {
      Critical: '#ef4444', High: '#f97316', Medium: '#f59e0b', Low: '#10b981', _unset: '#94a3b8',
    },
    labels: {},
  },
}

export default function QualificationQueue({ mode }) {
  const cfg = CONFIG[mode]
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [q, setQ] = useState('')

  const load = () => {
    tpvApi.vendors.list().then(d => setRows(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setRows([]))
  }
  useEffect(load, []) // eslint-disable-line react-hooks/exhaustive-deps

  const toneFor = (status) => cfg.tones[status] || cfg.tones._unset
  const labelFor = (status) => (status ? (cfg.labels[status] || status) : cfg.unset)

  const filtered = useMemo(() => {
    if (!rows) return []
    const needle = q.trim().toLowerCase()
    const list = needle
      ? rows.filter(v => (v.company_name || '').toLowerCase().includes(needle) || (v.vendor_code || '').toLowerCase().includes(needle))
      : rows
    // Worst-first: unassessed and high-severity float to the top so the queue
    // reads as a to-do list, not an alphabetical roster.
    const rank = (v) => {
      const s = v[cfg.statusKey]
      const i = cfg.order.indexOf(s)
      return i === -1 ? -1 : i // unset (-1) sorts before the ordered severities
    }
    return [...list].sort((a, b) => rank(a) - rank(b))
  }, [rows, q, cfg])

  const summary = useMemo(() => {
    const counts = { _unset: 0 }
    cfg.order.forEach(s => { counts[s] = 0 })
    ;(rows || []).forEach(v => {
      const s = v[cfg.statusKey]
      if (s && counts[s] !== undefined) counts[s]++
      else if (!s) counts._unset++
    })
    return counts
  }, [rows, cfg])

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>VENDORS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>{cfg.title}</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>{cfg.caption}</p>
        </div>
        <button onClick={load} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {/* Status summary strip */}
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16 }}>
        {[...cfg.order, '_unset'].map(s => (
          <div key={s} className="pr-glass" style={{ padding: '10px 14px', borderRadius: 12, display: 'flex', alignItems: 'center', gap: 9, minWidth: 130 }}>
            <span style={{ width: 10, height: 10, borderRadius: '50%', background: toneFor(s === '_unset' ? null : s), flexShrink: 0 }} />
            <div>
              <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1 }}>{summary[s] ?? 0}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{s === '_unset' ? cfg.unset : labelFor(s)}</div>
            </div>
          </div>
        ))}
      </div>

      {/* Search */}
      <div style={{ position: 'relative', maxWidth: 320, marginBottom: 12 }}>
        <Search size={15} style={{ position: 'absolute', left: 11, top: 10, color: 'var(--text-muted)' }} />
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search vendors…"
          style={{ width: '100%', padding: '8px 12px 8px 34px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }} />
      </div>

      {/* Table */}
      <div className="pr-glass" style={{ padding: 0, overflow: 'hidden', borderRadius: 14 }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                <th style={{ padding: '11px 16px' }}>Vendor</th>
                <th style={{ padding: '11px 16px' }}>Status</th>
                <th style={{ padding: '11px 16px' }}>Score</th>
                <th style={{ padding: '11px 16px' }}>Assessed</th>
                <th style={{ padding: '11px 16px', textAlign: 'right' }}></th>
              </tr>
            </thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={5} style={{ padding: 20, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={5} style={{ padding: 20, color: 'var(--text-muted)' }}>{q ? 'No matching vendors.' : 'No vendors yet.'}</td></tr>
              ) : filtered.map(v => {
                const status = v[cfg.statusKey]
                const tone = toneFor(status)
                const score = v[cfg.scoreKey]
                const at = v[cfg.atKey]
                return (
                  <tr key={v.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '11px 16px' }}>
                      <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{v.company_name}</div>
                      {v.vendor_code && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.vendor_code}</div>}
                    </td>
                    <td style={{ padding: '11px 16px' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '3px 10px', borderRadius: 999, background: `${tone}1f`, color: tone, fontSize: 11.5, fontWeight: 700 }}>
                        {(!status || status === 'Critical' || status === 'Not_Qualified') ? <AlertTriangle size={12} /> : <ShieldCheck size={12} />}
                        {labelFor(status)}
                      </span>
                    </td>
                    <td style={{ padding: '11px 16px', fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                      {score !== null && score !== undefined ? `${score}${mode === 'prequalification' ? '/100' : ''}` : '—'}
                    </td>
                    <td style={{ padding: '11px 16px', color: 'var(--text-muted)', fontSize: 12 }}>
                      {at ? new Date(at).toLocaleDateString() : 'never'}
                    </td>
                    <td style={{ padding: '11px 16px', textAlign: 'right' }}>
                      <button onClick={() => navigate(`/app/tpv/view/${v.id}?tab=${cfg.tab}`)}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}>
                        {status ? 'Reassess' : 'Assess'} <ArrowRight size={12} />
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
