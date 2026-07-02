import { useState, useEffect } from 'react'
import { Plus, Search, Send, Copy, Receipt, Trash2, X, MoreVertical } from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmt = (v) => '₹' + Number(v).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const sc = s => s === 'Accepted' ? { bg: 'rgba(16,185,129,0.1)', color: '#10b981' } : s === 'Sent' ? { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' } : s === 'Draft' ? { bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' } : s === 'Declined' ? { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } : { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' }

export default function Estimates() {
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showModal, setShowModal] = useState(false)
  const [toast, setToast] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm] = useState({ subject: '', client: '', valid_until: '' })

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    setLoading(true)
    salesApi.estimates.list({ status: filter !== 'All' ? filter : undefined }).then(d => { setData(d); setLoading(false) })
  }, [filter])

  const stats = { total: data.length, draft: data.filter(e => e.status === 'Draft').length, sent: data.filter(e => e.status === 'Sent').length, accepted: data.filter(e => e.status === 'Accepted').length }

  const handleCreate = async () => {
    if (!form.subject || !form.client) return showToast('Subject & client required', 'error')
    await salesApi.estimates.create({ ...form, amount: 0 })
    showToast('Estimate created!')
    setShowModal(false)
    setForm({ subject: '', client: '', valid_until: '' })
    salesApi.estimates.list({}).then(setData)
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">Sales & Revenue</p><h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}><span className="text-gradient">Estimates</span></h1></div>
        <button onClick={() => setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15} /> New Estimate</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{ l: 'Total', v: stats.total, c: '#7C3AED' }, { l: 'Draft', v: stats.draft, c: '#94a3b8' }, { l: 'Sent', v: stats.sent, c: '#a78bfa' }, { l: 'Accepted', v: stats.accepted, c: '#10b981' }].map(k => (
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="flex gap-2">
        {['All', 'Draft', 'Sent', 'Accepted', 'Declined', 'Expired'].map(f => (
          <button key={f} onClick={() => setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: filter === f ? '#fff' : 'var(--text-muted)', border: `1px solid ${filter === f ? 'transparent' : 'var(--border)'}` }}>{f}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['Ref', 'Subject', 'Client', 'Amount', 'Valid Until', 'Status', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
              </tr></thead>
              <tbody>
                {data.map(e => {
                  const s = sc(e.status)
                  return (
                    <tr key={e.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }} onMouseEnter={ev => ev.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                      <td className="py-3 px-4 font-bold" style={{ color: '#a78bfa' }}>{e.reference}</td>
                      <td className="py-3 px-4 font-semibold max-w-[200px] truncate" style={{ color: 'var(--text-h)' }}>{e.subject}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{e.client}</td>
                      <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(e.amount)}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(e.valid_until)}</td>
                      <td className="py-3 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{e.status}</span></td>
                      <td className="py-3 px-4 relative">
                        <button onClick={() => setOpenMenu(openMenu === e.id ? null : e.id)} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{ color: 'var(--text-muted)' }} /></button>
                        {openMenu === e.id && (
                          <div className="absolute right-4 top-10 z-50 rounded-xl shadow-2xl py-1 min-w-[160px]" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                            {[{ icon: Send, label: 'Send' }, { icon: Receipt, label: 'Convert to Invoice' }, { icon: Copy, label: 'Duplicate' }, { icon: Trash2, label: 'Delete' }].map(a => (
                              <button key={a.label} onClick={() => { showToast(a.label + '!'); setOpenMenu(null) }} className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium transition-colors hover:bg-[rgba(124,58,237,0.06)]" style={{ color: a.label === 'Delete' ? '#f87171' : 'var(--text-h)' }}>
                                <a.icon size={12} />{a.label}
                              </button>
                            ))}
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {data.length === 0 && <tr><td colSpan="7" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No estimates found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {showModal && (
        <div className="modal-backdrop" onClick={() => setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>New Estimate</h2><button onClick={() => setShowModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="space-y-3">
              <div><label className="label">Subject *</label><input className="input-3d text-sm" placeholder="Estimate subject" value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} /></div>
              <div><label className="label">Client *</label><select className="input-3d text-sm" value={form.client} onChange={e => setForm({ ...form, client: e.target.value })}><option value="">Select client</option>{salesApi.clients.map(c => <option key={c} value={c}>{c}</option>)}</select></div>
              <div><label className="label">Valid Until</label><input type="date" className="input-3d text-sm" value={form.valid_until} onChange={e => setForm({ ...form, valid_until: e.target.value })} /></div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Create Estimate</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
