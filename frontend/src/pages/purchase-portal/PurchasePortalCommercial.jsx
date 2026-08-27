import { useEffect, useMemo, useState } from 'react'
import { X, FileText, Loader2, Send, CheckCircle2 } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * Purchase Vendor Portal — Commercial documents (read-only). One component drives
 * every Commercial nav item via the `view` prop: orders, quotations, contracts,
 * invoices, debit-notes, payments, and the statement ledger. All data is scoped
 * server-side to the signed-in Purchase vendor; there is no vendor id in any URL.
 *
 * Everything here is read-only by design — the vendor sees what was raised against
 * them. (Vendor quotation SUBMISSION is a separate write flow, added next.)
 */

const money = (v, ccy = 'INR') => {
  const n = Number(v || 0)
  try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: ccy }).format(n) }
  catch { return n.toFixed(2) }
}
const date = (v) => (v ? String(v).slice(0, 10) : '—')

const STATUS_TONE = {
  paid: 'ok', approved: 'ok', active: 'ok', accepted: 'ok', issued: 'info', closed: 'muted',
  pending: 'warn', draft: 'muted', overdue: 'bad', cancelled: 'bad', rejected: 'bad', terminated: 'bad',
}
function Pill({ value }) {
  const label = String(value ?? '—').replace(/_/g, ' ')
  const tone = STATUS_TONE[String(value ?? '').toLowerCase()] || 'muted'
  const bg = { ok: 'rgba(34,197,94,0.15)', info: 'rgba(59,130,246,0.15)', warn: 'rgba(245,158,11,0.15)', bad: 'rgba(239,68,68,0.15)', muted: 'rgba(148,163,184,0.15)' }[tone]
  const fg = { ok: '#22c55e', info: '#3b82f6', warn: '#f59e0b', bad: '#ef4444', muted: '#94a3b8' }[tone]
  return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'capitalize', background: bg, color: fg }}>{label}</span>
}

