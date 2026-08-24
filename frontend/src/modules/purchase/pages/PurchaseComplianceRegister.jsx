import { useState, useEffect, useCallback } from 'react'
import { ShieldCheck, RefreshCw, ChevronDown, ChevronRight, Search } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase compliance register — the Purchase-side mirror of the TPV engine
// (parity rule). Per-vendor register across 14 categories; expiry drives status
// automatically (Rule 8).
const STATUS_TONE = {
  Compliant: '#10b981', Partially_Compliant: '#f59e0b', Non_Compliant: '#ef4444',
  Expiring: '#f59e0b', Expired: '#ef4444', Waived: '#0ea5e9', Under_Review: '#94a3b8',
}
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function PurchaseComplianceRegister() {
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [meta, setMeta] = useState({ categories: [], statuses: [] })
  const [vendors, setVendors] = useState([])
  const [expanded, setExpanded] = useState(null)
  const [q, setQ] = useState('')

  const load = useCallback(() => {
    purchaseApi.vendorCompliance.roster().then(d => { setLoadError(null); setRows(d?.data ?? []); setMeta({ categories: d?.categories ?? [], statuses: d?.statuses ?? [] }) }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const rosterById = Object.fromEntries((rows || []).map(r => [r.vendor_id, r]))
  const allRows = vendors
    .filter(v => !q || (v.company_name || '').toLowerCase().includes(q.toLowerCase()))
    .map(v => rosterById[v.id] || { vendor_id: v.id, vendor: v.company_name, vendor_code: v.purchase_vendor_code, tracked: 0, ok: 0, problems: 0, expiring: 0, percent: 0 })
    .sort((a, b) => a.percent - b.percent)

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Compliance Register</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Every purchase vendor across 14 categories — expiry drives status automatically.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ position: 'relative', maxWidth: 320, marginBottom: 12 }}>
        <Search size={15} style={{ position: 'absolute', left: 11, top: 10, color: 'var(--text-muted)' }} />
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search vendors…" style={{ width: '100%', padding: '8px 12px 8px 34px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }} />
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Vendor', 'Compliance', 'Tracked', 'Problems', 'Expiring'].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={6} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : allRows.length === 0 ? <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>No vendors.</td></tr>
                : allRows.map(r => (
                  <VendorRow key={r.vendor_id} r={r} meta={meta} expanded={expanded === r.vendor_id}
                    onToggle={() => setExpanded(expanded === r.vendor_id ? null : r.vendor_id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function VendorRow({ r, meta, expanded, onToggle, onChanged }) {
  const pct = r.percent ?? 0
  const tone = pct >= 80 ? '#10b981' : pct >= 50 ? '#f59e0b' : '#ef4444'
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px' }}>
          <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{r.vendor}</div>
          <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.vendor_code}</div>
        </td>
        <td style={{ padding: '10px 14px', width: 170 }}>
          <div className="pr-bar" style={{ height: 8 }}><span style={{ width: `${pct}%`, background: `linear-gradient(90deg,${tone}bb,${tone})` }} /></div>
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{pct}% compliant</div>
        </td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.tracked}/14</td>
        <td style={{ padding: '10px 14px' }}>{r.problems > 0 ? <span style={{ color: '#ef4444', fontWeight: 700 }}>{r.problems}</span> : <span style={{ color: 'var(--text-muted)' }}>0</span>}</td>
        <td style={{ padding: '10px 14px' }}>{r.expiring > 0 ? <span style={{ color: '#f59e0b', fontWeight: 700 }}>{r.expiring}</span> : <span style={{ color: 'var(--text-muted)' }}>0</span>}</td>
      </tr>
      {expanded && (
        <tr><td colSpan={6} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(56,189,248,0.03))' }}>
          <Matrix vendorId={r.vendor_id} statuses={meta.statuses} onChanged={onChanged} />
        </td></tr>
      )}
    </>
  )
}

function Matrix({ vendorId, statuses, onChanged }) {
  const [matrix, setMatrix] = useState(null)
  const load = useCallback(() => { purchaseApi.vendorCompliance.matrix(vendorId).then(d => setMatrix(d?.matrix ?? [])).catch(() => setMatrix([])) }, [vendorId])
  useEffect(load, [load])

  const save = async (cat, patch) => {
    const current = matrix.find(m => m.category === cat)
    await purchaseApi.vendorCompliance.upsert(vendorId, {
      category: cat,
      status: patch.status ?? current.status ?? 'Under_Review',
      valid_until: patch.valid_until !== undefined ? (patch.valid_until || null) : (current.valid_until || null),
      requirement: current.requirement || null,
      notes: current.notes || null,
    })
    load(); onChanged?.()
  }

  if (!matrix) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading matrix…</div>
  const opts = statuses.length ? statuses : ['Compliant', 'Partially_Compliant', 'Non_Compliant', 'Waived', 'Under_Review']

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(280px,1fr))', gap: 8, paddingTop: 12 }}>
      {matrix.map(c => (
        <div key={c.category} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 11px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <span style={{ width: 9, height: 9, borderRadius: '50%', background: STATUS_TONE[c.status] || '#94a3b8', flexShrink: 0 }} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{c.category_label}</div>
            <div style={{ fontSize: 10.5, color: STATUS_TONE[c.status] || 'var(--text-muted)' }}>{fmt(c.status)}{c.valid_until ? ` · to ${new Date(c.valid_until).toLocaleDateString()}` : ''}</div>
          </div>
          <select value={c.stored_status || 'Under_Review'} onChange={e => save(c.category, { status: e.target.value })} style={sel} title="Set status">
            {opts.map(s => <option key={s} value={s}>{fmt(s)}</option>)}
          </select>
          <input type="date" defaultValue={c.valid_until || ''} onBlur={e => save(c.category, { valid_until: e.target.value })} style={{ ...sel, width: 120 }} title="Valid until" />
        </div>
      ))}
    </div>
  )
}

const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const sel = { padding: '4px 6px', borderRadius: 7, fontSize: 11, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
