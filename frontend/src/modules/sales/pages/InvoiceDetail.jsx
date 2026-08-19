import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Send, Copy, Receipt, Trash2, Download, CreditCard, X, Banknote, AlertCircle } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import StatusBadge from '../components/StatusBadge'
import ActivityTimeline from '../components/ActivityTimeline'
import { useToast } from '@/hooks/useToast'

const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function InvoiceDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [showPayModal, setShowPayModal]       = useState(false)
  const [showCreditDrawer, setShowCreditDrawer] = useState(false)
  const [creditNotes, setCreditNotes]         = useState([])
  const [selectedCredit, setSelectedCredit]   = useState(null)
  const [applying, setApplying]               = useState(false)
  const [payForm, setPayForm] = useState({
    amount: '',
    date: new Date().toISOString().split('T')[0],
    mode: 'Bank Transfer',
    transaction_id: '',
    note: '',
    tds_amount: '',
    tds_section: '',
  })
  const [showTds, setShowTds] = useState(false)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)

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

  const events = [
    { type: 'created', label: 'Invoice created', date: invoice.issue_date },
    invoice.status !== 'Draft' && { type: 'sent', label: 'Invoice sent to client', date: invoice.issue_date },
    ...(invoice.payments || []).map(p => ({ type: 'payment', label: `Payment received — ${fmt(p.amount)}`, detail: p.mode, date: p.date })),
    invoice.status === 'Paid' && { type: 'paid', label: 'Invoice fully paid', date: invoice.payments?.[invoice.payments.length - 1]?.date },
  ].filter(Boolean)

  const handlePay = async () => {
    if (!payForm.amount) return showToast('Amount required', 'error')
    await salesApi.invoices.recordPayment(invoice.id, payForm)
    const fresh = await salesApi.invoices.get(id)
    setInvoice(fresh)
    showToast('Payment recorded!')
    setShowPayModal(false)
    setShowTds(false)
    setPayForm({ amount: '', date: new Date().toISOString().split('T')[0], mode: 'Bank Transfer', transaction_id: '', note: '', tds_amount: '', tds_section: '' })
  }

  const handleGenerateLink = async () => {
    try {
      const { token } = await salesApi.invoices.generatePublicLink(invoice.id)
      const url = `${window.location.origin}/portal/invoices/${invoice.id}?token=${token}`
      await navigator.clipboard.writeText(url)
      const fresh = await salesApi.invoices.get(id)
      setInvoice(fresh)
      showToast('Public link copied to clipboard!')
    } catch (e) {
      showToast(e.message || 'Failed to generate link', 'error')
    }
  }

  const markSent = async () => {
    try {
      await salesApi.invoices.send(invoice.id)
      showToast(`${invoice.number} marked as sent`)
      setInvoice(await salesApi.invoices.get(invoice.id))
    } catch (e) {
      showToast(e.message || 'Could not update the invoice', 'error')
    }
  }

  const handleSendReminder = async () => {
    try {
      await salesApi.invoices.sendPaymentReminder(invoice.id)
      showToast('Payment reminder sent!')
    } catch (e) {
      showToast(e.message || 'Failed to send reminder', 'error')
    }
  }

  const openCreditDrawer = async () => {
    setShowCreditDrawer(true)
    setSelectedCredit(null)
    const all = await salesApi.creditNotes.list({ status: 'Open' })
    // filter to same client only
    setCreditNotes(all.filter(cn => cn.client === invoice.client))
  }

  const handleApplyCredit = async () => {
    if (!selectedCredit) return showToast('Select a credit note first', 'error')
    setApplying(true)
    try {
      await salesApi.creditNotes.applyToInvoice(selectedCredit.id, invoice.id)
      showToast(`Credit note ${selectedCredit.number} applied — ₹${Number(selectedCredit.amount).toLocaleString('en-IN')} credited!`)
      setShowCreditDrawer(false)
    } catch {
      showToast('Failed to apply credit. Try again.', 'error')
    } finally {
      setApplying(false)
    }
  }

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]">
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
          {invoice.balance > 0 && (
            <button onClick={openCreditDrawer} className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold transition-all hover:scale-[1.02]"
              style={{ background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.25)', color: '#fbbf24' }}>
              <Banknote size={13} /> Apply Credit
            </button>
          )}
          {invoice.balance > 0 && (
            <button onClick={handleSendReminder} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <AlertCircle size={13} /> Send Reminder
            </button>
          )}
          <button onClick={handleGenerateLink} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            <Copy size={13} /> {invoice.public_link_token ? 'Copy Public Link' : 'Public Link'}
          </button>
          {/* "Send" marks the invoice sent (status + sent_at) — there is no invoice
              mailable, so it must not claim the customer was emailed. The PDF button
              was removed outright: no invoice PDF endpoint exists, and it showed
              "PDF ready!" having produced nothing. */}
          {[
            { icon: Send, label: 'Mark as Sent', action: markSent },
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
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total: {fmt(invoice.total)}</p>
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
            {invoice.line_items && invoice.line_items.length > 0 ? (
              <div className="mb-6">
                <p className="label-caps mb-3">Line Items</p>
                <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <table className="w-full text-xs">
                    <thead><tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
                      {['Item', 'HSN/SAC', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => <th key={h} className="px-4 py-2.5 text-left label-caps">{h}</th>)}
                    </tr></thead>
                    <tbody>
                      {invoice.line_items.map((item, i) => (
                        <tr key={i} style={{ borderBottom: i < invoice.line_items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                          <td className="px-4 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{item.item_name}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.hsn_sac_code || '—'}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.qty}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{fmt(item.rate)}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.tax}%</td>
                          <td className="px-4 py-2.5 font-bold" style={{ color: '#a78bfa' }}>{fmt(item.total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="mt-4 ml-auto w-64 space-y-1.5 text-xs">
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>Subtotal</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(invoice.subtotal)}</span></div>
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>After Discount</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(invoice.after_discount_amount)}</span></div>
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}>
                    <span className="flex items-center gap-1.5">
                      GST
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: invoice.gst_paid ? 'rgba(16,185,129,0.15)' : 'rgba(245,158,11,0.15)', color: invoice.gst_paid ? '#10b981' : '#fbbf24' }}>
                        {invoice.gst_paid ? 'GST Paid' : 'GST Not Paid'}
                      </span>
                    </span>
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(invoice.gst_amount)}</span>
                  </div>
                  <div className="flex justify-between pt-2 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}><span>Total</span><span style={{ color: '#a78bfa' }}>{fmt(invoice.total)}</span></div>
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
                { label: 'Total', value: fmt(invoice.total) },
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

      {/* ── Record Payment Drawer ── */}
      {showPayModal && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(480px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 12px rgba(16,185,129,0.4)' }}>
                    <CreditCard size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Record Payment</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Record a payment received for this invoice</p>
              </div>
              <button onClick={() => setShowPayModal(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body">
              {/* Invoice summary */}
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
                <p className="text-xs font-bold" style={{ color: '#a78bfa' }}>{invoice.number}</p>
                <div className="flex justify-between items-center mt-1">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{invoice.client}</p>
                  <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Balance: {fmt(invoice.balance)}</p>
                </div>
              </div>

              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="label">Amount *</label>
                    <input type="number" className="input-3d text-sm" placeholder={`Max: ${invoice.balance}`}
                      value={payForm.amount}
                      onChange={e => setPayForm({ ...payForm, amount: e.target.value })} />
                  </div>
                  <div>
                    <label className="label">Payment Date</label>
                    <input type="date" className="input-3d text-sm"
                      value={payForm.date}
                      onChange={e => setPayForm({ ...payForm, date: e.target.value })} />
                  </div>
                </div>

                <div>
                  <label className="label">Payment Mode</label>
                  <div className="grid grid-cols-4 gap-2">
                    {['Bank Transfer','Cash','Cheque','Stripe','Razorpay','PayPal','UPI'].map(m => {
                      const mc = m === 'Bank Transfer' ? '#3b82f6' : m === 'Cash' ? '#10b981' : m === 'Cheque' ? '#f59e0b' : m === 'Razorpay' ? '#528ff0' : m === 'Stripe' ? '#635bff' : m === 'UPI' ? '#00baf2' : '#a78bfa'
                      return (
                        <button key={m} onClick={() => setPayForm({ ...payForm, mode: m })}
                          className="py-2 px-1 rounded-xl text-[10px] font-bold transition-all text-center"
                          style={{
                            background: payForm.mode === m ? mc + '20' : 'var(--bg-input)',
                            color: payForm.mode === m ? mc : 'var(--text-muted)',
                            border: `1px solid ${payForm.mode === m ? mc + '60' : 'var(--border)'}`,
                          }}>
                          {m}
                        </button>
                      )
                    })}
                  </div>
                </div>

                <div>
                  <label className="label">Transaction / Reference ID</label>
                  <input className="input-3d text-sm" placeholder="Bank ref, UTR, Stripe charge ID…"
                    value={payForm.transaction_id}
                    onChange={e => setPayForm({ ...payForm, transaction_id: e.target.value })} />
                </div>

                <div>
                  <label className="label">Note</label>
                  <textarea className="input-3d text-sm resize-none" rows={3} placeholder="Optional payment note…"
                    value={payForm.note}
                    onChange={e => setPayForm({ ...payForm, note: e.target.value })} />
                </div>

                <button onClick={() => setShowTds(s => !s)}
                  className="text-xs font-bold flex items-center gap-1.5" style={{ color: '#a78bfa' }}>
                  {showTds ? '− Hide' : '+ Add'} TDS deduction
                </button>

                {showTds && (
                  <div className="grid grid-cols-2 gap-4 p-3 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div>
                      <label className="label">TDS Amount</label>
                      <input type="number" className="input-3d text-sm" placeholder="0.00"
                        value={payForm.tds_amount}
                        onChange={e => setPayForm({ ...payForm, tds_amount: e.target.value })} />
                    </div>
                    <div>
                      <label className="label">TDS Section</label>
                      <input className="input-3d text-sm" placeholder="e.g. 194C, 194J"
                        value={payForm.tds_section}
                        onChange={e => setPayForm({ ...payForm, tds_section: e.target.value })} />
                    </div>
                  </div>
                )}
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowPayModal(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handlePay}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.4)' }}>
                Record Payment
              </button>
            </div>
          </div>
        </>
      )}
      {/* ── Apply Credit Drawer ── */}
      {showCreditDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(500px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#f59e0b,#d97706)', boxShadow: '0 4px 12px rgba(245,158,11,0.4)' }}>
                    <Banknote size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Apply Credit Note</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Select an open credit note to offset against this invoice</p>
              </div>
              <button onClick={() => setShowCreditDrawer(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body">
              {/* Invoice summary */}
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
                <p className="text-xs font-bold" style={{ color: '#a78bfa' }}>{invoice.number}</p>
                <div className="flex justify-between items-center mt-1">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{invoice.client}</p>
                  <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Balance: {fmt(invoice.balance)}</p>
                </div>
              </div>

              {/* Credit notes list */}
              <div>
                <p className="label-caps mb-3">Open Credit Notes for {invoice.client}</p>
                {creditNotes.length === 0 ? (
                  <div className="flex flex-col items-center gap-2 py-8 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <AlertCircle size={28} style={{ color: 'var(--text-muted)' }} />
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No open credit notes</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>There are no open credit notes for {invoice.client}</p>
                  </div>
                ) : (
                  <div className="space-y-2">
                    {creditNotes.map(cn => {
                      const isSelected = selectedCredit?.id === cn.id
                      const covers     = Math.min(cn.amount, invoice.balance)
                      const remaining  = invoice.balance - covers
                      return (
                        <button key={cn.id} onClick={() => setSelectedCredit(isSelected ? null : cn)}
                          className="w-full text-left p-4 rounded-2xl transition-all"
                          style={{
                            background: isSelected ? 'rgba(245,158,11,0.08)' : 'var(--bg-input)',
                            border: `1px solid ${isSelected ? 'rgba(245,158,11,0.4)' : 'var(--border)'}`,
                          }}>
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex-1">
                              <div className="flex items-center gap-2">
                                <p className="text-xs font-black" style={{ color: isSelected ? '#fbbf24' : 'var(--text-h)' }}>{cn.number}</p>
                                <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>OPEN</span>
                              </div>
                              <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{cn.reason}</p>
                              <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>Ref: {cn.invoice_number}</p>
                            </div>
                            <div className="text-right flex-shrink-0">
                              <p className="text-sm font-black" style={{ color: isSelected ? '#fbbf24' : 'var(--text-h)' }}>{fmt(cn.amount)}</p>
                              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Credit value</p>
                            </div>
                          </div>
                          {isSelected && (
                            <div className="mt-3 pt-3 grid grid-cols-2 gap-3" style={{ borderTop: '1px solid rgba(245,158,11,0.2)' }}>
                              <div className="p-2.5 rounded-xl" style={{ background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.15)' }}>
                                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Will cover</p>
                                <p className="text-sm font-black" style={{ color: '#10b981' }}>{fmt(covers)}</p>
                              </div>
                              <div className="p-2.5 rounded-xl" style={{ background: remaining > 0 ? 'rgba(239,68,68,0.06)' : 'rgba(16,185,129,0.06)', border: `1px solid ${remaining > 0 ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)'}` }}>
                                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Remaining balance</p>
                                <p className="text-sm font-black" style={{ color: remaining > 0 ? '#f87171' : '#10b981' }}>{fmt(remaining)}</p>
                              </div>
                            </div>
                          )}
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowCreditDrawer(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handleApplyCredit} disabled={!selectedCredit || applying}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01] disabled:opacity-50 disabled:cursor-not-allowed"
                style={{ background: 'linear-gradient(135deg,#f59e0b,#d97706)', boxShadow: '0 6px 20px rgba(245,158,11,0.4)' }}>
                {applying ? 'Applying…' : selectedCredit ? `Apply ${selectedCredit.number}` : 'Select a Credit Note'}
              </button>
            </div>
          </div>
        </>
      )}
      </div>
    </>
  )
}
