import { useState, useEffect, useCallback } from 'react'
import { FileText, ClipboardList, Plus, RefreshCw, X, Pencil, Trash2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §8 — Contracts & Work Orders. Two tabs over the TPV-owned commercial
// entities; per-entity create/edit/delete via a field-driven modal.
const CONTRACT_STATUSES = ['Draft', 'Active', 'Expiring', 'Expired', 'Renewed', 'Terminated', 'Closed']
const WO_STATUSES = ['Draft', 'Issued', 'In_Progress', 'Completed', 'Closed', 'Cancelled']
const STATUS_TONE = {
  Draft: '#94a3b8', Active: '#10b981', Issued: '#0ea5e9', In_Progress: '#0ea5e9',
  Expiring: '#f59e0b', Completed: '#10b981', Renewed: '#22c55e',
  Expired: '#ef4444', Terminated: '#ef4444', Cancelled: '#ef4444', Closed: '#6b7280',
}

const money = (v, ccy) => (v === null || v === undefined || v === '' ? '—' : `${ccy || 'INR'} ${Number(v).toLocaleString()}`)
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvContracts() {
  const [tab, setTab] = useState('contracts')
  const [contracts, setContracts] = useState(null)
  const [workOrders, setWorkOrders] = useState(null)
  const [vendors, setVendors] = useState([])
  const [modal, setModal] = useState(null) // { kind, row? }

  const load = useCallback(() => {
    tpvApi.contracts.list().then(d => setContracts(Array.isArray(d) ? d : [])).catch(() => setContracts([]))
    tpvApi.workOrders.list().then(d => setWorkOrders(Array.isArray(d) ? d : [])).catch(() => setWorkOrders([]))
  }, [])
  useEffect(() => {
    load()
    tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([]))
  }, [load])

  const remove = async (kind, id) => {
    if (!window.confirm('Delete this record?')) return
    await (kind === 'contract' ? tpvApi.contracts.delete(id) : tpvApi.workOrders.delete(id))
    load()
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>VENDORS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Contracts &amp; Work Orders</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>The commercial engagement behind each vendor relationship.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({ kind: tab === 'contracts' ? 'contract' : 'workOrder' })} style={btnPrimary}>
            <Plus size={15} /> New {tab === 'contracts' ? 'Contract' : 'Work Order'}
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 14 }}>
        {[['contracts', 'Contracts', FileText], ['work-orders', 'Work Orders', ClipboardList]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setTab(k)} style={tab === k ? tabOn : tabOff}>
            <Icon size={14} /> {label}
          </button>
        ))}
      </div>

      {tab === 'contracts'
        ? <ContractTable rows={contracts} onEdit={r => setModal({ kind: 'contract', row: r })} onDelete={id => remove('contract', id)} />
        : <WorkOrderTable rows={workOrders} onEdit={r => setModal({ kind: 'workOrder', row: r })} onDelete={id => remove('workOrder', id)} />}

      {modal && (
        <RecordModal
          kind={modal.kind}
          row={modal.row}
          vendors={vendors}
          contracts={contracts || []}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); load() }}
        />
      )}
    </div>
  )
}

