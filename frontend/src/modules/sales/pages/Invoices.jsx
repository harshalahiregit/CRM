import { useState, useEffect } from 'react'
import { Plus, Search, Send, CreditCard, Trash2, X, MoreVertical, Copy, Bell } from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmt = (v) => '₹' + Number(v).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const sc = s => s === 'Paid' ? { bg: 'rgba(16,185,129,0.1)', color: '#10b981' } : s === 'Overdue' ? { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } : s === 'Partially Paid' ? { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' } : s === 'Unpaid' ? { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' } : s === 'Draft' ? { bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' } : { bg: 'rgba(239,68,68,0.08)', color: '#f87171' }

export default function Invoices() {
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showModal, setShowModal] = useState(false)
  const [showPayModal, setShowPayModal] = useState(false)
  const [selectedInv, setSelectedInv] = useState(null)
  const [toast, setToast] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm] = useState({ client: '', due_date: '' })
  const [payForm, setPayForm] = useState({ amount: '', mode: 'Bank Transfer', reference: '' })

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    setLoading(true)
    salesApi.invoices.list({ status: filter !== 'All' ? filter : undefined }).then(d => { setData(d); setLoading(false) })
  }, [filter])

  const stats = { total: data.length, unpaid: data.filter(i => i.status === 'Unpaid').length, paid: data.filter(i => i.status === 'Paid').length, overdue: data.filter(i => i.status === 'Overdue').length, totalAmt: data.reduce((s, i) => s + i.amount, 0), totalBal: data.reduce((s, i) => s + i.balance, 0) }

  const handleCreate = async () => {
    if (!form.client) return showToast('Client required', 'error')
    await salesApi.invoices.create({ ...form, amount: 0 })
    showToast('Invoice created!')
    setShowModal(false)
    setForm({ client: '', due_date: '' })
    salesApi.invoices.list({}).then(setData)
  }

  const handlePay = async () => {
    if (!payForm.amount) return showToast('Amount required', 'error')
    await salesApi.invoices.recordPayment(selectedInv.id, payForm)
    showToast('Payment recorded!')
    setShowPayModal(false)
    setPayForm({ amount: '', mode: 'Bank Transfer', reference: '' })
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">Sales & Revenue</p><h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}><span className="text-gradient">Invoices</span></h1></div>
        <button onClick={() => setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15} /> New Invoice</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color: '#7C3AED' }}>{fmt(stats.totalAmt)}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Total Invoiced</p></div>
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color: '#f87171' }}>{fmt(stats.totalBal)}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Outstanding</p></div>
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color: '#10b981' }}>{stats.paid}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Paid</p></div>
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color: '#f87171' }}>{stats.overdue}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Overdue</p></div>
      </div>

      <div className="flex gap-2 flex-wrap">
        {['All', 'Draft', 'Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'].map(f => (
          <button key={f} onClick={() => setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: filter === f ? '#fff' : 'var(--text-muted)', border: `1px solid ${filter === f ? 'transparent' : 'var(--border)'}` }}>{f}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['Invoice', 'Client', 'Issue Date', 'Due Date', 'Amount', 'Balance', 'Status', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
              </tr></thead>
              <tbody>
                {data.map(inv => {
                  const s = sc(inv.status)
                  return (
                    <tr key={inv.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }} onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-3 px-4 font-bold" style={{ color: '#a78bfa' }}>{inv.number}</td>
                      <td className="py-3 px-4 font-semibold" style={{ color: 'var(--text-h)' }}>{inv.client}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(inv.issue_date)}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(inv.due_date)}</td>
                      <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(inv.amount)}</td>
                      <td className="py-3 px-4 font-bold" style={{ color: inv.balance > 0 ? '#f87171' : '#10b981' }}>{fmt(inv.balance)}</td>
                      <td className="py-3 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{inv.status}{inv.recurring ? ' 🔄' : ''}</span></td>
                      <td className="py-3 px-4 relative">
                        <button onClick={() => setOpenMenu(openMenu === inv.id ? null : inv.id)} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{ color: 'var(--text-muted)' }} /></button>
                        {openMenu === inv.id && (
                          <div className="absolute right-4 top-10 z-50 rounded-xl shadow-2xl py-1 min-w-[170px]" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                            {[
                              { icon: CreditCard, label: 'Record Payment', action: () => { setSelectedInv(inv); setShowPayModal(true) } },
                              { icon: Send, label: 'Send Invoice', action: () => showToast('Invoice sent!') },
                              { icon: Bell, label: 'Send Reminder', action: () => showToast('Reminder sent!') },
                              { icon: Copy, label: 'Duplicate', action: () => showToast('Duplicated!') },
                              { icon: Trash2, label: 'Delete', action: () => showToast('Deleted!', 'error') },
                            ].map(a => (
                              <button key={a.label} onClick={() => { a.action(); setOpenMenu(null) }} className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium transition-colors hover:bg-[rgba(124,58,237,0.06)]" style={{ color: a.label === 'Delete' ? '#f87171' : 'var(--text-h)' }}>
                                <a.icon size={12} />{a.label}
                              </button>
                            ))}
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {data.length === 0 && <tr><td colSpan="8" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No invoices found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {showModal && (
        <div className="modal-backdrop" onClick={() => setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>New Invoice</h2><button onClick={() => setShowModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="space-y-3">
              <div><label className="label">Client *</label><select className="input-3d text-sm" value={form.client} onChange={e => setForm({ ...form, client: e.target.value })}><option value="">Select client</option>{salesApi.clients.map(c => <option key={c} value={c}>{c}</option>)}</select></div>
              <div><label className="label">Due Date</label><input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => setForm({ ...form, due_date: e.target.value })} /></div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Create Invoice</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {showPayModal && selectedInv && (
        <div className="modal-backdrop" onClick={() => setShowPayModal(false)}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Record Payment</h2><button onClick={() => setShowPayModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="p-3 rounded-xl mb-4" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
              <p className="text-xs font-bold" style={{ color: '#a78bfa' }}>{selectedInv.number}</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{selectedInv.client} · Balance: {fmt(selectedInv.balance)}</p>
            </div>
            <div className="space-y-3">
              <div><label className="label">Amount *</label><input type="number" className="input-3d text-sm" placeholder="Payment amount" value={payForm.amount} onChange={e => setPayForm({ ...payForm, amount: e.target.value })} /></div>
              <div><label className="label">Payment Mode</label><select className="input-3d text-sm" value={payForm.mode} onChange={e => setPayForm({ ...payForm, mode: e.target.value })}>{['Bank Transfer', 'Cash', 'Cheque', 'Stripe', 'Razorpay', 'PayPal', 'UPI'].map(m => <option key={m} value={m}>{m}</option>)}</select></div>
              <div><label className="label">Reference / Txn ID</label><input className="input-3d text-sm" placeholder="Transaction reference" value={payForm.reference} onChange={e => setPayForm({ ...payForm, reference: e.target.value })} /></div>
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
