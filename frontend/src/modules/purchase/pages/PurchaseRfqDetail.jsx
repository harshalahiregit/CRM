import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Send, Ban, Plus, Users, Package, Trophy, Award, CheckCircle2,
  Clock, AlertTriangle, Loader2, X, Star,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import {
  RFQ_STATUS, rfqStatusCfg, quoteStatusCfg, fmtMoney, fmtDate, canManagePR, canApprovePR,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput,
} from '@/components/ui/kit3d'

export default function PurchaseRfqDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManagePR(user)
  const admin = canApprovePR(user)

  const [rfq, setRfq] = useState(null)
  const [cmp, setCmp] = useState(null)
  const [loading, setLoad] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [recording, setRecording] = useState(false)
  const [awardId, setAwardId] = useState(null)

  const load = useCallback(() => {
    Promise.all([purchaseApi.rfqs.get(id), purchaseApi.rfqs.comparison(id)])
      .then(([r, c]) => { setRfq(r?.data ?? r); setCmp(c?.data ?? c); setLoad(false) })
      .catch(e => { if (e?.response?.status === 404) setNotFound(true); setLoad(false) })
  }, [id])
  useEffect(() => { load() }, [load])

  const doSend = async () => {
    setBusy(true); setErr(null)
    try { await purchaseApi.rfqs.send(id); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not send.') }
    finally { setBusy(false) }
  }
  const doCancel = async () => {
    if (!confirm('Cancel this RFQ?')) return
    setBusy(true); setErr(null)
    try { await purchaseApi.rfqs.cancel(id); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not cancel.') }
    finally { setBusy(false) }
  }

  if (loading) return <Wrap><div className="skeleton" style={{ height: 44, width: 260, borderRadius: 12, background: 'var(--border)', marginBottom: 16 }} /><div className="skeleton" style={{ height: 200, borderRadius: 16, background: 'var(--border)' }} /></Wrap>
  if (notFound || !rfq) return <Wrap><NotFound onBack={() => navigate('/app/purchase/quotations')} /></Wrap>

  const cfg = rfqStatusCfg(rfq.status)
  const items = rfq.items || []
  const recipients = rfq.rfq_vendors || []
  const quotations = rfq.quotations || []
  const canRecord = manage && rfq.status !== RFQ_STATUS.AWARDED && rfq.status !== RFQ_STATUS.CANCELLED && recipients.length > 0 && rfq.status !== RFQ_STATUS.DRAFT

  return (
    <Wrap>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/purchase/quotations')} style={backBtn}><ArrowLeft size={16} /></button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>{rfq.rfq_number}</p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{rfq.title}</h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>{cfg.label}</span>
          </div>
          <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 12, color: 'var(--text-muted)', marginTop: 5 }}>
            {rfq.department && <span>{rfq.department}</span>}
            {rfq.required_by && <span>Required by {fmtDate(rfq.required_by)}</span>}
            {rfq.closes_at && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} /> Quotes close {fmtDate(rfq.closes_at)}</span>}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
          {manage && rfq.status === RFQ_STATUS.DRAFT && <button onClick={doSend} disabled={busy} style={actBtn('#0ea5e9', true)}>{busy ? <Loader2 size={14} className="rfq-spin" /> : <Send size={14} />} Send to Vendors</button>}
          {canRecord && <button onClick={() => setRecording(true)} style={actBtn('#7C3AED', true)}><Plus size={14} /> Record Quote</button>}
          {manage && ![RFQ_STATUS.AWARDED, RFQ_STATUS.CANCELLED].includes(rfq.status) && <button onClick={doCancel} disabled={busy} style={actBtn('#ef4444')}><Ban size={14} /> Cancel</button>}
        </div>
      </div>

      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 16, alignItems: 'start' }}>
        {/* Left: comparison + items */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {quotations.length > 0 && cmp ? (
            <ComparisonCard cmp={cmp} admin={admin} onAward={setAwardId} awardable={rfq.status === RFQ_STATUS.UNDER_REVIEW} />
          ) : (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={Trophy}>Quotation Comparison</SectionTitle>
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>
                {rfq.status === RFQ_STATUS.DRAFT ? 'Send this RFQ to vendors, then record their quotes to compare here.' : 'No quotes recorded yet. Use “Record Quote” once vendors respond.'}
              </p>
            </div>
          )}

          {/* Requested items */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Package}>Requested Items <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {items.length}</span></SectionTitle>
            <div style={{ overflowX: 'auto', marginTop: 12 }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 420 }}>
                <thead><tr>{['Item', 'Qty', 'Unit', 'Target'].map((h, i) => <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '7px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>)}</tr></thead>
                <tbody>
                  {items.map(it => (
                    <tr key={it.id}>
                      <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}</td>
                      <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.qty}</td>
                      <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.unit || '—'}</td>
                      <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.target_rate ? fmtMoney(it.target_rate, rfq.currency) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Right: recipients */}
        <div className="pr-glass" style={{ padding: 20 }}>
          <SectionTitle icon={Users}>Recipients <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {recipients.length}</span></SectionTitle>
          {recipients.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No vendors added yet.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
              {recipients.map(rv => {
                const responded = rv.status === 'Responded'
                return (
                  <div key={rv.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px', borderRadius: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    {responded ? <CheckCircle2 size={15} style={{ color: '#10b981', flexShrink: 0 }} /> : <Clock size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
                    <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', flex: 1, minWidth: 0, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{rv.vendor?.company_name}</span>
                    <span style={{ fontSize: 10.5, fontWeight: 700, color: responded ? '#10b981' : 'var(--text-muted)' }}>{responded ? 'Responded' : 'Invited'}</span>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      {recording && <RecordQuoteModal rfq={rfq} onClose={() => setRecording(false)} onDone={() => { setRecording(false); load() }} />}
      {awardId && <AwardModal quotation={quotations.find(q => q.id === awardId)} onClose={() => setAwardId(null)} onDone={(po) => { setAwardId(null); navigate(`/app/purchase/orders`, { state: { highlight: po?.id } }) }} />}
    </Wrap>
  )
}

// ── Comparison matrix ─────────────────────────────────────────────────────────
function ComparisonCard({ cmp, admin, onAward, awardable }) {
  const quotes = cmp.quotations || []
  const rows = cmp.rows || []
  const currency = cmp.rfq?.currency || 'INR'
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={Trophy}>Quotation Comparison</SectionTitle>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '2px 0 14px' }}>Lowest rate per line is highlighted. Leading total is starred.</p>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 480 }}>
          <thead>
            <tr>
              <th style={{ textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>Item</th>
              {quotes.map(q => (
                <th key={q.id} style={{ textAlign: 'right', padding: '8px 10px', borderBottom: '1px solid var(--border)', minWidth: 120 }}>
                  <div style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 4, justifyContent: 'flex-end' }}>
                    {cmp.lowest_total_quotation_id === q.id && <Star size={11} style={{ color: '#f59e0b', fill: '#f59e0b' }} />}
                    {q.vendor}
                  </div>
                  <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 600 }}>{q.quotation_number}</div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map(row => (
              <tr key={row.rfq_item_id}>
                <td style={{ padding: '9px 10px', fontSize: 12.5, color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>
                  {row.description}<span style={{ color: 'var(--text-muted)' }}> · {row.qty}{row.unit ? ` ${row.unit}` : ''}</span>
                </td>
                {quotes.map(q => {
                  const cell = row.cells?.[q.id]
                  const isLow = cell && row.lowest_rate != null && Math.abs(cell.rate - row.lowest_rate) < 0.001
                  return (
                    <td key={q.id} style={{ padding: '9px 10px', textAlign: 'right', borderBottom: '1px solid var(--border)', fontVariantNumeric: 'tabular-nums',
                      background: isLow ? 'rgba(16,185,129,0.10)' : 'transparent' }}>
                      {cell ? (
                        <span style={{ fontSize: 12.5, fontWeight: isLow ? 800 : 600, color: isLow ? '#10b981' : 'var(--text-h)' }}>{fmtMoney(cell.rate, currency)}</span>
                      ) : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                    </td>
                  )
                })}
              </tr>
            ))}
            {/* Totals row */}
            <tr>
              <td style={{ padding: '10px', fontSize: 12, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Total</td>
              {quotes.map(q => {
                const lead = cmp.lowest_total_quotation_id === q.id
                return (
                  <td key={q.id} style={{ padding: '10px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    <div style={{ fontSize: 14, fontWeight: 900, color: lead ? '#f59e0b' : 'var(--text-h)' }}>{fmtMoney(q.total, currency)}</div>
                    {admin && awardable && (
                      <button onClick={() => onAward(q.id)} style={{ marginTop: 6, display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 9, cursor: 'pointer', fontSize: 11.5, fontWeight: 800, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#34d399,#10b981)', boxShadow: '0 6px 14px -4px #10b98188' }}>
                        <Award size={12} /> Award
                      </button>
                    )}
                    {q.status === 'Awarded' && <div style={{ marginTop: 6, fontSize: 10.5, fontWeight: 800, color: '#10b981' }}>✓ Awarded</div>}
                  </td>
                )
              })}
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Record quote modal ────────────────────────────────────────────────────────
function RecordQuoteModal({ rfq, onClose, onDone }) {
  const recipients = rfq.rfq_vendors || []
  const items = rfq.items || []
  const [vendorId, setVendorId] = useState('')
  const [rates, setRates] = useState(() => Object.fromEntries(items.map(it => [it.id, ''])))
  const [validUntil, setValidUntil] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  const total = items.reduce((sum, it) => {
    const rate = Number(rates[it.id]) || 0
    const base = rate * Number(it.qty || 0)
    return sum + base + base * (Number(it.tax || 0) / 100)
  }, 0)

  const save = async () => {
    if (!vendorId) { setErr('Choose the vendor this quote is from.'); return }
    const lines = items.filter(it => rates[it.id] !== '' && Number(rates[it.id]) >= 0)
    if (lines.length === 0) { setErr('Enter a rate for at least one line.'); return }
    setSaving(true); setErr(null)
    try {
      await purchaseApi.rfqs.recordQuote(rfq.id, {
        vendor_id: Number(vendorId),
        valid_until: validUntil || null,
        items: lines.map((it, i) => ({ purchase_rfq_item_id: it.id, description: it.description, qty: it.qty, unit: it.unit, rate: Number(rates[it.id]) || 0, tax: it.tax, sort_order: i })),
      })
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not record the quote.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={620}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Record Quotation</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Enter the rates a vendor quoted for {rfq.rfq_number}.</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: '58vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
          <Field label="Vendor *">
            <select value={vendorId} onChange={e => setVendorId(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="">Select a recipient…</option>
              {recipients.map(rv => <option key={rv.id} value={rv.vendor_id}>{rv.vendor?.company_name}{rv.status === 'Responded' ? ' (has quote)' : ''}</option>)}
            </select>
          </Field>
          <Field label="Valid until"><TextInput type="date" value={validUntil} onChange={e => setValidUntil(e.target.value)} /></Field>
        </div>

        <label style={{ ...labelStyle, marginTop: 10 }}>Rates per line</label>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
          {items.map(it => (
            <div key={it.id} style={{ display: 'grid', gridTemplateColumns: '2.4fr 1fr', gap: 10, alignItems: 'center' }}>
              <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}<span style={{ color: 'var(--text-muted)' }}> · {it.qty}{it.unit ? ` ${it.unit}` : ''} · {it.tax}% tax</span></div>
              <div style={{ position: 'relative' }}>
                <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', fontSize: 12, color: 'var(--text-muted)' }}>₹</span>
                <input type="number" min="0" value={rates[it.id]} onChange={e => setRates(p => ({ ...p, [it.id]: e.target.value }))} placeholder="rate"
                  style={{ ...inputStyle, paddingLeft: 22 }} />
              </div>
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12, fontSize: 13 }}>
          <span style={{ color: 'var(--text-muted)', marginRight: 10 }}>Quote total</span>
          <strong style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(total, rfq.currency)}</strong>
        </div>
        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 10 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Record Quote" color="#7C3AED" />
    </Overlay>
  )
}

// ── Award confirmation ────────────────────────────────────────────────────────
function AwardModal({ quotation, onClose, onDone }) {
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  if (!quotation) return null
  const confirm = async () => {
    setSaving(true); setErr(null)
    try { const res = await purchaseApi.quotations.award(quotation.id); onDone(res?.purchase_order) }
    catch (e) { setErr(e?.response?.data?.message || 'Could not award.'); setSaving(false) }
  }
  return (
    <Overlay onClose={onClose} width={440}>
      <div style={{ padding: '20px 22px 6px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}><Award size={19} style={{ color: '#10b981' }} /><h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Award to {quotation.vendor}</h2></div>
        <p style={{ margin: '8px 0 0', fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          This awards <strong style={{ color: 'var(--text-h)' }}>{quotation.quotation_number}</strong> ({fmtMoney(quotation.total, 'INR')}), creates a draft purchase order, and rejects the other quotes. This can't be undone.
        </p>
        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 12 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={confirm} loading={saving} confirmLabel="Award & create PO" color="#10b981" />
    </Overlay>
  )
}

/* ── bits ── */
const Wrap = ({ children }) => <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}><style>{PURCHASE_STYLE}</style><style>{`@keyframes rfqSpin{to{transform:rotate(360deg)}}.rfq-spin{animation:rfqSpin .9s linear infinite}`}</style>{children}</div>
const SectionTitle = ({ icon: Icon, children }) => <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Icon size={16} style={{ color: '#a78bfa' }} /><h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2></div>
const backBtn = { width: 36, height: 36, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 2, flexShrink: 0 }
const actBtn = (color, solid = false) => ({ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, background: solid ? `linear-gradient(145deg, ${color}dd, ${color})` : 'var(--bg-card)', border: solid ? 'none' : `1px solid ${color}55`, color: solid ? '#fff' : color, boxShadow: solid ? `0 8px 18px -6px ${color}88` : 'none' })
const Banner = ({ tone, icon: Icon, children }) => <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: `${tone}12`, border: `1px solid ${tone}55` }}><Icon size={15} style={{ color: tone, flexShrink: 0 }} /><span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span></div>
const NotFound = ({ onBack }) => (
  <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center', maxWidth: 460, margin: '40px auto' }}>
    <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(148,163,184,0.14)' }}><AlertTriangle size={26} style={{ color: '#94a3b8' }} /></div>
    <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>RFQ not found</h3>
    <button onClick={onBack} style={{ marginTop: 16, display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}><ArrowLeft size={15} /> Back to RFQs</button>
  </div>
)