function StatusPill({ status }) {
  const tone = STATUS_TONE[status] || '#94a3b8'
  return (
    <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${tone}1f`, color: tone, fontSize: 11, fontWeight: 700 }}>
      {String(status || 'Draft').replace(/_/g, ' ')}
    </span>
  )
}

function TableShell({ head, children, empty, loading }) {
  return (
    <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              {head.map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {loading ? <tr><td colSpan={head.length} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              : empty ? <tr><td colSpan={head.length} style={{ padding: 18, color: 'var(--text-muted)' }}>Nothing yet.</td></tr>
              : children}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ContractTable({ rows, onEdit, onDelete }) {
  return (
    <TableShell head={['Reference', 'Vendor', 'Title', 'Value', 'Period', 'WOs', 'Status', '']} loading={rows === null} empty={rows && rows.length === 0}>
      {(rows || []).map(c => (
        <tr key={c.id} style={{ borderTop: '1px solid var(--border)' }}>
          <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{c.reference}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{c.vendor?.company_name || '—'}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{c.title}</td>
          <td style={{ padding: '10px 14px', fontVariantNumeric: 'tabular-nums' }}>{money(c.contract_value, c.currency)}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(c.start_date)} → {date(c.end_date)}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{c.work_orders_count ?? 0}</td>
          <td style={{ padding: '10px 14px' }}><StatusPill status={c.status} /></td>
          <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
            <button onClick={() => onEdit(c)} style={iconBtn} title="Edit"><Pencil size={14} /></button>
            <button onClick={() => onDelete(c.id)} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
          </td>
        </tr>
      ))}
    </TableShell>
  )
}

function WorkOrderTable({ rows, onEdit, onDelete }) {
  return (
    <TableShell head={['Reference', 'Vendor', 'Title', 'Contract', 'Manpower', 'Period', 'Status', '']} loading={rows === null} empty={rows && rows.length === 0}>
      {(rows || []).map(w => (
        <tr key={w.id} style={{ borderTop: '1px solid var(--border)' }}>
          <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{w.reference}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{w.vendor?.company_name || '—'}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{w.title}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{w.contract?.reference || '—'}</td>
          <td style={{ padding: '10px 14px', fontVariantNumeric: 'tabular-nums' }}>{w.manpower_requirement ?? '—'}</td>
          <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(w.start_date)} → {date(w.end_date)}</td>
          <td style={{ padding: '10px 14px' }}><StatusPill status={w.status} /></td>
          <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
            <button onClick={() => onEdit(w)} style={iconBtn} title="Edit"><Pencil size={14} /></button>
            <button onClick={() => onDelete(w.id)} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
          </td>
        </tr>
      ))}
    </TableShell>
  )
}

// Field-driven modal form for both entities.
function RecordModal({ kind, row, vendors, contracts, onClose, onSaved }) {
  const isContract = kind === 'contract'
  const fields = isContract ? [
    { k: 'title', label: 'Title', req: true },
    { k: 'contract_type', label: 'Type' },
    { k: 'contract_value', label: 'Value', type: 'number' },
    { k: 'currency', label: 'Currency' },
    { k: 'start_date', label: 'Start date', type: 'date' },
    { k: 'end_date', label: 'End date', type: 'date' },
    { k: 'scope', label: 'Scope', area: true },
    { k: 'payment_terms', label: 'Payment terms', area: true },
    { k: 'sla', label: 'SLA', area: true },
    { k: 'kpi', label: 'KPI', area: true },
    { k: 'penalties', label: 'Penalties', area: true },
    { k: 'insurance_requirements', label: 'Insurance requirements', area: true },
    { k: 'hse_clauses', label: 'HSE clauses', area: true },
    { k: 'compliance_clauses', label: 'Compliance clauses', area: true },
    { k: 'renewal_terms', label: 'Renewal terms', area: true },
    { k: 'notes', label: 'Notes', area: true },
  ] : [
    { k: 'title', label: 'Title', req: true },
    { k: 'work_package', label: 'Work package' },
    { k: 'location', label: 'Location' },
    { k: 'quantity', label: 'Quantity' },
    { k: 'manpower_requirement', label: 'Manpower requirement', type: 'number' },
    { k: 'start_date', label: 'Start date', type: 'date' },
    { k: 'end_date', label: 'End date', type: 'date' },
    { k: 'scope', label: 'Scope', area: true },
    { k: 'equipment_requirement', label: 'Equipment requirement', area: true },
    { k: 'commercial_terms', label: 'Commercial terms', area: true },
    { k: 'notes', label: 'Notes', area: true },
  ]
  const statuses = isContract ? CONTRACT_STATUSES : WO_STATUSES

  const seed = () => {
    const base = { vendor_id: row?.vendor_id || '', status: row?.status || 'Draft' }
    if (!isContract) base.contract_id = row?.contract_id || ''
    fields.forEach(f => { base[f.k] = row?.[f.k] ?? '' })
    return base
  }
  const [form, setForm] = useState(seed)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      // Drop empty strings so nullable columns stay null.
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
      const api = isContract ? tpvApi.contracts : tpvApi.workOrders
      if (row) await api.update(row.id, payload)
      else await api.create(payload)
      onSaved()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save.')
    } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>
            {row ? `Edit ${row.reference}` : `New ${isContract ? 'Contract' : 'Work Order'}`}
          </h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Vendor *
            <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
              <option value="">Select vendor…</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          {!isContract && (
            <label style={lbl}>Contract
              <select value={form.contract_id} onChange={set('contract_id')} style={inp}>
                <option value="">None</option>
                {contracts.map(c => <option key={c.id} value={c.id}>{c.reference} · {c.title}</option>)}
              </select>
            </label>
          )}
          <label style={lbl}>Status
            <select value={form.status} onChange={set('status')} style={inp}>
              {statuses.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </label>
          {fields.map(f => (
            <label key={f.k} style={{ ...lbl, gridColumn: f.area ? '1 / -1' : 'auto' }}>
              {f.label}{f.req ? ' *' : ''}
              {f.area
                ? <textarea value={form[f.k]} onChange={set(f.k)} rows={2} style={{ ...inp, resize: 'vertical' }} />
                : <input type={f.type || 'text'} value={form[f.k]} onChange={set(f.k)} style={inp} />}
            </label>
          ))}
        </div>

        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.vendor_id || !form.title} style={{ ...btnPrimary, opacity: (saving || !form.vendor_id || !form.title) ? 0.6 : 1 }}>
            {saving ? 'Saving…' : row ? 'Save changes' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── inline styles ────────────────────────────────────────────────────────────
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const tabOn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 15px', borderRadius: 9, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const tabOff = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 15px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 600 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 620, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
