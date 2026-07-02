import { useState, useEffect } from 'react'
import { Plus, Send, Trash2, X, CreditCard, Banknote, Building2 } from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmt = (v) => '₹' + Number(v).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const mc = m => m === 'Bank Transfer' ? '#3b82f6' : m === 'Cash' ? '#10b981' : m === 'Cheque' ? '#f59e0b' : m === 'Razorpay' ? '#528ff0' : m === 'Stripe' ? '#635bff' : m === 'UPI' ? '#00baf2' : '#a78bfa'

export default function Payments() {
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [toast, setToast] = useState(null)
  const [form, setForm] = useState({ invoice_number: '', amount: '', mode: 'Bank Transfer', reference: '' })

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    setLoading(true)
    salesApi.payments.list({}).then(d => { setData(d); setLoading(false) })
  }, [])

  const totalAmt = data.reduce((s, p) => s + p.amount, 0)

  const handleRecord = async () => {
    if (!form.amount || !form.invoice_number) return showToast('Invoice & amount required', 'error')
    await salesApi.payments.record(form)
    showToast('Payment recorded!')
    setShowModal(false)
    setForm({ invoice_number: '', amount: '', mode: 'Bank Transfer', reference: '' })
    salesApi.payments.list({}).then(setData)
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">Sales & Revenue</p><h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}><span className="text-gradient">Payments</span></h1></div>
        <button onClick={() => setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15} /> Record Payment</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color: '#10b981' }}>{fmt(totalAmt)}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Total Collected</p></div>
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color: '#7C3AED' }}>{data.length}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>Total Payments</p></div>
        <div className="kpi-3d">
          <p className="text-sm font-bold mb-2" style={{ color: 'var(--text-h)' }}>By Mode</p>
          <div className="flex flex-wrap gap-1.5">
            {[...new Set(data.map(p => p.mode))].map(m => (
              <span key={m} className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: mc(m) + '18', color: mc(m) }}>{m} ({data.filter(p => p.mode === m).length})</span>
            ))}
          </div>
        </div>
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['Payment ID', 'Invoice', 'Client', 'Date', 'Amount', 'Mode', 'Reference', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
              </tr></thead>
              <tbody>
                {data.map(p => (
                  <tr key={p.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }} onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <td className="py-3 px-4 font-bold" style={{ color: '#a78bfa' }}>PAY-{String(p.id).padStart(3, '0')}</td>
                    <td className="py-3 px-4 font-semibold" style={{ color: 'var(--text-h)' }}>{p.invoice_number}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{p.client}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.date)}</td>
                    <td className="py-3 px-4 font-bold" style={{ color: '#10b981' }}>{fmt(p.amount)}</td>
                    <td className="py-3 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: mc(p.mode) + '18', color: mc(p.mode) }}>{p.mode}</span></td>
                    <td className="py-3 px-4 font-mono text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.reference || '—'}</td>
                    <td className="py-3 px-4">
                      <div className="flex gap-1">
                        <button onClick={() => showToast('Receipt sent!')} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]" title="Send Receipt"><Send size={12} style={{ color: 'var(--text-muted)' }} /></button>
                        <button onClick={() => showToast('Deleted!', 'error')} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)]" title="Delete"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
                      </div>
                    </td>
                  </tr>
                ))}
                {data.length === 0 && <tr><td colSpan="8" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No payments found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {showModal && (
        <div className="modal-backdrop" onClick={() => setShowModal(false)}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Record Payment</h2><button onClick={() => setShowModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="space-y-3">
              <div><label className="label">Invoice # *</label><input className="input-3d text-sm" placeholder="e.g. INV-2026-001" value={form.invoice_number} onChange={e => setForm({ ...form, invoice_number: e.target.value })} /></div>
              <div><label className="label">Amount *</label><input type="number" className="input-3d text-sm" placeholder="Payment amount" value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} /></div>
              <div><label className="label">Payment Mode</label><select className="input-3d text-sm" value={form.mode} onChange={e => setForm({ ...form, mode: e.target.value })}>{['Bank Transfer', 'Cash', 'Cheque', 'Stripe', 'Razorpay', 'PayPal', 'UPI'].map(m => <option key={m} value={m}>{m}</option>)}</select></div>
              <div><label className="label">Reference / Txn ID</label><input className="input-3d text-sm" placeholder="Transaction reference" value={form.reference} onChange={e => setForm({ ...form, reference: e.target.value })} /></div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleRecord} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>Record Payment</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
