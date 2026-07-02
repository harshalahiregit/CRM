import { useState, useEffect } from 'react'
import { Plus, Search, Send, Copy, FileText, Trash2, X, MoreVertical, ArrowRightLeft, Receipt } from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmt = (v) => '₹' + Number(v).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const sc = s => s === 'Accepted' ? { bg: 'rgba(16,185,129,0.1)', color: '#10b981' } : s === 'Sent' ? { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' } : s === 'Open' ? { bg: 'rgba(59,130,246,0.1)', color: '#3b82f6' } : s === 'Declined' ? { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } : { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' }

export default function Proposals() {
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [search, setSearch] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [toast, setToast] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm] = useState({ subject: '', client: '', expiry_date: '', notes: '' })

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    setLoading(true)
    salesApi.proposals.list({ status: filter !== 'All' ? filter : undefined, search: search || undefined })
      .then(d => { setData(d); setLoading(false) })
  }, [filter, search])

  const stats = { total: data.length, open: data.filter(p => p.status === 'Open').length, sent: data.filter(p => p.status === 'Sent').length, accepted: data.filter(p => p.status === 'Accepted').length }

  const handleCreate = async () => {
    if (!form.subject || !form.client) return showToast('Subject & client required', 'error')
    await salesApi.proposals.create({ ...form, amount: 0 })
    showToast('Proposal created!')
    setShowModal(false)
    setForm({ subject: '', client: '', expiry_date: '', notes: '' })
    salesApi.proposals.list({}).then(setData)
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            <span className="text-gradient">Proposals</span>
          </h1>
        </div>
        <button onClick={() => setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Proposal
        </button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{ l: 'Total', v: stats.total, c: '#7C3AED' }, { l: 'Open', v: stats.open, c: '#3b82f6' }, { l: 'Sent', v: stats.sent, c: '#a78bfa' }, { l: 'Accepted', v: stats.accepted, c: '#10b981' }].map(k => (
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="flex items-center gap-3 flex-wrap">
        <div className="flex gap-2">
          {['All', 'Open', 'Sent', 'Accepted', 'Declined', 'Expired'].map(f => (
            <button key={f} onClick={() => setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
              style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: filter === f ? '#fff' : 'var(--text-muted)', border: `1px solid ${filter === f ? 'transparent' : 'var(--border)'}` }}>{f}</button>
          ))}
        </div>
        <div className="flex-1 min-w-[200px] relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search proposals..." className="input-3d text-sm pl-9 w-full" />
        </div>
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['ID', 'Subject', 'Client', 'Amount', 'Created', 'Expiry', 'Status', ''].map(h => (
                  <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>
                ))}
              </tr></thead>
              <tbody>
                {data.map(p => {
                  const s = sc(p.status)
                  return (
                    <tr key={p.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }}
                      onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-3 px-4 font-bold" style={{ color: '#a78bfa' }}>PROP-{String(p.id).padStart(3, '0')}</td>
                      <td className="py-3 px-4 font-semibold max-w-[200px] truncate" style={{ color: 'var(--text-h)' }}>{p.subject}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{p.client}</td>
                      <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(p.amount)}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.created_at)}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.expiry_date)}</td>
                      <td className="py-3 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{p.status}</span></td>
                      <td className="py-3 px-4 relative">
                        <button onClick={() => setOpenMenu(openMenu === p.id ? null : p.id)} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{ color: 'var(--text-muted)' }} /></button>
                        {openMenu === p.id && (
                          <div className="absolute right-4 top-10 z-50 rounded-xl shadow-2xl py-1 min-w-[160px]" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                            {[
                              { icon: Send, label: 'Send', action: () => { salesApi.proposals.send(p.id); showToast('Proposal sent!') } },
                              { icon: Copy, label: 'Duplicate', action: () => showToast('Duplicated!') },
                              { icon: FileText, label: 'Convert to Estimate', action: () => showToast('Converted to estimate!') },
                              { icon: Receipt, label: 'Convert to Invoice', action: () => showToast('Converted to invoice!') },
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
                {data.length === 0 && <tr><td colSpan="8" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No proposals found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {showModal && (
        <div className="modal-backdrop" onClick={() => setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>New Proposal</h2><button onClick={() => setShowModal(false)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button></div>
            <div className="space-y-3">
              <div><label className="label">Subject *</label><input className="input-3d text-sm" placeholder="Proposal subject" value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} /></div>
              <div><label className="label">Client *</label>
                <select className="input-3d text-sm" value={form.client} onChange={e => setForm({ ...form, client: e.target.value })}>
                  <option value="">Select client</option>
                  {salesApi.clients.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div><label className="label">Expiry Date</label><input type="date" className="input-3d text-sm" value={form.expiry_date} onChange={e => setForm({ ...form, expiry_date: e.target.value })} /></div>
              <div><label className="label">Notes</label><textarea className="input-3d text-sm" rows={3} placeholder="Additional notes..." value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} /></div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Create Proposal</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
