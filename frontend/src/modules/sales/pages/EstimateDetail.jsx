import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Send, Copy, Receipt, Trash2, FileText,
  CheckCircle, XCircle, Download, X, User, Calendar,
  DollarSign, Tag
} from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import StatusBadge from '../components/StatusBadge'
import ActivityTimeline from '../components/ActivityTimeline'

const fmt     = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const PAY_MODES = ['Bank Transfer', 'Cash', 'Cheque', 'Stripe', 'Razorpay', 'PayPal', 'UPI']

export default function EstimateDetail() {
  const { id }   = useParams()
  const navigate = useNavigate()

  const [estimate, setEstimate]   = useState(null)
  const [loading, setLoading]     = useState(true)
  const [toast, setToast]         = useState(null)
  const [showConvert, setShowConvert] = useState(false)
  const [converting, setConverting]  = useState(false)

  const showToast = (msg, type = 'success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3500)
  }

  useEffect(() => {
    salesApi.estimates.get(id).then(e => { setEstimate(e); setLoading(false) })
  }, [id])

  if (loading) return (
    <div className="space-y-4 animate-[tiltIn_0.35s_ease_forwards]">
      {[1, 2, 3].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
    </div>
  )

  if (!estimate) return (
    <div className="flex flex-col items-center justify-center min-h-[50vh] gap-3">
      <p className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Estimate not found</p>
      <button onClick={() => navigate('/app/sales/estimates')} className="text-sm" style={{ color: '#a78bfa' }}>← Back to Estimates</button>
    </div>
  )

  // Totals from line items (if present), fallback to stored amount
  const subtotal = estimate.items?.reduce((s, r) => s + (r.qty * r.rate), 0) ?? estimate.amount ?? 0
  const taxTotal = estimate.items?.reduce((s, r) => s + ((r.qty * r.rate) * (r.tax / 100)), 0) ?? 0
  const grandTotal = subtotal + taxTotal

  const isEditable = !['Accepted', 'Declined', 'Expired'].includes(estimate.status)

  const events = [
    { type: 'created', label: 'Estimate created', date: estimate.created_at },
    estimate.status !== 'Draft' && { type: 'sent', label: 'Sent to customer', date: estimate.created_at },
    estimate.status === 'Accepted' && { type: 'accepted', label: 'Accepted by customer', date: estimate.valid_until },
    estimate.status === 'Declined' && { type: 'declined', label: 'Declined by customer', date: estimate.valid_until },
    estimate.status === 'Expired' && { type: 'declined', label: 'Estimate expired', date: estimate.valid_until },
  ].filter(Boolean)

  const handleConvertToInvoice = async () => {
    setConverting(true)
    try {
      await salesApi.estimates.convertToInvoice(estimate.id)
      showToast('Estimate converted to Invoice successfully!')
      setTimeout(() => navigate('/app/sales/invoices'), 1500)
    } catch {
      showToast('Conversion failed. Try again.', 'error')
    } finally {
      setConverting(false)
      setShowConvert(false)
    }
  }

  return (
    <>
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}
      <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">

        {/* Top bar */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <button onClick={() => navigate('/app/sales/estimates')}
              className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
              style={{ border: '1px solid var(--border)' }}>
              <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
            </button>
            <div>
              <div className="flex items-center gap-2 flex-wrap">
                <p className="font-black text-base" style={{ color: 'var(--text-h)' }}>
                  EST-{String(estimate.id).padStart(3, '0')}
                </p>
                <StatusBadge status={estimate.status} size="lg" />
              </div>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {estimate.client} · Valid until {fmtDate(estimate.valid_until)}
              </p>
            </div>
          </div>

          {/* Action buttons */}
          <div className="flex items-center gap-2 flex-wrap">
            {estimate.status === 'Accepted' && (
              <button
                onClick={() => setShowConvert(true)}
                className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:scale-[1.02]"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 14px rgba(16,185,129,0.35)' }}>
                <Receipt size={14} /> Convert to Invoice
              </button>
            )}
            {[
              { icon: Send,     label: 'Send',      action: () => showToast('Estimate sent to customer!') },
              { icon: Download, label: 'PDF',        action: () => showToast('PDF ready!') },
              { icon: Copy,     label: 'Duplicate',  action: () => showToast('Duplicated!') },
            ].map(a => (
              <button key={a.label} onClick={a.action}
                className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <a.icon size={13} /> {a.label}
              </button>
            ))}
            <button className="p-2 rounded-xl transition-colors hover:bg-[rgba(239,68,68,0.08)]"
              style={{ border: '1px solid rgba(239,68,68,0.2)' }}>
              <Trash2 size={14} style={{ color: '#f87171' }} />
            </button>
          </div>
        </div>

        {/* Status action banner */}
        {(estimate.status === 'Sent' || estimate.status === 'Draft') && (
          <div className="flex items-center justify-between px-5 py-4 rounded-2xl flex-wrap gap-3"
            style={{ background: 'rgba(124,58,237,0.05)', border: '1px solid rgba(124,58,237,0.15)' }}>
            <div>
              <p className="text-xs font-semibold" style={{ color: '#a78bfa' }}>Awaiting Customer Response</p>
              <p className="text-sm font-bold mt-0.5" style={{ color: 'var(--text-h)' }}>
                This estimate is pending acceptance. Total: {fmt(grandTotal)}
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => showToast('Status updated to Accepted!')}
                className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                <CheckCircle size={13} /> Mark Accepted
              </button>
              <button
                onClick={() => showToast('Status updated to Declined!')}
                className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold"
                style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
                <XCircle size={13} /> Mark Declined
              </button>
            </div>
          </div>
        )}

        {estimate.status === 'Expired' && (
          <div className="flex items-center gap-3 px-5 py-4 rounded-2xl"
            style={{ background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.2)' }}>
            <span className="text-lg">⏰</span>
            <div>
              <p className="text-xs font-semibold" style={{ color: '#fbbf24' }}>Estimate Expired</p>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>This estimate expired on {fmtDate(estimate.valid_until)}. Duplicate it to create a fresh one.</p>
            </div>
          </div>
        )}

        {/* Main grid */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">

          {/* Left: Document */}
          <div className="lg:col-span-2 space-y-5">
            <div className="card-3d" style={{ padding: '28px' }}>

              {/* Document header */}
              <div className="flex items-start justify-between mb-8">
                <div>
                  <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-3"
                    style={{ background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 18px rgba(124,58,237,0.4)' }}>
                    <FileText size={18} className="text-white" />
                  </div>
                  <p className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Estimate</p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    EST-{String(estimate.id).padStart(3, '0')}
                  </p>
                </div>
                <div className="text-right">
                  <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Sangoe Technologies Pvt. Ltd.</p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>hello@sangoe.in · GSTIN: 27AABCS1234E1Z5</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Mumbai, Maharashtra, India</p>
                </div>
              </div>

              {/* Customer & dates */}
              <div className="grid grid-cols-2 gap-6 mb-8 p-4 rounded-2xl"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div>
                  <p className="label-caps mb-1">Prepared For</p>
                  <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{estimate.client}</p>
                  {estimate.sale_agent && (
                    <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                      Agent: {estimate.sale_agent}
                    </p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="label-caps mb-1">Estimate Date</p>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(estimate.date || estimate.created_at)}</p>
                  </div>
                  <div>
                    <p className="label-caps mb-1">Valid Until</p>
                    <p className="text-sm font-semibold"
                      style={{ color: estimate.status === 'Expired' ? '#fbbf24' : 'var(--text-h)' }}>
                      {fmtDate(estimate.valid_until)}
                    </p>
                  </div>
                </div>
              </div>

              {/* Subject */}
              {estimate.subject && (
                <div className="mb-6">
                  <p className="label-caps mb-1">Subject</p>
                  <p className="font-bold" style={{ color: 'var(--text-h)' }}>{estimate.subject}</p>
                </div>
              )}

              {/* Line Items */}
              {estimate.items && estimate.items.length > 0 ? (
                <div className="mb-6">
                  <p className="label-caps mb-3">Line Items</p>
                  <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                    <table className="w-full text-xs">
                      <thead>
                        <tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
                          {['#', 'Item', 'Description', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => (
                            <th key={h} className="px-4 py-2.5 text-left label-caps">{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {estimate.items.map((item, i) => {
                          const lineAmt = (item.qty * item.rate)
                          const lineTotal = lineAmt + (lineAmt * item.tax / 100)
                          return (
                            <tr key={i} style={{ borderBottom: i < estimate.items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                              <td className="px-4 py-3 text-[10px]" style={{ color: 'var(--text-muted)' }}>{i + 1}</td>
                              <td className="px-4 py-3 font-semibold" style={{ color: 'var(--text-h)' }}>{item.name || item.item_name}</td>
                              <td className="px-4 py-3 max-w-[180px]" style={{ color: 'var(--text-muted)' }}>
                                <span className="truncate block">{item.description || '—'}</span>
                              </td>
                              <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{item.qty}</td>
                              <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{fmt(item.rate)}</td>
                              <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{item.tax}%</td>
                              <td className="px-4 py-3 font-bold" style={{ color: '#a78bfa' }}>{fmt(lineTotal)}</td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>

                  {/* Totals */}
                  <div className="mt-4 ml-auto w-72 space-y-2 text-xs">
                    <div className="flex justify-between py-1.5" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                      <span>Subtotal</span>
                      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(subtotal)}</span>
                    </div>
                    <div className="flex justify-between py-1.5" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                      <span>GST / Tax</span>
                      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(taxTotal)}</span>
                    </div>
                    <div className="flex justify-between pt-2 font-black text-sm" style={{ color: 'var(--text-h)' }}>
                      <span>Grand Total</span>
                      <span style={{ color: '#a78bfa' }}>{fmt(grandTotal)}</span>
                    </div>
                  </div>
                </div>
              ) : (
                <div className="mb-6 py-8 rounded-2xl text-center" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No line items added yet</p>
                  <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Total: {fmt(estimate.amount || 0)}</p>
                </div>
              )}

              {/* Client note */}
              {estimate.clientnote && (
                <div className="p-4 rounded-2xl mt-4" style={{ background: 'rgba(16,185,129,0.05)', border: '1px solid rgba(16,185,129,0.15)' }}>
                  <p className="label-caps mb-1" style={{ color: '#10b981' }}>Note to Customer</p>
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{estimate.clientnote}</p>
                </div>
              )}

              {/* Terms */}
              {estimate.terms && (
                <div className="p-4 rounded-2xl mt-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <p className="label-caps mb-1">Terms &amp; Conditions</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{estimate.terms}</p>
                </div>
              )}
            </div>
          </div>

          {/* Right panel */}
          <div className="space-y-4">

            {/* Summary card */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Estimate Summary</h3>
              <div className="space-y-3 text-xs">
                {[
                  { label: 'Reference', value: `EST-${String(estimate.id).padStart(3, '0')}` },
                  { label: 'Customer', value: estimate.client },
                  { label: 'Status', value: <StatusBadge status={estimate.status} /> },
                  { label: 'Total', value: fmt(grandTotal) },
                  { label: 'Date', value: fmtDate(estimate.date || estimate.created_at) },
                  { label: 'Valid Until', value: fmtDate(estimate.valid_until) },
                  estimate.sale_agent && { label: 'Sale Agent', value: estimate.sale_agent },
                  estimate.currency && { label: 'Currency', value: estimate.currency },
                ].filter(Boolean).map(row => (
                  <div key={row.label} className="flex justify-between items-center py-1"
                    style={{ borderBottom: '1px solid var(--border)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>{row.label}</span>
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{row.value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Admin note */}
            {estimate.adminnote && (
              <div className="card-3d" style={{ padding: '20px' }}>
                <div className="flex items-center gap-2 mb-3">
                  <span className="px-2 py-0.5 rounded text-[9px] font-bold"
                    style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>🔒 INTERNAL</span>
                  <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Admin Note</h3>
                </div>
                <p className="text-xs leading-relaxed" style={{ color: 'var(--text-muted)' }}>{estimate.adminnote}</p>
              </div>
            )}

            {/* Activity timeline */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity</h3>
              <ActivityTimeline events={events} />
            </div>
          </div>
        </div>
      </div>

      {/* ── Convert to Invoice confirmation drawer ── */}
      {showConvert && (
        <>
          <div className="drawer-backdrop" onClick={() => setShowConvert(false)} />
          <div className="drawer-panel" style={{ width: 'min(460px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 12px rgba(16,185,129,0.4)' }}>
                    <Receipt size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
                    Convert to Invoice
                  </h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>
                  This will create a new Invoice from this estimate
                </p>
              </div>
              <button onClick={() => setShowConvert(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body">
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)' }}>
                <p className="text-xs font-bold mb-1" style={{ color: '#10b981' }}>EST-{String(estimate.id).padStart(3, '0')}</p>
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{estimate.subject || estimate.client}</p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Total: {fmt(grandTotal)} · Customer: {estimate.client}</p>
              </div>
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.2)' }}>
                <p className="text-xs font-semibold mb-1" style={{ color: '#fbbf24' }}>⚠️ What happens next</p>
                <ul className="text-xs space-y-1" style={{ color: 'var(--text-muted)' }}>
                  <li>• A new Invoice will be created with all line items from this estimate</li>
                  <li>• The estimate status will remain unchanged</li>
                  <li>• You can edit the invoice before sending to the customer</li>
                </ul>
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowConvert(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handleConvertToInvoice} disabled={converting}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01] disabled:opacity-60"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.4)' }}>
                {converting ? 'Converting…' : 'Yes, Convert to Invoice'}
              </button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
