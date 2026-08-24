import { useState, useEffect, useCallback } from 'react'
import { Gauge, RefreshCw, ChevronDown, ChevronRight } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Vendor Performance Index — the Purchase-side mirror of the TPV VPI
// (parity rule). Six governance dimensions (compliance / quality / CAPA /
// conduct / inspection / documentation) into a weighted A–E index.
const DIM_LABEL = {
  compliance: 'Compliance', quality: 'Quality', capa: 'CAPA',
  conduct: 'Conduct', inspection: 'Inspection', documentation: 'Docs',
}
const BAND_TONE = { A: '#22c55e', B: '#0ea5e9', C: '#f59e0b', D: '#f97316', E: '#ef4444' }
const scoreTone = (s) => (s >= 80 ? '#22c55e' : s >= 60 ? '#f59e0b' : '#ef4444')
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function PurchasePerformanceIndex() {
  const [rows, setRows] = useState(null)
  const [dims, setDims] = useState([])
  const [expanded, setExpanded] = useState(null)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    purchaseApi.vpi.roster().then(d => { setRows(d?.data ?? []); setDims(d?.dimensions ?? []) }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])

  const bandCounts = (rows || []).reduce((a, r) => { a[r.band] = (a[r.band] || 0) + 1; return a }, {})

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PERFORMANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Performance Index</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Six-dimension weighted governance index — worst first, A–E banded.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
        {['A', 'B', 'C', 'D', 'E'].map(b => (
          <div key={b} className="pr-glass" style={{ padding: '8px 14px', borderRadius: 10, display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ width: 26, height: 26, borderRadius: 8, background: `${BAND_TONE[b]}22`, color: BAND_TONE[b], fontWeight: 900, fontSize: 13, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>{b}</span>
            <span style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{bandCounts[b] || 0}</span>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                <th style={{ padding: '11px 14px' }}></th>
                <th style={{ padding: '11px 14px' }}>Vendor</th>
                <th style={{ padding: '11px 14px' }}>Index</th>
                <th style={{ padding: '11px 14px' }}>Band</th>
                {dims.map(d => <th key={d} style={{ padding: '11px 8px', textAlign: 'center' }} title={DIM_LABEL[d] || d}>{(DIM_LABEL[d] || d).slice(0, 4)}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={4 + dims.length} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={4 + dims.length} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={4 + dims.length} style={{ padding: 18, color: 'var(--text-muted)' }}>No vendors.</td></tr>
                : rows.map(r => (
                  <tr key={r.vendor_id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px' }}><button onClick={() => setExpanded(expanded === r.vendor_id ? null : r.vendor_id)} style={iconBtn}>{expanded === r.vendor_id ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)', fontWeight: 600 }}>{r.vendor}<div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 400 }}>{r.vendor_code} · {fmt(r.status)}</div></td>
                    <td style={{ padding: '10px 14px' }}><span style={{ fontSize: 18, fontWeight: 900, color: scoreTone(r.overall_score) }}>{r.overall_score}</span></td>
                    <td style={{ padding: '10px 14px' }}><span style={{ width: 26, height: 26, borderRadius: 8, background: `${BAND_TONE[r.band]}22`, color: BAND_TONE[r.band], fontWeight: 900, fontSize: 13, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>{r.band}</span></td>
                    {dims.map(d => {
                      const s = r.dimensions?.[d] ?? 0
                      return <td key={d} style={{ padding: '10px 8px', textAlign: 'center', color: scoreTone(s), fontWeight: 700, fontSize: 12 }}>{s}</td>
                    })}
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {expanded && rows && <ExpandedDetail vendorId={expanded} />}
    </div>
  )
}

function ExpandedDetail({ vendorId }) {
  const [d, setD] = useState(null)
  useEffect(() => { purchaseApi.vpi.vendor(vendorId).then(setD).catch(() => setD(null)) }, [vendorId])
  if (!d) return null

  return (
    <div className="pr-glass" style={{ marginTop: 12, padding: 18, borderRadius: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        <Gauge size={18} style={{ color: '#38bdf8' }} />
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{d.company_name} — index {d.overall_score} (Band {d.band})</h2>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(220px,1fr))', gap: 10 }}>
        {Object.entries(d.dimensions || {}).map(([k, dim]) => (
          <div key={k} style={{ padding: '10px 12px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 6 }}>
              <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{dim.label || DIM_LABEL[k] || k}</span>
              <span style={{ fontSize: 15, fontWeight: 900, color: scoreTone(dim.score) }}>{dim.score}</span>
            </div>
            <div className="pr-bar" style={{ height: 6 }}><span style={{ width: `${dim.score}%`, background: `linear-gradient(90deg,${scoreTone(dim.score)}bb,${scoreTone(dim.score)})` }} /></div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 5 }}>
              {Object.entries(dim.detail || {}).filter(([, v]) => typeof v !== 'object').map(([dk, dv]) => `${fmt(dk)}: ${dv}`).join(' · ')}
            </div>
          </div>
        ))}
      </div>
      <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '12px 0 0' }}>Weighted per config/purchase_vpi.php · scored directly from the Purchase governance engines.</p>
    </div>
  )
}

const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
