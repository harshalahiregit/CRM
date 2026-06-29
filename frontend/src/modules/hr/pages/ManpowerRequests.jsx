import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, X, CheckCircle, XCircle, Search, Filter } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const PRIORITY_S = p => p==='High'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:p==='Medium'?{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}:{c:'#34d399',bg:'rgba(52,211,153,0.12)'}
const STATUS_S   = s => s==='Approved'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}

const EMPTY_FORM = { department:'', position_title:'', number_of_posts:1, required_by_date:'', job_type:'Full-time', priority:'Medium', justification:'' }

export default function ManpowerRequests() {
  const { isDark } = useTheme()
  const [records, setRecords]     = useState([])
  const [loading, setLoading]     = useState(true)
  const [error, setError]         = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)
  const [statusF, setStatusF]     = useState('All')
  const [deptF, setDeptF]         = useState('All')
  const [search, setSearch]       = useState('')

  const [rejectModal, setRejectModal] = useState({ open:false, id:null, reason:'' })

  const showToast = (msg, type='success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3000)
  }

  const fetchData = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const params = {}
      if (statusF !== 'All') params.status = statusF
      if (deptF   !== 'All') params.department = deptF
      const data = await hrApi.manpower.list(params)
      setRecords(data)
    } catch (e) {
      setError('Failed to load requests. Make sure you are logged in.')
    } finally { setLoading(false) }
  }, [statusF, deptF])

  useEffect(() => { fetchData() }, [fetchData])

  const handleCreate = async () => {
    if (!form.department || !form.position_title) return showToast('Department and Position are required', 'error')
    setSaving(true)
    try {
      const rec = await hrApi.manpower.create(form)
      setRecords(prev => [rec, ...prev])
      setShowModal(false); setForm(EMPTY_FORM)
      showToast('Manpower request created!')
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed to create request', 'error')
    } finally { setSaving(false) }
  }

  const handleStatus = async (id, status, reason='') => {
    try {
      const payload = { status }
      if (status === 'Rejected') payload.rejection_reason = reason
      const updated = await hrApi.manpower.updateStatus(id, payload)
      setRecords(prev => prev.map(r => r.id === id ? updated : r))
      showToast(`Request ${status}!`)
      setRejectModal({ open:false, id:null, reason:'' })
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed to update status', 'error')
    }
  }

  const openRejectModal = (id) => setRejectModal({ open:true, id, reason:'' })


  const handleDelete = async (id) => {
    if (!window.confirm('Delete this request?')) return
    try {
      await hrApi.manpower.delete(id)
      setRecords(prev => prev.filter(r => r.id !== id))
      showToast('Deleted')
    } catch { showToast('Failed to delete', 'error') }
  }

  const filtered = records.filter(r =>
    r.position_title?.toLowerCase().includes(search.toLowerCase()) ||
    r.department?.toLowerCase().includes(search.toLowerCase())
  )

  const stats = {
    total:    records.length,
    pending:  records.filter(r=>r.status==='Pending').length,
    approved: records.filter(r=>r.status==='Approved').length,
    rejected: records.filter(r=>r.status==='Rejected').length,
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">

      {/* Toast */}
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl transition-all"
          style={{ background: toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            Manpower <span className="text-gradient">Requests</span>
          </h1>
        </div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}>
          <Plus size={15}/> New Request
        </button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Pending',v:stats.pending,c:'#fbbf24'},{l:'Approved',v:stats.approved,c:'#10b981'},{l:'Rejected',v:stats.rejected,c:'#f87171'}].map(k=>(
          <div key={k.l} className="kpi-3d">
            <p className="text-3xl font-black" style={{ color:k.c, letterSpacing:'-0.03em' }}>{k.v}</p>
            <p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d flex gap-3 items-center flex-wrap" style={{ padding:'14px 18px' }}>
        <div className="relative flex-1 min-w-[180px]">
          <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color:'var(--text-muted)' }}/>
          <input className="input-3d pl-9 text-sm py-2" placeholder="Search by position or dept..." value={search} onChange={e=>setSearch(e.target.value)}/>
        </div>
        <div className="flex gap-2 flex-wrap">
          {['All','Pending','Approved','Rejected'].map(s=>(
            <button key={s} onClick={()=>setStatusF(s)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
              style={{ background:statusF===s?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:statusF===s?'#fff':'var(--text-muted)', border:`1px solid ${statusF===s?'transparent':'var(--border)'}` }}>{s}</button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="card-3d overflow-x-auto" style={{ padding:0 }}>
        {loading ? (
          <div className="p-10 text-center" style={{ color:'var(--text-muted)' }}>Loading…</div>
        ) : error ? (
          <div className="p-10 text-center" style={{ color:'#f87171' }}>{error}</div>
        ) : filtered.length === 0 ? (
          <div className="p-10 text-center" style={{ color:'var(--text-muted)' }}>No manpower requests found.</div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr style={{ borderBottom:'1px solid var(--border)' }}>
                {['Req ID','Department','Position','Posts','Priority','Status','Date','Actions'].map(h=>(
                  <th key={h} className="label-caps px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {filtered.map(r => {
                const ps = PRIORITY_S(r.priority)
                const ss = STATUS_S(r.status)
                return(
                  <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}
                    onMouseEnter={e=>e.currentTarget.style.background='var(--bg-input)'}
                    onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                    <td className="px-4 py-3 font-mono text-xs" style={{ color:'#a78bfa' }}>MR-{String(r.id).padStart(3,'0')}</td>
                    <td className="px-4 py-3 font-semibold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                    <td className="px-4 py-3" style={{ color:'var(--text-body)' }}>{r.position_title}</td>
                    <td className="px-4 py-3 font-bold text-center" style={{ color:'var(--text-h)' }}>{r.number_of_posts}</td>
                    <td className="px-4 py-3"><span className="px-2.5 py-1 rounded-lg text-[11px] font-bold" style={{ background:ps.bg, color:ps.c }}>{r.priority}</span></td>
                    <td className="px-4 py-3"><span className="px-2.5 py-1 rounded-lg text-[11px] font-bold" style={{ background:ss.bg, color:ss.c }}>{r.status}</span></td>
                    <td className="px-4 py-3 text-xs" style={{ color:'var(--text-muted)' }}>{r.created_at ? new Date(r.created_at).toLocaleDateString('en-IN') : '—'}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-1.5">
                        {r.status==='Pending' && <>
                          <button onClick={()=>handleStatus(r.id,'Approved')} title="Approve" className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background:'rgba(16,185,129,0.1)' }}><CheckCircle size={13} style={{ color:'#10b981' }}/></button>
                          <button onClick={()=>openRejectModal(r.id)} title="Reject" className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background:'rgba(239,68,68,0.1)' }}><XCircle size={13} style={{ color:'#f87171' }}/></button>
                        </>}}
                        <button onClick={()=>handleDelete(r.id)} title="Delete" className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background:'rgba(239,68,68,0.05)' }}><X size={12} style={{ color:'#f87171' }}/></button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {/* Reject Reason Modal */}
      {rejectModal.open && (
        <div className="modal-backdrop" onClick={()=>setRejectModal({open:false,id:null,reason:''})}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Reject Request</h2>
              <button onClick={()=>setRejectModal({open:false,id:null,reason:''})} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-3">
              <div>
                <label className="label">Rejection Reason *</label>
                <textarea rows={3} className="input-3d text-sm resize-none" placeholder="Please provide a reason for rejection..." value={rejectModal.reason} onChange={e=>setRejectModal(m=>({...m,reason:e.target.value}))}/>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setRejectModal({open:false,id:null,reason:''})} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={()=>handleStatus(rejectModal.id,'Rejected',rejectModal.reason)} disabled={!rejectModal.reason} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#ef4444,#dc2626)', opacity:!rejectModal.reason?0.5:1 }}>Confirm Reject</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Create Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>New Manpower Request</h2>
              <button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Department *</label>
                  <select className="input-3d text-sm" value={form.department} onChange={e=>setForm({...form,department:e.target.value})}>
                    <option value="">Select...</option>
                    {['Engineering','Sales','HR','Operations','Finance','Product','Marketing'].map(d=><option key={d}>{d}</option>)}
                  </select>
                </div>
                <div>
                  <label className="label">Job Type</label>
                  <select className="input-3d text-sm" value={form.job_type} onChange={e=>setForm({...form,job_type:e.target.value})}>
                    {['Full-time','Part-time','Contract','Internship'].map(t=><option key={t}>{t}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <label className="label">Position Title *</label>
                <input className="input-3d text-sm" placeholder="e.g. Senior React Developer" value={form.position_title} onChange={e=>setForm({...form,position_title:e.target.value})}/>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">No. of Posts</label>
                  <input type="number" min="1" className="input-3d text-sm" value={form.number_of_posts} onChange={e=>setForm({...form,number_of_posts:parseInt(e.target.value)||1})}/>
                </div>
                <div>
                  <label className="label">Priority</label>
                  <select className="input-3d text-sm" value={form.priority} onChange={e=>setForm({...form,priority:e.target.value})}>
                    {['Low','Medium','High'].map(p=><option key={p}>{p}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <label className="label">Required By Date</label>
                <input type="date" className="input-3d text-sm" value={form.required_by_date} onChange={e=>setForm({...form,required_by_date:e.target.value})}/>
              </div>
              <div>
                <label className="label">Justification</label>
                <textarea rows={3} className="input-3d text-sm resize-none" placeholder="Why is this position needed?" value={form.justification} onChange={e=>setForm({...form,justification:e.target.value})}/>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)', opacity:saving?0.7:1 }}>
                  {saving ? 'Saving…' : 'Submit Request'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
