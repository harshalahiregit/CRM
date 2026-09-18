import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, RefreshCw, Search, Package, AlertTriangle, Loader2, Eye } from 'lucide-react'
import { transportOrderApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import TransportOrderForm, { emptyTransportOrder, validateTransportOrder, toTransportOrderPayload } from '../components/TransportOrderForm'
import {
  ORDER_STATUS_LABEL, orderStatusCfg, priorityCfg,
  fmtDateTime, fmtLocation,
} from '../constants'

/**
 * Transport Orders list (SNG-TRN-006).
 *
 * Status chips with counts, search, and a row that opens the record. Counts sit
 * above the list because they are that list's own filter — this is deliberately
 * NOT a dashboard: everything in STOS-OPS §96/§97 beyond order and trip counts
 * belongs to SNG-TRN-019 and to the tickets that own the underlying data.
 *
 * useState/useEffect rather than react-query, matching the 303 files in this
 * codebase that do the same. The module should look like the CRM it lives in.
 */
export default function TransportOrders() {
  const navigate = useNavigate()
  const toast = useToast()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [counts, setCounts] = useState({})
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyTransportOrder())
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [page, c] = await Promise.all([
        transportOrderApi.list({ status: status || undefined, search: search || undefined }),
        transportOrderApi.statusCounts(),
      ])
      setRows(Array.isArray(page?.data) ? page.data : [])
      setMeta(page ?? null)
      setCounts(c || {})
    } catch (e) {
      setError(e?.message || 'Could not load transport orders.')
    } finally {
      setLoading(false)
    }
  }, [status, search])

  useEffect(() => { load() }, [load])

  const submit = async () => {
    const problem = validateTransportOrder(form)
    if (problem) return toast.error(problem)

    setSaving(true)
    try {
      // toTransportOrderPayload, not `form`: the deadline leaves the browser as a
      // zoned instant. Sending the raw datetime-local value stored it as UTC.
      const created = await transportOrderApi.create(toTransportOrderPayload(form))
      toast.success(`Order ${created?.order_number ?? ''} created.`)
      setOpen(false); setForm(emptyTransportOrder())
      load()
    } catch (e) {
      toast.error(e?.message || 'The order could not be created.')
    } finally {
      setSaving(false)
    }
  }

  const total = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0)

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      {/* Header */}
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Transport</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Transport Orders</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>
            A customer's requirement to move goods. An approved order becomes a trip.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} disabled={loading}
            style={{ padding: '8px 13px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={() => { setForm(emptyTransportOrder()); setOpen(true) }}
            style={{ padding: '8px 14px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <Plus size={14} /> New Order
          </button>
        </div>
      </div>

      {/* Status filter chips — this list's own filter, not a dashboard. */}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <Chip active={status === ''} onClick={() => setStatus('')} label="All" count={total} />
        {Object.entries(ORDER_STATUS_LABEL).map(([key, label]) => (
          <Chip key={key} active={status === key} onClick={() => setStatus(key)}
            label={label} count={counts[key] || 0} cfg={orderStatusCfg(key)} />
        ))}
      </div>

      {/* Search */}
      <div style={{ position: 'relative', maxWidth: 420 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Order number, customer reference, service or route…"
          style={{ width: '100%', padding: '9px 12px 9px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none' }}
        />
      </div>

      {error && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 14px', borderRadius: 10, background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.30)', color: '#f87171', fontSize: 13 }}>
          <AlertTriangle size={15} /> {error}
        </div>
      )}

      {/* List */}
      <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
            <Loader2 size={18} className="animate-spin" style={{ display: 'inline' }} /> Loading orders…
          </div>
        ) : rows.length === 0 ? (
          <div style={{ padding: 48, textAlign: 'center' }}>
            <Package size={30} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
            <p style={{ color: 'var(--text-h)', fontSize: 15, fontWeight: 700, margin: 0 }}>No transport orders yet</p>
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 0' }}>
              {status || search ? 'Nothing matches this filter.' : 'Create the first order to start the operational chain.'}
            </p>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 900 }}>
              <thead>
                <tr>
                  {['Order', 'Customer', 'Route', 'Required by', 'Priority', 'Status', 'Trips', ''].map((h, i) => (
                    <th key={h || i} style={{ textAlign: i === 6 ? 'right' : 'left', padding: '10px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.08em', borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((o) => {
                  const st = orderStatusCfg(o.order_status)
                  const pr = priorityCfg(o.priority)
                  return (
                    <tr key={o.id}
                      onClick={() => navigate(`/app/transport/orders/${o.id}`)}
                      style={{ cursor: 'pointer', borderBottom: '1px solid var(--border-soft, var(--border))' }}>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 800, color: '#a78bfa', whiteSpace: 'nowrap' }}>{o.order_number}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>
                        {o.customer?.company || '—'}
                        {o.customer_reference && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{o.customer_reference}</div>}
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--text-muted)', maxWidth: 260 }}>
                        {o.route || fmtLocation(o.pickup_location)}
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDateTime(o.required_at)}</td>
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{ padding: '3px 9px', borderRadius: 999, background: pr.bg, color: pr.color, fontSize: 10.5, fontWeight: 800 }}>{o.priority}</span>
                      </td>
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{ padding: '3px 10px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 10.5, fontWeight: 800 }}>{st.label}</span>
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{o.trips_count ?? 0}</td>
                      <td style={{ padding: '11px 14px', textAlign: 'right' }}>
                        <Eye size={14} style={{ color: 'var(--text-muted)' }} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {meta?.total > 0 && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>
          Showing {rows.length} of {meta.total}
        </p>
      )}

      {/* New Order */}
      <Modal open={open} onClose={() => !saving && setOpen(false)} style={{ maxWidth: 760, width: '94vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>New Transport Order</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            The order is created as a draft. Submit it for validation once it is complete.
          </p>
        </div>
        <div style={{ padding: 22, maxHeight: '64vh', overflowY: 'auto' }}>
          <TransportOrderForm value={form} onChange={setForm} mode="create" />
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 9 }}>
          <button onClick={() => setOpen(false)} disabled={saving}
            style={{ padding: '9px 15px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>
            Cancel
          </button>
          <button onClick={submit} disabled={saving}
            style={{ padding: '9px 17px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: saving ? 'not-allowed' : 'pointer', opacity: saving ? 0.7 : 1, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            {saving && <Loader2 size={13} className="animate-spin" />} Create Order
          </button>
        </div>
      </Modal>
    </div>
  )
}

function Chip({ active, onClick, label, count, cfg }) {
  return (
    <button onClick={onClick}
      style={{
        padding: '6px 13px', borderRadius: 999, fontSize: 12, fontWeight: 700, cursor: 'pointer',
        background: active ? (cfg?.bg || 'rgba(124,58,237,0.16)') : 'var(--bg-input)',
        border: `1px solid ${active ? (cfg?.color || '#7C3AED') : 'var(--border)'}`,
        color: active ? (cfg?.color || '#a78bfa') : 'var(--text-muted)',
        display: 'inline-flex', alignItems: 'center', gap: 7,
      }}>
      {label}
      <span style={{ fontSize: 11, fontWeight: 800, opacity: 0.85, fontVariantNumeric: 'tabular-nums' }}>{count}</span>
    </button>
  )
}
