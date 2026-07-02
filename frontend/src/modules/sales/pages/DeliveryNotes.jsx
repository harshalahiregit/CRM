import { useState, useEffect } from 'react'
import { Plus, Send, Check, Trash2, X, MoreVertical } from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const sc = s => s === 'Delivered' ? { bg: 'rgba(16,185,129,0.1)', color: '#10b981' } : s === 'Sent' ? { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' } : s === 'Draft' ? { bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' } : { bg: 'rgba(239,68,68,0.1)', color: '#f87171' }

export default function DeliveryNotes() {
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [toast, setToast] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    setLoading(true)
    salesApi.deliveryNotes.list({ status: filter !== 'All' ? filter : undefined }).then(d => { setData(d); setLoading(false) })
  }, [filter])

  const stats = { total: data.length, draft: data.filter(d => d.status === 'Draft').length, sent: data.filter(d => d.status === 'Sent').length, delivered: data.filter(d => d.status === 'Delivered').length }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">Sales & Revenue</p><h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Delivery <span className="text-gradient">Notes</span></h1></div>
        <button onClick={() => showToast('Create delivery note modal coming soon')} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15} /> New Delivery Note</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{ l: 'Total', v: stats.total, c: '#7C3AED' }, { l: 'Draft', v: stats.draft, c: '#94a3b8' }, { l: 'Sent', v: stats.sent, c: '#a78bfa' }, { l: 'Delivered', v: stats.delivered, c: '#10b981' }].map(k => (
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="flex gap-2">
        {['All', 'Draft', 'Sent', 'Delivered', 'Cancelled'].map(f => (
          <button key={f} onClick={() => setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: filter === f ? '#fff' : 'var(--text-muted)', border: `1px solid ${filter === f ? 'transparent' : 'var(--border)'}` }}>{f}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['DN #', 'Invoice', 'Client', 'Items', 'Delivery Date', 'Status', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
              </tr></thead>
              <tbody>
                {data.map(dn => {
                  const s = sc(dn.status)
                  return (
                    <tr key={dn.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }} onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-3 px-4 font-bold" style={{ color: '#a78bfa' }}>{dn.number}</td>
                      <td className="py-3 px-4 font-semibold" style={{ color: 'var(--text-h)' }}>{dn.invoice_number}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{dn.client}</td>
                      <td className="py-3 px-4 text-center font-bold" style={{ color: 'var(--text-h)' }}>{dn.items_count}</td>
                      <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmtDate(dn.delivery_date)}</td>
                      <td className="py-3 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{dn.status}</span></td>
                      <td className="py-3 px-4 relative">
                        <button onClick={() => setOpenMenu(openMenu === dn.id ? null : dn.id)} className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{ color: 'var(--text-muted)' }} /></button>
                        {openMenu === dn.id && (
                          <div className="absolute right-4 top-10 z-50 rounded-xl shadow-2xl py-1 min-w-[160px]" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                            {[{ icon: Send, label: 'Send' }, { icon: Check, label: 'Mark Delivered' }, { icon: Trash2, label: 'Delete' }].map(a => (
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
                {data.length === 0 && <tr><td colSpan="7" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No delivery notes found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
