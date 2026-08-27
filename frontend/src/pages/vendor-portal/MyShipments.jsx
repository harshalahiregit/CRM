import { useEffect, useState } from 'react'
import { Loader2, Plus, X, Send, Trash2, BellDot, Package as PackageIcon, Truck } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — Compliance & HSSE logistics (dispatch notices the vendor sends us).
 * `view="pre-alert"`: create a shipment pre-alert + see them all.
 * `view="packages"`: a flat list of the vendor's packages.
 * `view="shipping"`: track shipment status, advance it as it moves.
 */
export default function MyShipments({ view }) {
  switch (view) {
    case 'packages': return <Packages />
    case 'shipping': return <Shipping />
    default:         return <PreAlert />
  }
}

const date = v => (v ? String(v).slice(0, 10) : '—')
const STATUS_TONE = { 'pre-alert': 'warn', dispatched: 'info', 'in-transit': 'info', delivered: 'ok', cancelled: 'bad' }
function Pill({ value }) {
  const tone = STATUS_TONE[String(value ?? '').toLowerCase()] || 'muted'
  const bg = { ok: 'rgba(34,197,94,0.15)', info: 'rgba(59,130,246,0.15)', warn: 'rgba(245,158,11,0.15)', bad: 'rgba(239,68,68,0.15)', muted: 'rgba(148,163,184,0.15)' }[tone]
  const fg = { ok: '#22c55e', info: '#3b82f6', warn: '#f59e0b', bad: '#ef4444', muted: '#94a3b8' }[tone]
  return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: bg, color: fg }}>{value || '—'}</span>
}

/* ── Pre Alert — create + list ────────────────────────────────────────────── */
function PreAlert() {
  const [data, setData] = useState(null)
  const [adding, setAdding] = useState(false)
  const reload = () => portalApi.logistics.shipments().then(setData).catch(() => setData({ data: [], statuses: [] }))
  useEffect(() => { reload() }, [])
  const rows = data?.data || []
  return (
    <Wrap>
      <style>{CSS}</style>
      <Head title="Shipment Pre-Alerts" cta="New Pre-Alert" onCta={() => setAdding(true)} />
      {data === null ? <Center><Loader2 className="sh-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty icon={BellDot} text="No pre-alerts yet. Tell us about a shipment before you send it." />
        : (
          <Table head={['Ref', 'Courier', 'Tracking #', 'Packages', 'Expected', 'Status']}>
            {rows.map(s => (
              <tr key={s.id}>
                <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{s.reference}</td>
                <td>{s.courier || '—'}</td><td>{s.tracking_number || '—'}</td>
                <td>{s.packages_count ?? 0}</td><td>{date(s.expected_date)}</td><td><Pill value={s.status} /></td>
              </tr>
            ))}
          </Table>
        )}
      {adding && <ShipmentForm onClose={() => setAdding(false)} onDone={() => { setAdding(false); reload() }} />}
    </Wrap>
  )
}

function ShipmentForm({ onClose, onDone }) {
  const [f, setF] = useState({ courier: '', tracking_number: '', expected_date: '', notes: '' })
  const [pkgs, setPkgs] = useState([{ description: '', qty: 1, weight: '', dimensions: '' }])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k, v) => setF(s => ({ ...s, [k]: v }))
  const setPkg = (i, k, v) => setPkgs(ps => ps.map((p, j) => j === i ? { ...p, [k]: v } : p))
  const addPkg = () => setPkgs(ps => [...ps, { description: '', qty: 1, weight: '', dimensions: '' }])
  const delPkg = (i) => setPkgs(ps => ps.filter((_, j) => j !== i))

  const submit = async () => {
    setError('')
    const packages = pkgs.filter(p => p.description.trim()).map(p => ({ description: p.description, qty: Number(p.qty || 1), weight: p.weight || null, dimensions: p.dimensions || null }))
    setSaving(true)
    try { await portalApi.logistics.createShipment({ ...f, packages }); onDone() }
    catch (e) { setError(e?.response?.data?.message || 'Could not create the pre-alert.') }
    finally { setSaving(false) }
  }
  return (
    <Modal title="New Shipment Pre-Alert" onClose={onClose}>
      <div className="sh-grid">
        <Field label="Courier" value={f.courier} onChange={v => set('courier', v)} />
        <Field label="Tracking #" value={f.tracking_number} onChange={v => set('tracking_number', v)} />
        <Field label="Expected date" type="date" value={f.expected_date} onChange={v => set('expected_date', v)} />
        <Field label="Notes" value={f.notes} onChange={v => set('notes', v)} />
      </div>
      <div style={{ margin: '16px 0 8px', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>Packages</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {pkgs.map((p, i) => (
          <div key={i} style={{ display: 'grid', gridTemplateColumns: '2fr 60px 1fr 1fr 28px', gap: 6, alignItems: 'center' }}>
            <input className="sh-input" placeholder="Description" value={p.description} onChange={e => setPkg(i, 'description', e.target.value)} />
            <input className="sh-input" type="number" min="1" value={p.qty} onChange={e => setPkg(i, 'qty', e.target.value)} />
            <input className="sh-input" placeholder="Weight" value={p.weight} onChange={e => setPkg(i, 'weight', e.target.value)} />
            <input className="sh-input" placeholder="Dimensions" value={p.dimensions} onChange={e => setPkg(i, 'dimensions', e.target.value)} />
            <button className="sh-icon" onClick={() => delPkg(i)} disabled={pkgs.length === 1}><Trash2 size={14} /></button>
          </div>
        ))}
      </div>
      <button className="sh-btn" style={{ marginTop: 8 }} onClick={addPkg}><Plus size={13} /> Add package</button>
      <FormFoot error={error} saving={saving} onClose={onClose} onSubmit={submit} submitLabel="Create Pre-Alert" />
    </Modal>
  )
}

/* ── Packages — flat list ─────────────────────────────────────────────────── */
function Packages() {
  const [rows, setRows] = useState(null)
  useEffect(() => { portalApi.logistics.packages().then(d => setRows(d?.data || [])).catch(() => setRows([])) }, [])
  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 16px' }}>Packages</h2>
      {rows === null ? <Center><Loader2 className="sh-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty icon={PackageIcon} text="No packages yet. They appear here once you add a pre-alert." />
        : (
          <Table head={['Shipment', 'Description', 'Qty', 'Weight', 'Dimensions']}>
            {rows.map(p => (
              <tr key={p.id}>
                <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{p.shipment?.reference || '—'}</td>
                <td>{p.description}</td><td>{p.qty}</td><td>{p.weight || '—'}</td><td>{p.dimensions || '—'}</td>
              </tr>
            ))}
          </Table>
        )}
    </Wrap>
  )
}