// Per-view configuration. `detail` present ⇒ rows are clickable.
const VIEWS = {
  orders: {
    title: 'Purchase Orders', list: () => purchasePortalApi.commercial.orders(), detail: (id) => purchasePortalApi.commercial.order(id),
    cols: [
      { k: 'po_number', h: 'PO #', strong: true }, { k: 'title', h: 'Title' },
      { k: 'order_date', h: 'Date', fmt: date }, { k: 'expected_delivery_date', h: 'Expected', fmt: date },
      { k: 'total', h: 'Total', money: true, align: 'right' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  quotations: {
    title: 'Quotations', list: () => purchasePortalApi.commercial.quotations(), detail: (id) => purchasePortalApi.commercial.quotation(id),
    cols: [
      { k: 'quotation_number', h: 'Quote #', strong: true }, { k: 'valid_until', h: 'Valid Until', fmt: date },
      { k: 'received_at', h: 'Received', fmt: date }, { k: 'total', h: 'Total', money: true, align: 'right' },
      { k: 'status', h: 'Status', pill: true },
    ],
  },
  contracts: {
    title: 'Contracts', list: () => purchasePortalApi.commercial.contracts(), detail: (id) => purchasePortalApi.commercial.contract(id),
    cols: [
      { k: 'contract_number', h: 'Contract #', strong: true }, { k: 'title', h: 'Title' },
      { k: 'start_date', h: 'Start', fmt: date }, { k: 'end_date', h: 'End', fmt: date },
      { k: 'spend_ceiling', h: 'Ceiling', money: true, align: 'right' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  invoices: {
    title: 'Purchase Invoices', list: () => purchasePortalApi.commercial.invoices(), detail: (id) => purchasePortalApi.commercial.invoice(id),
    cols: [
      { k: 'invoice_number', h: 'Invoice #', strong: true }, { k: 'invoice_date', h: 'Date', fmt: date },
      { k: 'due_date', h: 'Due', fmt: date }, { k: 'total', h: 'Total', money: true, align: 'right' },
      { k: 'balance', h: 'Balance', money: true, align: 'right' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  'debit-notes': {
    title: 'Debit Notes', list: () => purchasePortalApi.commercial.debitNotes(), detail: (id) => purchasePortalApi.commercial.debitNote(id),
    cols: [
      { k: 'debit_number', h: 'Debit #', strong: true }, { k: 'debit_date', h: 'Date', fmt: date },
      { k: 'reason', h: 'Reason' }, { k: 'total', h: 'Total', money: true, align: 'right' },
      { k: 'balance', h: 'Balance', money: true, align: 'right' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  payments: {
    title: 'Payments', list: () => purchasePortalApi.commercial.payments(), detail: null,
    cols: [
      { k: 'payment_date', h: 'Date', fmt: date }, { k: 'reference', h: 'Reference' },
      { k: 'payment_mode', h: 'Mode' }, { k: 'invoice', h: 'Invoice', fmt: (v) => v?.invoice_number || '—' },
      { k: 'amount', h: 'Amount', money: true, align: 'right' },
    ],
  },
}

function cellValue(row, col) {
  const raw = row[col.k]
  if (col.money) return money(raw, row.currency)
  if (col.fmt) return col.fmt(raw)
  if (col.pill) return <Pill value={raw} />
  return raw ?? '—'
}

/* ── Detail modal ─────────────────────────────────────────────────────────── */
function DetailModal({ view, id, onClose }) {
  const [doc, setDoc] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    let live = true
    setLoading(true)
    VIEWS[view].detail(id).then(d => { if (live) setDoc(d) }).finally(() => live && setLoading(false))
    return () => { live = false }
  }, [view, id])

  const items = doc?.items || []
  const payments = doc?.payments || []
  const numberKey = VIEWS[view].cols[0].k

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 760, background: 'var(--bg-card, #14161c)', border: '1px solid var(--border, rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border, rgba(255,255,255,0.08))' }}>
          <FileText size={17} />
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>{doc?.[numberKey] || 'Document'}</strong>
          {doc?.status && <Pill value={doc.status} />}
          <button onClick={onClose} className="portal-icon-btn"><X size={16} /></button>
        </div>

        <div style={{ padding: 18 }}>
          {loading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="spin" size={22} /></div>
          ) : !doc ? (
            <p style={{ color: 'var(--text-muted)' }}>Could not load this document.</p>
          ) : (
            <>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12, marginBottom: 18 }}>
                {['title', 'order_date', 'invoice_date', 'debit_date', 'due_date', 'valid_until', 'start_date', 'end_date', 'reason', 'currency'].map(f => doc[f] != null && doc[f] !== '' && (
                  <div key={f}>
                    <div style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{f.replace(/_/g, ' ')}</div>
                    <div style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 600 }}>{/date$/.test(f) ? date(doc[f]) : doc[f]}</div>
                  </div>
                ))}
              </div>

              {items.length > 0 && (
                <>
                  <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', margin: '4px 0 8px' }}>LINE ITEMS</div>
                  <div style={{ overflowX: 'auto' }}>
                    <table className="pp-table">
                      <thead><tr><th>Description</th><th style={{ textAlign: 'right' }}>Qty</th><th>Unit</th><th style={{ textAlign: 'right' }}>Rate</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
                      <tbody>
                        {items.map((it, i) => (
                          <tr key={it.id ?? i}>
                            <td>{it.description || '—'}</td>
                            <td style={{ textAlign: 'right' }}>{it.qty ?? '—'}</td>
                            <td>{it.unit || '—'}</td>
                            <td style={{ textAlign: 'right' }}>{money(it.rate, doc.currency)}</td>
                            <td style={{ textAlign: 'right' }}>{money(it.amount, doc.currency)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 24, marginTop: 14, flexWrap: 'wrap' }}>
                {doc.subtotal != null && <Total label="Subtotal" value={money(doc.subtotal, doc.currency)} />}
                {doc.tax_total != null && <Total label="Tax" value={money(doc.tax_total, doc.currency)} />}
                {doc.total != null && <Total label="Total" value={money(doc.total, doc.currency)} strong />}
                {doc.balance != null && <Total label="Balance" value={money(doc.balance, doc.currency)} strong />}
              </div>

              {payments.length > 0 && (
                <>
                  <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', margin: '18px 0 8px' }}>PAYMENTS</div>
                  <div style={{ overflowX: 'auto' }}>
                    <table className="pp-table">
                      <thead><tr><th>Date</th><th>Reference</th><th>Mode</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
                      <tbody>
                        {payments.map((p, i) => (
                          <tr key={p.id ?? i}><td>{date(p.payment_date)}</td><td>{p.reference || '—'}</td><td>{p.payment_mode || '—'}</td><td style={{ textAlign: 'right' }}>{money(p.amount, doc.currency)}</td></tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
function Total({ label, value, strong }) {
  return (
    <div style={{ textAlign: 'right' }}>
      <div style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: strong ? 16 : 13.5, fontWeight: strong ? 800 : 600, color: 'var(--text-h)' }}>{value}</div>
    </div>
  )
}

/* ── Statement ledger ─────────────────────────────────────────────────────── */
function StatementView() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { purchasePortalApi.commercial.statement().then(setData).finally(() => setLoading(false)) }, [])
  if (loading) return <Center><Loader2 className="spin" size={22} /></Center>
  const lines = data?.lines || []
  return (
    <div className="pp-card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
        <h2 style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Statement of Account</h2>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 10.5, textTransform: 'uppercase', color: 'var(--text-muted)' }}>Closing Balance</div>
          <div style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)' }}>{money(data?.closing_balance)}</div>
        </div>
      </div>
      {lines.length === 0 ? <Empty /> : (
        <div style={{ overflowX: 'auto' }}>
          <table className="pp-table">
            <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th style={{ textAlign: 'right' }}>Balance</th></tr></thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i}>
                  <td>{date(l.date)}</td><td>{l.type}</td><td>{l.reference || '—'}</td>
                  <td style={{ textAlign: 'right' }}>{l.debit ? money(l.debit) : '—'}</td>
                  <td style={{ textAlign: 'right' }}>{l.credit ? money(l.credit) : '—'}</td>
                  <td style={{ textAlign: 'right', fontWeight: 700 }}>{money(l.balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }
function Empty() { return <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 48, fontSize: 14 }}>Nothing here yet.</div> }

/* ── RFQ invitations + quote submission (vendor write path) ───────────────── */
function RfqPanel({ onSubmitted }) {
  const [rfqs, setRfqs] = useState(null)
  const [quoting, setQuoting] = useState(null)   // the RFQ being quoted
  const reload = () => purchasePortalApi.commercial.rfqs().then(d => setRfqs(Array.isArray(d) ? d : [])).catch(() => setRfqs([]))
  useEffect(() => { reload() }, [])

  if (rfqs === null) return <div className="pp-card" style={{ marginBottom: 16 }}><Center><Loader2 className="spin" size={20} /></Center></div>
  if (rfqs.length === 0) return null

  return (
    <div className="pp-card" style={{ marginBottom: 16 }}>
      <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 4px' }}>Requests for Quotation</h2>
      <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px' }}>RFQs you were invited to. Submit your prices while the RFQ is open.</p>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {rfqs.map(r => (
          <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', border: '1px solid var(--border, rgba(255,255,255,0.08))', borderRadius: 10 }}>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13.5 }}>{r.rfq_number} · {r.title || 'RFQ'}</div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                {r.items?.length || 0} item(s){r.closes_at ? ` · closes ${r.closes_at}` : ''} · {r.status_label || r.status}
              </div>
            </div>
            {r.already_responded ? (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, color: '#22c55e' }}><CheckCircle2 size={14} /> Submitted</span>
            ) : r.can_quote ? (
              <button onClick={() => setQuoting(r)} className="pp-btn pp-btn-primary"><Send size={13} /> Submit Quote</button>
            ) : (
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Closed</span>
            )}
          </div>
        ))}
      </div>
      {quoting && <QuoteFormModal rfq={quoting} onClose={() => setQuoting(null)} onDone={() => { setQuoting(null); reload(); onSubmitted?.() }} />}
    </div>
  )
}

function QuoteFormModal({ rfq, onClose, onDone }) {
  const [lines, setLines] = useState(() => (rfq.items || []).map(it => ({
    purchase_rfq_item_id: it.id, description: it.description, qty: it.qty ?? 1, unit: it.unit || '', rate: '', tax: '',
  })))
  const [validUntil, setValidUntil] = useState('')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const setLine = (i, k, v) => setLines(ls => ls.map((l, j) => j === i ? { ...l, [k]: v } : l))
  const total = lines.reduce((s, l) => s + (Number(l.qty || 0) * Number(l.rate || 0)) * (1 + Number(l.tax || 0) / 100), 0)

  const submit = async () => {
    setError('')
    if (lines.some(l => l.rate === '' || Number(l.rate) < 0)) { setError('Enter a rate for every line item.'); return }
    setSaving(true)
    try {
      await purchasePortalApi.commercial.submitQuote(rfq.id, {
        items: lines.map(l => ({
          purchase_rfq_item_id: l.purchase_rfq_item_id, description: l.description,
          qty: Number(l.qty || 0), rate: Number(l.rate || 0), unit: l.unit || null, tax: Number(l.tax || 0),
        })),
        valid_until: validUntil || null, notes: notes || null,
      })
      onDone()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Could not submit the quotation.')
    } finally { setSaving(false) }
  }

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 720, background: 'var(--bg-card, #14161c)', border: '1px solid var(--border, rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border, rgba(255,255,255,0.08))' }}>
          <Send size={16} />
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>Submit Quote — {rfq.rfq_number}</strong>
          <button onClick={onClose} className="portal-icon-btn"><X size={16} /></button>
        </div>
        <div style={{ padding: 18 }}>
          <div style={{ overflowX: 'auto' }}>
            <table className="pp-table">
              <thead><tr><th>Item</th><th style={{ textAlign: 'right' }}>Qty</th><th>Unit</th><th style={{ textAlign: 'right', width: 120 }}>Rate *</th><th style={{ textAlign: 'right', width: 90 }}>Tax %</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={i}>
                    <td>{l.description || `Item ${i + 1}`}</td>
                    <td style={{ textAlign: 'right' }}>{l.qty}</td>
                    <td>{l.unit || '—'}</td>
                    <td><input type="number" min="0" step="0.01" value={l.rate} onChange={e => setLine(i, 'rate', e.target.value)} className="pp-input" /></td>
                    <td><input type="number" min="0" step="0.01" value={l.tax} onChange={e => setLine(i, 'tax', e.target.value)} className="pp-input" /></td>
                    <td style={{ textAlign: 'right' }}>{money((Number(l.qty || 0) * Number(l.rate || 0)) * (1 + Number(l.tax || 0) / 100), rfq.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 14 }}>
            <label style={{ fontSize: 12, color: 'var(--text-muted)' }}>Valid until<br /><input type="date" value={validUntil} onChange={e => setValidUntil(e.target.value)} className="pp-input" style={{ marginTop: 4 }} /></label>
            <label style={{ flex: 1, minWidth: 200, fontSize: 12, color: 'var(--text-muted)' }}>Notes<br /><input value={notes} onChange={e => setNotes(e.target.value)} className="pp-input" style={{ marginTop: 4, width: '100%' }} placeholder="Optional" /></label>
            <div style={{ textAlign: 'right', marginLeft: 'auto' }}>
              <div style={{ fontSize: 10.5, textTransform: 'uppercase', color: 'var(--text-muted)' }}>Quote Total</div>
              <div style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)' }}>{money(total, rfq.currency)}</div>
            </div>
          </div>

          {error && <div style={{ marginTop: 12, color: '#ef4444', fontSize: 13 }}>{error}</div>}

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
            <button onClick={onClose} className="pp-btn">Cancel</button>
            <button onClick={submit} disabled={saving} className="pp-btn pp-btn-primary">{saving ? <Loader2 className="spin" size={14} /> : <Send size={14} />} Submit Quotation</button>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── List page ────────────────────────────────────────────────────────────── */
export default function PurchasePortalCommercial({ view }) {
  if (view === 'statement') return <PageWrap><StatementView /></PageWrap>

  const cfg = VIEWS[view]
  const [rows, setRows] = useState(null)
  const [openId, setOpenId] = useState(null)
  const cols = useMemo(() => cfg?.cols || [], [cfg])

  const reload = () => cfg?.list().then(d => setRows(Array.isArray(d) ? d : (d?.data || []))).catch(() => setRows([]))
  useEffect(() => { setRows(null); setOpenId(null); reload() }, [view])

  if (!cfg) return <PageWrap><Empty /></PageWrap>

  return (
    <PageWrap>
      <style>{PP_TABLE_CSS}</style>
      {view === 'quotations' && <RfqPanel onSubmitted={reload} />}
      <div className="pp-card">
        <h2 style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 14px' }}>{cfg.title}</h2>
        {rows === null ? <Center><Loader2 className="spin" size={22} /></Center>
          : rows.length === 0 ? <Empty />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table className="pp-table">
                <thead><tr>{cols.map(c => <th key={c.k} style={{ textAlign: c.align || 'left' }}>{c.h}</th>)}</tr></thead>
                <tbody>
                  {rows.map(row => (
                    <tr key={row.id} onClick={cfg.detail ? () => setOpenId(row.id) : undefined} style={{ cursor: cfg.detail ? 'pointer' : 'default' }}>
                      {cols.map(c => (
                        <td key={c.k} style={{ textAlign: c.align || 'left', fontWeight: c.strong ? 700 : 400, color: c.strong ? 'var(--text-h)' : undefined }}>
                          {cellValue(row, c)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      </div>
      {openId != null && cfg.detail && <DetailModal view={view} id={openId} onClose={() => setOpenId(null)} />}
    </PageWrap>
  )
}

function PageWrap({ children }) {
  return <div style={{ maxWidth: 1040, margin: '0 auto', padding: '4px 2px' }}><style>{PP_TABLE_CSS}</style>{children}</div>
}

const PP_TABLE_CSS = `
.pp-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.pp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pp-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 8px 12px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.pp-table td { padding: 10px 12px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); vertical-align: middle; }
.pp-table tbody tr:hover { background: var(--bg-input, rgba(255,255,255,0.03)); }
.pp-input { background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 6px 9px; color: var(--text-h); font-size: 13px; width: 100%; }
.pp-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.pp-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.pp-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.pp-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.pp-btn-primary:disabled { opacity: 0.6; cursor: default; }
.spin { animation: pp-spin 0.9s linear infinite; }
@keyframes pp-spin { to { transform: rotate(360deg); } }
`
