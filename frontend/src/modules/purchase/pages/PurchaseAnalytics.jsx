import { useState, useEffect, useCallback } from 'react'
import { RefreshCw, Download } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Reports & Analytics — the Purchase-side mirror of the TPV governance
// analytics (parity rule). Cross-module posture, trends and vendor benchmarks
// with CSV export. Distinct from the procurement /reports/* cost analytics.
const SERIES = [
  { k: 'ncrs', label: 'NCRs', c: '#ef4444' },
  { k: 'capas', label: 'CAPAs', c: '#22c55e' },
]
const DATASET_LABEL = { vendors: 'Vendors', ncrs: 'NCRs', capas: 'CAPAs', benchmark: 'Benchmark' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function PurchaseAnalytics() {
  const [data, setData] = useState(null)
  const [busy, setBusy] = useState('')

  const load = useCallback(() => {
    purchaseApi.analytics.get({ months: 6 }).then(setData).catch(() => setData({ error: true }))
  }, [])
  useEffect(() => { load() }, [load])

  const download = async (dataset) => {
    setBusy(dataset)
    try {
      const blob = await purchaseApi.analytics.export(dataset)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = `purchase-${dataset}.csv`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch { alert('Export failed.') } finally { setBusy('') }
  }

  if (!data) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading analytics…</div>
  if (data.error) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Could not load analytics.</div>

  const { overview, trends = [], benchmark = [], datasets = [] } = data
  const gov = overview?.governance || {}
  const kpis = [
    { k: 'Vendors', v: overview?.portfolio?.total ?? 0, c: '#38bdf8' },
    { k: 'Compliance', v: `${overview?.compliance?.percent ?? 0}%`, c: '#22c55e' },
    { k: 'Open NCRs', v: gov.ncr?.open ?? 0, c: '#ef4444', sub: `${gov.ncr?.overdue ?? 0} overdue` },
    { k: 'Open CAPAs', v: gov.capa?.open ?? 0, c: '#0ea5e9', sub: `${gov.capa?.overdue ?? 0} overdue` },
  ]
  const maxTrend = Math.max(1, ...trends.flatMap(t => SERIES.map(s => t[s.k] || 0)))

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>INTELLIGENCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Reports &amp; Analytics</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Vendor posture, governance trends and benchmarks — with CSV export.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', gap: 10, marginBottom: 18 }}>
        {kpis.map(c => (
          <div key={c.k} className="pr-glass" style={{ padding: '12px 14px', borderRadius: 12 }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c.c }}>{c.v}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.k}</div>
            {c.sub && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>{c.sub}</div>}
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: 18, borderRadius: 14, marginBottom: 18 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Governance activity — last 6 months</h2>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {SERIES.map(s => <span key={s.k} style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 9, height: 9, borderRadius: 2, background: s.c }} />{s.label}</span>)}
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 14, height: 150, overflowX: 'auto', paddingTop: 8 }}>
          {trends.map((t, i) => (
            <div key={i} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, minWidth: 54 }}>
              <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 110 }}>
                {SERIES.map(s => (
                  <div key={s.k} title={`${s.label}: ${t[s.k] || 0}`} style={{ width: 10, height: `${((t[s.k] || 0) / maxTrend) * 100}%`, minHeight: (t[s.k] ? 3 : 0), background: `linear-gradient(180deg,${s.c},${s.c}bb)`, borderRadius: '3px 3px 0 0' }} />
                ))}
              </div>
              <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{t.label}</div>
            </div>
          ))}
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 14, borderRadius: 14, marginBottom: 18 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 10 }}>Export CSV</div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {(datasets || []).map(d => (
            <button key={d} onClick={() => download(d)} disabled={busy === d} style={{ ...btnGhost, opacity: busy === d ? 0.6 : 1 }}>
              <Download size={14} /> {busy === d ? 'Exporting…' : (DATASET_LABEL[d] || d)}
            </button>
          ))}
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ padding: '14px 16px 4px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Vendor benchmark <span style={{ fontSize: 12, fontWeight: 500, color: 'var(--text-muted)' }}>· worst compliance first</span></div>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Vendor', 'Status', 'Compliance', 'Open NCRs', 'Open CAPAs'].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {benchmark.length === 0 ? <tr><td colSpan={5} style={{ padding: 18, color: 'var(--text-muted)' }}>No vendors.</td></tr>
                : benchmark.map(r => {
                  const pct = r.compliance_pct
                  const tone = pct == null ? '#6b7280' : pct >= 80 ? '#22c55e' : pct >= 50 ? '#f59e0b' : '#ef4444'
                  return (
                    <tr key={r.vendor_id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)', fontWeight: 600 }}>{r.vendor}<div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 400 }}>{r.vendor_code}</div></td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{fmt(r.status)}</td>
                      <td style={{ padding: '10px 14px', width: 150 }}>
                        {pct == null ? <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>Not tracked</span> : (
                          <>
                            <div className="pr-bar" style={{ height: 7 }}><span style={{ width: `${pct}%`, background: `linear-gradient(90deg,${tone}bb,${tone})` }} /></div>
                            <div style={{ fontSize: 10.5, color: tone, marginTop: 3 }}>{pct}%</div>
                          </>
                        )}
                      </td>
                      <td style={{ padding: '10px 14px' }}>{r.open_ncrs > 0 ? <b style={{ color: '#ef4444' }}>{r.open_ncrs}</b> : <span style={{ color: 'var(--text-muted)' }}>0</span>}</td>
                      <td style={{ padding: '10px 14px' }}>{r.open_capas > 0 ? <b style={{ color: '#0ea5e9' }}>{r.open_capas}</b> : <span style={{ color: 'var(--text-muted)' }}>0</span>}</td>
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

const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