/* ── Shipping — status tracking + advance ─────────────────────────────────── */
function Shipping() {
  const [data, setData] = useState(null)
  const reload = () => portalApi.logistics.shipments().then(setData).catch(() => setData({ data: [], statuses: [] }))
  useEffect(() => { reload() }, [])
  const rows = data?.data || []
  const statuses = data?.statuses || []
  const advance = async (id, status) => { await portalApi.logistics.updateStatus(id, status).catch(() => {}); reload() }
  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 16px' }}>Shipping Status</h2>
      {data === null ? <Center><Loader2 className="sh-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty icon={Truck} text="No shipments to track yet." />
        : (
          <Table head={['Ref', 'Courier', 'Tracking #', 'Dispatched', 'Delivered', 'Status']}>
            {rows.map(s => (
              <tr key={s.id}>
                <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{s.reference}</td>
                <td>{s.courier || '—'}</td><td>{s.tracking_number || '—'}</td>
                <td>{date(s.dispatched_on)}</td><td>{date(s.delivered_on)}</td>
                <td>
                  <select className="sh-input" style={{ padding: '4px 8px' }} value={s.status} onChange={e => advance(s.id, e.target.value)}>
                    {statuses.map(st => <option key={st} value={st}>{st}</option>)}
                  </select>
                </td>
              </tr>
            ))}
          </Table>
        )}
    </Wrap>
  )
}

/* ── shared bits ──────────────────────────────────────────────────────────── */
function Wrap({ children }) { return <div style={{ maxWidth: 940, margin: '0 auto' }}>{children}</div> }
function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }
function Head({ title, cta, onCta }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h2>
      <button className="sh-btn sh-btn-primary" onClick={onCta}><Plus size={15} /> {cta}</button>
    </div>
  )
}
function Empty({ icon: Icon, text }) { return <div className="sh-card" style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'var(--text-muted)', fontSize: 14 }}><Icon size={22} style={{ opacity: 0.6 }} /> {text}</div> }
function Table({ head, children }) {
  return (
    <div className="sh-card" style={{ padding: '6px 4px' }}>
      <div style={{ overflowX: 'auto' }}>
        <table className="sh-table"><thead><tr>{head.map(h => <th key={h}>{h}</th>)}</tr></thead><tbody>{children}</tbody></table>
      </div>
    </div>
  )
}
function Modal({ title, onClose, children }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 660, background: 'var(--bg-card,#14161c)', border: '1px solid var(--border,rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border,rgba(255,255,255,0.08))' }}>
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>{title}</strong>
          <button onClick={onClose} className="sh-icon"><X size={16} /></button>
        </div>
        <div style={{ padding: 18 }}>{children}</div>
      </div>
    </div>
  )
}
function FormFoot({ error, saving, onClose, onSubmit, submitLabel }) {
  return (
    <>
      {error && <div style={{ marginTop: 12, color: '#ef4444', fontSize: 13 }}>{error}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
        <button onClick={onClose} className="sh-btn">Cancel</button>
        <button onClick={onSubmit} disabled={saving} className="sh-btn sh-btn-primary">{saving ? <Loader2 className="sh-spin" size={14} /> : <Send size={14} />} {submitLabel}</button>
      </div>
    </>
  )
}
function Field({ label, value, onChange, type = 'text' }) { return <label className="sh-lbl">{label}<input type={type} value={value} onChange={e => onChange(e.target.value)} className="sh-input" /></label> }

const CSS = `
.sh-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.sh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sh-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.sh-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.sh-table tbody tr:last-child td { border-bottom: none; }
.sh-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.sh-lbl { font-size: 12px; color: var(--text-muted); display: flex; flex-direction: column; gap: 4px; }
.sh-input { width: 100%; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 7px 9px; color: var(--text-h); font-size: 13px; font-family: inherit; }
.sh-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.sh-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.sh-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.sh-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.sh-btn-primary:disabled { opacity: 0.6; cursor: default; }
.sh-icon { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 6px; border-radius: 6px; }
.sh-icon:hover:not(:disabled) { color: #ef4444; background: var(--bg-input, rgba(255,255,255,0.05)); }
.sh-icon:disabled { opacity: 0.3; cursor: default; }
.sh-spin { animation: sh-spin 0.9s linear infinite; }
@keyframes sh-spin { to { transform: rotate(360deg); } }
`
