import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Send, Copy, Receipt, Trash2, ClipboardList, CheckCircle, XCircle, Download, CreditCard, X } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import StatusBadge from '../components/StatusBadge'
import ActivityTimeline from '../components/ActivityTimeline'

const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function InvoiceDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)
  const [showPayModal, setShowPayModal] = useState(false)
  const [payForm, setPayForm] = useState({ amount: '', mode: 'Bank Transfer', reference: '' })

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    salesApi.invoices.get(id).then(inv => { setInvoice(inv); setLoading(false) })
  }, [id])

  if (loading) return (
    <div className="space-y-4 animate-fade-in">
      {[1, 2, 3].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
    </div>
  )

  if (!invoice) return (
    <div className="flex flex-col items-center justify-center min-h-[50vh] gap-3">
      <p className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Invoice not found</p>
      <button onClick={() => navigate('/app/sales/invoices')} className="text-sm" style={{ color: '#a78bfa' }}>← Back to Invoices</button>
    </div>
  )

  const subtotal = invoice.items?.reduce((s, r) => s + r.amount, 0) || 0
  const taxTotal = invoice.items?.reduce((s, r) => s + (r.amount * r.tax / 100), 0) || 0

  const events = [
    { type: 'created', label: 'Invoice created', date: invoice.issue_date },
    invoice.status !== 'Draft' && { type: 'sent', label: 'Invoice sent to client', date: invoice.issue_date },
    ...(invoice.payments || []).map(p => ({ type: 'payment', label: `Payment received — ${fmt(p.amount)}`, detail: p.mode, date: p.date })),
    invoice.status === 'Paid' && { type: 'paid', label: 'Invoice fully paid', date: invoice.payments?.[invoice.payments.length - 1]?.date },
  ].filter(Boolean)

  const handlePay = async () => {
    if (!payForm.amount) return showToast('Amount required', 'error')
    await salesApi.invoices.recordPayment(invoice.id, payForm)
    showToast('Payment recorded!')
    setShowPayModal(false)
    setPayForm({ amount: '', mode: 'Bank Transfer', reference: '' })
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Top bar */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/invoices')} className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]" style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <div className="flex items-center gap-2">
              <p className="font-black text-base" style={{ color: 'var(--text-h)' }}>{invoice.number}</p>
              <StatusBadge status={invoice.status} size="lg" />
              {invoice.recurring && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6' }}>🔄 Recurring</span>}
            </div>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{invoice.client} · Due {fmtDate(invoice.due_date)}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {invoice.balance > 0 && (
            <button onClick={() => setShowPayModal(true)} className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 14px rgba(16,185,129,0.35)' }}>
              <CreditCard size={14} /> Record Payment
            </button>
          )}
          {[
            { icon: Send, label: 'Send', action: () => showToast('Invoice sent!') },
            { icon: Download, label: 'PDF', action: () => showToast('PDF ready!') },
            { icon: Copy, label: 'Duplicate', action: () => showToast('Duplicated!') },
          ].map(a => (
            <button key={a.label} onClick={a.action} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <a.icon size={13} /> {a.label}
            </button>
          ))}
          <button className="p-2 rounded-xl transition-colors hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid rgba(239,68,68,0.2)' }}>
            <Trash2 size={14} style={{ color: '#f87171' }} />
          </button>
        </div>
      </div>

      {/* Balance banner */}
      {invoice.balance > 0 && (
        <div className="flex items-center justify-between px-5 py-4 rounded-2xl" style={{ background: invoice.status === 'Overdue' ? 'rgba(239,68,68,0.06)' : 'rgba(124,58,237,0.06)', border: `1px solid ${invoice.status === 'Overdue' ? 'rgba(239,68,68,0.2)' : 'rgba(124,58,237,0.15)'}` }}>
          <div>
            <p className="text-xs font-semibold" style={{ color: invoice.status === 'Overdue' ? '#f87171' : '#a78bfa' }}>{invoice.status === 'Overdue' ? '⚠️ Overdue' : 'Outstanding Balance'}</p>
            <p className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{fmt(invoice.balance)}</p>
          </div>
          <div className="text-right">
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total: {fmt(invoice.amount)}</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Paid: {fmt(invoice.paid)}</p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Document */}
        <div className="lg:col-span-2 space-y-5">
          <div className="card-3d" style={{ padding: '28px' }}>
            {/* Header */}
            <div className="flex items-start justify-between mb-8">
              <div>
                <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-3"
                  style={{ background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 18px rgba(124,58,237,0.4)' }}>
                  <Receipt size={18} className="text-white" />
                </div>
                <p className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Invoice</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{invoice.number}</p>
              </div>
              <div className="text-right">
                <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Sangoe Technologies Pvt. Ltd.</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>hello@sangoe.in · GSTIN: 27AABCS1234E1Z5</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Mumbai, Maharashtra, India</p>
              </div>
            </div>

            {/* Client & Dates */}
            <div className="grid grid-cols-2 gap-6 mb-8 p-4 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div>
                <p className="label-caps mb-1">Bill To</p>
                <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{invoice.client}</p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div><p className="label-caps mb-1">Issue Date</p><p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(invoice.issue_date)}</p></div>
                <div><p className="label-caps mb-1">Due Date</p><p className="text-sm font-semibold" style={{ color: invoice.status === 'Overdue' ? '#f87171' : 'var(--text-h)' }}>{fmtDate(invoice.due_date)}</p></div>
              </div>
            </div>

            {/* Line Items */}
            {invoice.items && invoice.items.length > 0 ? (
              <div className="mb-6">
                <p className="label-caps mb-3">Line Items</p>
                <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <table className="w-full text-xs">
                    <thead><tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
                      {['Item', 'Description', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => <th key={h} className="px-4 py-2.5 text-left label-caps">{h}</th>)}
                    </tr></thead>
                    <tbody>
                      {invoice.items.map((item, i) => (
                        <tr key={i} style={{ borderBottom: i < invoice.items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                          <td className="px-4 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{item.item_name}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.description}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.qty}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{fmt(item.rate)}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.tax}%</td>
                          <td className="px-4 py-2.5 font-bold" style={{ color: '#a78bfa' }}>{fmt(item.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="mt-4 ml-auto w-64 space-y-1.5 text-xs">
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>Subtotal</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(subtotal)}</span></div>
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>GST</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(taxTotal)}</span></div>
                  <div className="flex justify-between pt-2 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}><span>Total</span><span style={{ color: '#a78bfa' }}>{fmt(invoice.amount)}</span></div>
                </div>
              </div>
            ) : (
              <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No line items added yet.</p>
            )}
          </div>

          {/* Payment History */}
          {invoice.payments && invoice.payments.length > 0 && (
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Payment History</h3>
              <div className="space-y-2">
                {invoice.payments.map((p, i) => (
                  <div key={i} className="flex items-center justify-between p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div>
                      <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{fmt(p.amount)}</p>
                      <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{p.mode} · {p.transaction_id}</p>
                    </div>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.date)}</p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Right panel */}
        <div className="space-y-4">
          <div className="card-3d" style={{ padding: '20px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Invoice Summary</h3>
            <div className="space-y-3 text-xs">
              {[
                { label: 'Client', value: invoice.client },
                { label: 'Status', value: <StatusBadge status={invoice.status} /> },
                { label: 'Total', value: fmt(invoice.amount) },
                { label: 'Paid', value: fmt(invoice.paid) },
                { label: 'Balance', value: fmt(invoice.balance) },
                { label: 'Issue Date', value: fmtDate(invoice.issue_date) },
                { label: 'Due Date', value: fmtDate(invoice.due_date) },
              ].map(row => (
                <div key={row.label} className="flex justify-between items-center">
                  <span style={{ color: 'var(--text-muted)' }}>{row.label}</span>
                  <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{row.value}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="card-3d" style={{ padding: '20px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity</h3>
            <ActivityTimeline events={events} />
          </div>
        </div>
      </div>

      {/* Pay Modal */}
      {showPayModal && (
        <div className="modal-backdrop" onClick={() => setShowPayModal(false)}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Record Payment</h2><button onClick={() => setShowPayModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="p-3 rounded-xl mb-4" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
              <p className="text-xs font-bold" style={{ color: '#a78bfa' }}>{invoice.number}</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Balance: {fmt(invoice.balance)}</p>
            </div>
            <div className="space-y-3">
              <div><label className="label">Amount *</label><input type="number" className="input-3d text-sm" placeholder="Payment amount" value={payForm.amount} onChange={e => setPayForm({ ...payForm, amount: e.target.value })} /></div>
              <div><label className="label">Payment Mode</label><select className="input-3d text-sm" value={payForm.mode} onChange={e => setPayForm({ ...payForm, mode: e.target.value })}>{['Bank Transfer', 'Cash', 'Cheque', 'Stripe', 'Razorpay', 'PayPal', 'UPI'].map(m => <option key={m} value={m}>{m}</option>)}</select></div>
              <div><label className="label">Reference</label><input className="input-3d text-sm" placeholder="Transaction ID" value={payForm.reference} onChange={e => setPayForm({ ...payForm, reference: e.target.value })} /></div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setShowPayModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={handlePay} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>Record Payment</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
