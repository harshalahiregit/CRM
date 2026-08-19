import { useState, useEffect, useCallback } from 'react'
import { Plus, RefreshCw, Search, Download, Undo2, Pencil, Trash2, Send, CheckCircle2, Ban, X } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { fmtMoney, fmtDate } from '../constants'
import { KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Purchase → Order Returns.
 *
 * Goods returned to a Purchase Vendor (OR-#### series, line-level discounts).
 * A separate document from Debit Notes — this page never reads or writes
 * purchase_debit_notes. Purchase-owned: purchase_vendor_id only, no TPV, no
 * shared Vendor.
 */

const PER_PAGE_OPTIONS = [10, 25, 50, 100]
const STATUSES = ['Draft', 'Issued', 'Completed', 'Cancelled']
const statusCfg = (s) => ({
  Draft:     { label: 'Draft',     color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Issued:    { label: 'Issued',    color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Completed: { label: 'Completed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Cancelled: { label: 'Cancelled', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}[s] || { label: s || '—', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' })

const isoDaysAgo = (n) => {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

export default function PurchaseOrderReturns() {
  const [page, setPage] = useState({ data: [], total: 0, from: 0, to: 0, current_page: 1, last_page: 1 })
  const [loading, setLoading] = useState(true)
  const [vendors, setVendors] = useState([])

  // Filters — mirror the screen: From Date · To Date · Vendors · Status.
  // Clearing a date makes that bound unlimited.
  const [fromDate, setFromDate] = useState(isoDaysAgo(15))
  const [toDate, setToDate] = useState(isoDaysAgo(0))
  const [vendorId, setVendorId] = useState('All')
  const [status, setStatus] = useState('All')
  const [search, setSearch] = useState('')
  const [perPage, setPerPage] = useState(25)
  const [pageNo, setPageNo] = useState(1)
  const [editing, setEditing] = useState(null)

  useEffect(() => {
    purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {})
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    const params = { per_page: perPage, page: pageNo }
    if (fromDate) params.from_date = fromDate
    if (toDate) params.to_date = toDate
    if (vendorId !== 'All') params.purchase_vendor_id = vendorId
    if (status !== 'All') params.status = status
    if (search.trim()) params.search = search.trim()
    purchaseApi.orderReturns.list(params)
      .then(r => setPage(r?.data ? r : { data: r ?? [], total: 0, from: 0, to: 0, current_page: 1, last_page: 1 }))
      .catch(() => setPage({ data: [], total: 0, from: 0, to: 0, current_page: 1, last_page: 1 }))
      .finally(() => setLoading(false))
  }, [fromDate, toDate, vendorId, status, search, perPage, pageNo])

  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])
  useEffect(() => { setPageNo(1) }, [fromDate, toDate, vendorId, status, search, perPage])

  const rows = page.data || []

  const act = async (fn, row, confirmMsg) => {
    if (confirmMsg && !window.confirm(confirmMsg)) return
    try { await fn(row.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Action failed.') }
  }

  const doExport = () => exportCsv(stampedName('order-returns'), rows, [
    { label: 'Order Return Number', value: r => r.or_number },
    { label: 'Vendor', value: r => r.vendor?.company_name },
    { label: 'Total amount', value: r => r.subtotal },
    { label: 'Discount total', value: r => r.discount_total },
    { label: 'Total after discount', value: r => r.total },
    { label: 'Date created', value: r => fmtDate(r.created_at) },
    { label: 'Status', value: r => r.status },
  ])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Undo2 size={22} style={{ color: '#a78bfa' }} />
          <div>
            <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>RETURNS TO VENDOR</p>
            <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Order Returns</h1>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={doExport} disabled={!rows.length} style={ghostBtn}><Download size={14} /> Export</button>
          <button onClick={() => setEditing({})} style={solidBtn}><Plus size={15} /> New</button>
        </div>
      </div>

      {/* Filters */}
      <div className="pr-glass" style={{ padding: 16, borderRadius: 16, marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14 }}>
          <div>
            <label style={labelStyle}>From Date</label>
            <input type="date" value={fromDate} onChange={e => setFromDate(e.target.value)} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>To Date</label>
            <input type="date" value={toDate} onChange={e => setToDate(e.target.value)} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>Vendors</label>
            <select value={vendorId} onChange={e => setVendorId(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="All">All</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </div>
          <div>
            <label style={labelStyle}>Status</label>
            <select value={status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="All">All</option>
              {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
        </div>
      </div>

      {/* Rows-per-page + search */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        <select value={perPage} onChange={e => setPerPage(Number(e.target.value))} style={{ ...inputStyle, width: 90, cursor: 'pointer' }}>
          {PER_PAGE_OPTIONS.map(n => <option key={n} value={n}>{n}</option>)}
        </select>
        <div style={{ position: 'relative', marginLeft: 'auto', minWidth: 260 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search return number, vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 940 }}>
            <thead><tr>{['Order Return Number', 'Vendor', 'Total amount', 'Discount total', 'Total after discount', 'Date created', 'Status', ''].map((h, i) => (
              <th key={h} style={{ textAlign: i >= 2 && i <= 4 ? 'right' : 'left', padding: '11px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={8} style={{ padding: 28, textAlign: 'center', color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={8} style={{ padding: 28, color: 'var(--text-muted)', fontSize: 13 }}>No entries found</td></tr>
              ) : rows.map(r => {
                const cfg = statusCfg(r.status)
                return (
                  <tr key={r.id} className="pr-li-row">
                    <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>{r.or_number}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{r.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>{fmtMoney(r.subtotal, r.currency)}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>{fmtMoney(r.discount_total, r.currency)}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)', fontWeight: 700 }}>{fmtMoney(r.total, r.currency)}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(r.created_at)}</td>
                    <td style={{ padding: '11px 14px' }}><span style={{ padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{cfg.label}</span></td>
                    <td style={{ padding: '8px 14px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                      <div style={{ display: 'inline-flex', gap: 6 }}>
                        {r.status === 'Draft' && <IconBtn title="Edit" onClick={() => setEditing(r)}><Pencil size={13} /></IconBtn>}
                        {r.status === 'Draft' && <IconBtn title="Issue to vendor" color="#0ea5e9" onClick={() => act(purchaseApi.orderReturns.issue, r)}><Send size={13} /></IconBtn>}
                        {r.status === 'Issued' && <IconBtn title="Mark completed" color="#10b981" onClick={() => act(purchaseApi.orderReturns.complete, r)}><CheckCircle2 size={13} /></IconBtn>}
                        {r.status !== 'Completed' && r.status !== 'Cancelled' && <IconBtn title="Cancel" color="#f59e0b" onClick={() => act(purchaseApi.orderReturns.cancel, r, `Cancel ${r.or_number}?`)}><Ban size={13} /></IconBtn>}
                        {r.status === 'Draft' && <IconBtn title="Delete" color="#ef4444" onClick={() => act(purchaseApi.orderReturns.delete, r, `Delete ${r.or_number}? This cannot be undone.`)}><Trash2 size={13} /></IconBtn>}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {rows.length > 0 && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderTop: '1px solid var(--border)', flexWrap: 'wrap', gap: 10 }}>
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Showing {page.from ?? 0} to {page.to ?? 0} of {page.total ?? rows.length} entries</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <button onClick={() => setPageNo(p => Math.max(1, p - 1))} disabled={(page.current_page ?? 1) <= 1} style={pageBtn}>Previous</button>
              <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 700, minWidth: 26, textAlign: 'center' }}>{page.current_page ?? 1}</span>
              <button onClick={() => setPageNo(p => Math.min(page.last_page ?? 1, p + 1))} disabled={(page.current_page ?? 1) >= (page.last_page ?? 1)} style={pageBtn}>Next</button>
            </div>
          </div>
        )}
      </div>

      {editing && <ReturnModal orderReturn={editing} vendors={vendors} onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }} />}
    </div>
  )
}

// ── Create / edit an order return ────────────────────────────────────────────
const emptyLine = () => ({ description: '', qty: 1, unit: '', rate: 0, discount: 0, tax: 0 })

function ReturnModal({ orderReturn, vendors, onClose, onDone }) {
  const isNew = !orderReturn.id
  const [f, setF] = useState({
    purchase_vendor_id: orderReturn.purchase_vendor_id || '',
    return_date: (orderReturn.return_date || '').slice(0, 10) || new Date().toISOString().slice(0, 10),
    reason: orderReturn.reason || '',
    adjust_inventory: !!orderReturn.adjust_inventory,
    notes: orderReturn.notes || '',
  })
  const [lines, setLines] = useState(orderReturn.items?.length ? orderReturn.items.map(i => ({ ...i })) : [emptyLine()])
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const setLine = (i, k, v) => setLines(ls => ls.map((l, idx) => idx === i ? { ...l, [k]: v } : l))

  // Live totals — the backend recomputes authoritatively on save.
  const totals = lines.reduce((acc, l) => {
    const gross = (Number(l.qty) || 0) * (Number(l.rate) || 0)
    const disc = Math.min(Number(l.discount) || 0, gross)
    const net = gross - disc
    acc.subtotal += gross; acc.discount += disc; acc.tax += net * ((Number(l.tax) || 0) / 100)
    return acc
  }, { subtotal: 0, discount: 0, tax: 0 })
  const grand = totals.subtotal - totals.discount + totals.tax

  const save = async () => {
    if (!f.purchase_vendor_id) { setErr('Select a Purchase Vendor.'); return }
    const clean = lines.filter(l => l.description.trim())
    if (!clean.length) { setErr('Add at least one returned line.'); return }
    setSaving(true); setErr(null)
    try {
      const payload = {
        purchase_vendor_id: Number(f.purchase_vendor_id),
        return_date: f.return_date || null,
        reason: f.reason || null,
        adjust_inventory: !!f.adjust_inventory,
        notes: f.notes || null,
        items: clean.map(l => ({
          purchase_order_item_id: l.purchase_order_item_id || null,
          description: l.description,
          qty: Number(l.qty) || 0,
          unit: l.unit || null,
          rate: Number(l.rate) || 0,
          discount: Number(l.discount) || 0,
          tax: Number(l.tax) || 0,
        })),
      }
      if (isNew) await purchaseApi.orderReturns.create(payload)
      else await purchaseApi.orderReturns.update(orderReturn.id, payload)
      onDone()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save the order return.'))
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={900} showClose={false}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{isNew ? 'New Order Return' : `Edit · ${orderReturn.or_number}`}</h2>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Goods going back to a Purchase Vendor. The return number is generated on save.</p>
      </div>

      <div style={{ padding: '14px 22px 0', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Purchase Vendor *">
          <select value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={{ ...inputStyle, cursor: 'pointer' }}>
            <option value="">Select vendor…</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}{v.purchase_vendor_code ? ` · ${v.purchase_vendor_code}` : ''}</option>)}
          </select>
        </Field>
        <Field label="Return Date"><TextInput type="date" value={f.return_date} onChange={set('return_date')} /></Field>
        <Field label="Reason" full><TextInput value={f.reason} onChange={set('reason')} placeholder="Damaged on arrival, wrong item…" /></Field>
      </div>

      {/* Returned lines */}
      <div style={{ padding: '16px 22px 0' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
          <label style={labelStyle}>Returned items</label>
          <button onClick={() => setLines(ls => [...ls, emptyLine()])} style={{ ...ghostBtn, padding: '5px 10px', fontSize: 12 }}><Plus size={13} /> Add line</button>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 640 }}>
            <thead><tr>{['Description', 'Qty', 'Rate', 'Discount', 'Tax %', 'Amount', ''].map(h => (
              <th key={h} style={{ textAlign: 'left', padding: '6px 8px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {lines.map((l, i) => {
                const gross = (Number(l.qty) || 0) * (Number(l.rate) || 0)
                const net = gross - Math.min(Number(l.discount) || 0, gross)
                const amt = net + net * ((Number(l.tax) || 0) / 100)
                return (
                  <tr key={i}>
                    <td style={{ padding: '4px 8px', minWidth: 200 }}><input value={l.description} onChange={e => setLine(i, 'description', e.target.value)} style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                    <td style={{ padding: '4px 8px', width: 80 }}><input type="number" step="0.001" value={l.qty} onChange={e => setLine(i, 'qty', e.target.value)} style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                    <td style={{ padding: '4px 8px', width: 100 }}><input type="number" step="0.01" value={l.rate} onChange={e => setLine(i, 'rate', e.target.value)} style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                    <td style={{ padding: '4px 8px', width: 100 }}><input type="number" step="0.01" value={l.discount} onChange={e => setLine(i, 'discount', e.target.value)} style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                    <td style={{ padding: '4px 8px', width: 80 }}><input type="number" step="0.01" value={l.tax} onChange={e => setLine(i, 'tax', e.target.value)} style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                    <td style={{ padding: '4px 8px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{fmtMoney(amt)}</td>
                    <td style={{ padding: '4px 8px' }}>{lines.length > 1 && <button onClick={() => setLines(ls => ls.filter((_, idx) => idx !== i))} style={{ background: 'none', border: 'none', color: '#ef4444', cursor: 'pointer' }}><X size={14} /></button>}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 10 }}>
          <div style={{ display: 'grid', gap: 4, fontSize: 12.5, minWidth: 260 }}>
            <Row label="Total amount" value={fmtMoney(totals.subtotal)} />
            <Row label="Discount total" value={`− ${fmtMoney(totals.discount)}`} />
            <Row label="Tax" value={fmtMoney(totals.tax)} />
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 5, marginTop: 3 }}>
              <Row label="Total after discount" value={fmtMoney(grand)} strong />
            </div>
          </div>
        </div>
      </div>

      <div style={{ padding: '0 22px 20px' }}>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
        <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={isNew ? 'Create Return' : 'Save Changes'} />
      </div>
    </Overlay>
  )
}

const Row = ({ label, value, strong }) => (
  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 20 }}>
    <span style={{ color: 'var(--text-muted)', fontWeight: strong ? 800 : 500 }}>{label}</span>
    <span style={{ color: 'var(--text-h)', fontWeight: strong ? 900 : 600, fontVariantNumeric: 'tabular-nums' }}>{value}</span>
  </div>
)

const IconBtn = ({ children, title, color = 'var(--text-muted)', onClick }) => (
  <button title={title} onClick={onClick} style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color }}>{children}</button>
)

const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 14px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', border: 'none', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 800, boxShadow: '0 8px 20px -8px rgba(124,58,237,.7)' }
const pageBtn = { padding: '6px 14px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
