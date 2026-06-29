import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, Send, Eye, X, Mail } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const STATUS_S = s => s==='Accepted'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Sent'?{c:'#a78bfa',bg:'rgba(124,58,237,0.12)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}
const fmtCTC = v => v ? '₹'+Number(v).toLocaleString('en-IN') : '—'
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()

const EMPTY_FORM = { candidate_id:'', position:'', department:'', offered_ctc:'', joining_date:'', probation_period:'3 months', notice_period:'1 month', validity_date:'' }

export default function OfferLetters() {
  const { isDark } = useTheme()
  const [offers, setOffers]       = useState([])
  const [candidates, setCands]    = useState([])
  const [loading, setLoading]     = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const [offs, cands] = await Promise.all([hrApi.offers.list(), hrApi.candidates.list({stage:'Interview'})])
      setOffers(offs); setCands(cands)
    } catch { showToast('Failed to load offers','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[])

  const handleCreate = async () => {
    if (!form.candidate_id||!form.offered_ctc||!form.joining_date) return showToast('Candidate, CTC and joining date required','error')
    setSaving(true)
    try {
      const offer = await hrApi.offers.create(form)
      setOffers(prev=>[offer,...prev])
      setShowModal(false); setForm(EMPTY_FORM)
      showToast('Offer letter generated!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const handleSend = async (id) => {
    try {
      const updated = await hrApi.offers.send(id)
      setOffers(prev=>prev.map(o=>o.id===id?updated:o))
      showToast('Offer sent to candidate!')
    } catch { showToast('Failed','error') }
  }

  const handleStatus = async (id, status) => {
    try {
      const updated = await hrApi.offers.updateStatus(id, status)
      setOffers(prev=>prev.map(o=>o.id===id?updated:o))
      showToast(`Offer ${status}!`)
    } catch { showToast('Failed','error') }
  }

  const stats = { generated:offers.length, sent:offers.filter(o=>['Sent','Accepted'].includes(o.status)).length, accepted:offers.filter(o=>o.status==='Accepted').length, pending:offers.filter(o=>o.status==='Generated').length }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Offer <span className="text-gradient">Letters</span></h1></div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Generate Offer</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{l:'Generated',v:stats.generated,c:'#7C3AED'},{l:'Sent',v:stats.sent,c:'#a78bfa'},{l:'Accepted',v:stats.accepted,c:'#10b981'},{l:'Pending',v:stats.pending,c:'#fbbf24'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Acceptance progress */}
      {stats.generated > 0 && (
        <div className="card-3d" style={{ padding:'20px' }}>
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>Offer Acceptance Rate</span>
            <span className="text-sm font-black" style={{ color:'#10b981' }}>{Math.round((stats.accepted/stats.generated)*100)}%</span>
          </div>
          <div className="h-3 rounded-full" style={{ background:'var(--bg-input)' }}>
            <div className="h-full rounded-full" style={{ width:`${(stats.accepted/stats.generated)*100}%`, background:'linear-gradient(90deg,#34d399,#10b981)' }}/>
          </div>
        </div>
      )}

      {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div> : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          {offers.map(offer=>{
            const ss = STATUS_S(offer.status)
            const cname = offer.candidate?.name || '—'
            return(
              <div key={offer.id} className="card-3d flex flex-col" style={{ padding:'20px' }}>
                <div className="flex items-start justify-between mb-3">
                  <div className="flex items-center gap-2 mb-0.5">
                    <div className="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-black text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(cname)}</div>
                    <div>
                      <p className="font-bold text-sm" style={{ color:'var(--text-h)' }}>{cname}</p>
                      <p className="text-xs" style={{ color:'var(--text-muted)' }}>{offer.position} · {offer.department}</p>
                    </div>
                  </div>
                  <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background:ss.bg, color:ss.c }}>{offer.status}</span>
                </div>
                <div className="grid grid-cols-2 gap-2 mb-4">
                  <div className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Offered CTC</p><p className="text-sm font-black mt-0.5" style={{ color:'var(--text-h)' }}>{fmtCTC(offer.offered_ctc)}</p></div>
                  <div className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Joining Date</p><p className="text-xs font-bold mt-0.5" style={{ color:'var(--text-h)' }}>{fmtDate(offer.joining_date)}</p></div>
                </div>
                <div className="flex gap-2 mt-auto">
                  {offer.status==='Generated' && <button onClick={()=>handleSend(offer.id)} className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Send size={11}/> Send</button>}
                  {offer.status==='Sent' && <>
                    <button onClick={()=>handleStatus(offer.id,'Accepted')} className="flex-1 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}>✓ Accept</button>
                    <button onClick={()=>handleStatus(offer.id,'Rejected')} className="flex-1 py-2 rounded-xl text-xs font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>Reject</button>
                  </>}
                  {offer.status==='Accepted' && <span className="flex-1 text-center text-xs py-2 font-semibold" style={{ color:'#10b981' }}>✓ Accepted</span>}
                  {offer.status==='Rejected' && <span className="flex-1 text-center text-xs py-2 font-semibold" style={{ color:'#f87171' }}>Rejected</span>}
                </div>
              </div>
            )
          })}
          {offers.length===0 && <div className="col-span-3 text-center py-12" style={{ color:'var(--text-muted)' }}>No offers generated yet.</div>}
        </div>
      )}

      {/* Generate Offer Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Generate Offer Letter</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Candidate *</label>
                <select className="input-3d text-sm" value={form.candidate_id} onChange={e=>{ const c=candidates.find(x=>x.id==e.target.value); setForm({...form,candidate_id:e.target.value,position:c?.job_posting?.title||'',department:c?.job_posting?.department||''})}}>
                  <option value="">Select candidate...</option>
                  {candidates.map(c=><option key={c.id} value={c.id}>{c.name} — {c.stage}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Position</label><input className="input-3d text-sm" value={form.position} onChange={e=>setForm({...form,position:e.target.value})}/></div>
                <div><label className="label">Department</label><input className="input-3d text-sm" value={form.department} onChange={e=>setForm({...form,department:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Offered CTC (₹) *</label><input type="number" className="input-3d text-sm" placeholder="800000" value={form.offered_ctc} onChange={e=>setForm({...form,offered_ctc:e.target.value})}/></div>
                <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date} onChange={e=>setForm({...form,joining_date:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Probation Period</label><select className="input-3d text-sm" value={form.probation_period} onChange={e=>setForm({...form,probation_period:e.target.value})}><option>3 months</option><option>6 months</option><option>None</option></select></div>
                <div><label className="label">Notice Period</label><select className="input-3d text-sm" value={form.notice_period} onChange={e=>setForm({...form,notice_period:e.target.value})}><option>1 month</option><option>2 months</option><option>3 months</option></select></div>
              </div>
              <div><label className="label">Offer Validity Date</label><input type="date" className="input-3d text-sm" value={form.validity_date} onChange={e=>setForm({...form,validity_date:e.target.value})}/></div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Generating…':'Generate'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
