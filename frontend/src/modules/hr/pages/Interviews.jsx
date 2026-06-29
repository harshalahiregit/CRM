import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, Video, Mail, MessageCircle, Bell, X, Star } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const ROUND_COLOR = { 'HR Telephonic':'#7C3AED','Technical L1':'#3b82f6','Manager L2':'#f59e0b','Final HR L3':'#10b981' }
const STATUS_S = s => s==='Completed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Scheduled'?{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const fmtTime = dt => dt ? new Date(dt).toLocaleString('en-IN',{dateStyle:'medium',timeStyle:'short'}) : '—'

export default function Interviews() {
  const { isDark } = useTheme()
  const [interviews, setInterviews] = useState([])
  const [loading, setLoading]       = useState(true)
  const [tab, setTab]               = useState('All')
  const [toast, setToast]           = useState(null)
  const [showSchedule, setShowSchedule] = useState(false)
  const [showFeedback, setShowFeedback] = useState(null) // interview object
  const [feedbackForm, setFbForm]   = useState({ result:'Passed', technical_score:'', communication_score:'', problem_solving_score:'', notes:'' })
  const [form, setForm] = useState({ candidate_id:'', round_name:'HR Telephonic', interviewer_name:'', scheduled_at:'' })
  const [candidates, setCandidates] = useState([])
  const [saving, setSaving]         = useState(false)
  const [notifications, setNotifs]  = useState({ reminder:true, email:true, whatsapp:false })

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const [ivs, cands] = await Promise.all([
        hrApi.interviews.list(tab !== 'All' ? { status: tab } : {}),
        hrApi.candidates.list(),
      ])
      setInterviews(ivs); setCandidates(cands)
    } catch { showToast('Failed to load interviews','error') }
    finally { setLoading(false) }
  }

  useEffect(()=>{ fetchData() }, [tab])

  const handleSchedule = async () => {
    if (!form.candidate_id || !form.scheduled_at) return showToast('Candidate and date/time are required','error')
    setSaving(true)
    try {
      const iv = await hrApi.interviews.schedule(form)
      setInterviews(prev => [iv, ...prev])
      setShowSchedule(false)
      setForm({ candidate_id:'', round_name:'HR Telephonic', interviewer_name:'', scheduled_at:'' })
      showToast('Interview scheduled!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const handleGenerateMeet = async (id) => {
    try {
      const r = await hrApi.interviews.generateMeetLink(id)
      setInterviews(prev => prev.map(iv => iv.id===id ? {...iv, meet_link: r.meet_link} : iv))
      showToast('Google Meet link generated!')
    } catch { showToast('Failed','error') }
  }

  const handleNotify = async (id, type) => {
    try {
      await hrApi.interviews.sendNotification(id, type)
      showToast(`${type.replace('_',' ')} sent!`)
    } catch { showToast('Failed','error') }
  }

  const handleFeedback = async () => {
    if (!showFeedback) return
    setSaving(true)
    try {
      const payload = {
        result: feedbackForm.result,
        notes:  feedbackForm.notes,
        status: 'Completed',
        technical_score:       feedbackForm.technical_score       ? Number(feedbackForm.technical_score)       : undefined,
        communication_score:   feedbackForm.communication_score   ? Number(feedbackForm.communication_score)   : undefined,
        problem_solving_score: feedbackForm.problem_solving_score ? Number(feedbackForm.problem_solving_score) : undefined,
      }
      const updated = await hrApi.interviews.recordFeedback(showFeedback.id, payload)
      setInterviews(prev => prev.map(iv => iv.id === showFeedback.id ? updated : iv))
      setShowFeedback(null)
      setFbForm({ result:'Passed', technical_score:'', communication_score:'', problem_solving_score:'', notes:'' })
      showToast('Feedback submitted!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const stats = {
    today:   interviews.filter(iv => iv.scheduled_at && new Date(iv.scheduled_at).toDateString()===new Date().toDateString()).length,
    week:    interviews.filter(iv => { const d=new Date(iv.scheduled_at); const now=new Date(); return d>=now && d<=new Date(now.getTime()+7*24*3600000) }).length,
    pending: interviews.filter(iv => iv.result==='Pending' && iv.status==='Scheduled').length,
    done:    interviews.filter(iv => iv.status==='Completed').length,
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            Interview <span className="text-gradient">Schedule</span>
          </h1>
        </div>
        <button onClick={()=>setShowSchedule(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}>
          <Plus size={15}/> Schedule Interview
        </button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{l:"Today's",v:stats.today,c:'#f59e0b'},{l:'This Week',v:stats.week,c:'#7C3AED'},{l:'Pending Feedback',v:stats.pending,c:'#f87171'},{l:'Completed',v:stats.done,c:'#10b981'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Interview list */}
        <div className="lg:col-span-2 space-y-4">
          <div className="flex gap-2">
            {['All','Scheduled','Completed','Cancelled'].map(t=>(
              <button key={t} onClick={()=>setTab(t)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all" style={{ background:tab===t?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:tab===t?'#fff':'var(--text-muted)', border:`1px solid ${tab===t?'transparent':'var(--border)'}` }}>{t}</button>
            ))}
          </div>

          {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div> : (
            <div className="space-y-3">
              {interviews.map(iv=>{
                const rc = ROUND_COLOR[iv.round_name]||'#7C3AED'
                const ss = STATUS_S(iv.status)
                const cand = iv.candidate || {}
                return(
                  <div key={iv.id} className="card-3d" style={{ padding:'18px' }}>
                    <div className="flex items-start gap-3 flex-wrap">
                      <div className="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-black text-white flex-shrink-0" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                        {(cand.name||'?').split(' ').map(n=>n[0]).join('').toUpperCase()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{cand.name||'—'}</p>
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${rc}20`, color:rc }}>{iv.round_name}</span>
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{iv.status}</span>
                        </div>
                        <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>{iv.interviewer_name||'No interviewer assigned'}</p>
                        <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>{fmtTime(iv.scheduled_at)}</p>
                      </div>
                      <div className="flex gap-1.5 flex-shrink-0">
                        {iv.meet_link ? (
                          <a href={iv.meet_link} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[10px] font-bold text-white" style={{ background:'linear-gradient(135deg,#3b82f6,#1d4ed8)' }}>
                            <Video size={10}/> Join
                          </a>
                        ) : (
                          <button onClick={()=>handleGenerateMeet(iv.id)} className="flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-[10px] font-bold" style={{ background:'rgba(59,130,246,0.1)', color:'#3b82f6', border:'1px solid rgba(59,130,246,0.2)' }}>
                            <Video size={10}/> Gen Link
                          </button>
                        )}
                        <button onClick={()=>handleNotify(iv.id,'email_candidate')} className="p-1.5 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Mail size={12}/></button>
                        <button onClick={()=>handleNotify(iv.id,'whatsapp')} className="p-1.5 rounded-xl" style={{ background:'rgba(37,211,102,0.1)', color:'#25D366' }}><MessageCircle size={12}/></button>
                        {iv.status==='Scheduled' && <button onClick={()=>{setShowFeedback(iv);setFbForm({result:'Passed',technical_score:'',communication_score:'',problem_solving_score:'',notes:''})}} className="flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-[10px] font-bold" style={{ background:'rgba(245,158,11,0.1)', color:'#fbbf24', border:'1px solid rgba(245,158,11,0.2)' }}><Star size={10}/> Feedback</button>}
                      </div>
                    </div>
                  </div>
                )
              })}
              {interviews.length===0 && <p className="text-center py-8" style={{ color:'var(--text-muted)' }}>No interviews found.</p>}
            </div>
          )}
        </div>

        {/* Quick Actions + Toggles */}
        <div className="space-y-4">
          <div className="card-3d" style={{ padding:'22px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>Auto-Reminders</h3>
            <div className="space-y-3">
              {[{k:'reminder',l:'Reminder Before Interview',icon:<Bell size={12}/>},{k:'email',l:'Email Notifications',icon:<Mail size={12}/>},{k:'whatsapp',l:'WhatsApp Alerts',icon:<MessageCircle size={12}/>}].map(r=>(
                <div key={r.k} className="flex items-center justify-between">
                  <div className="flex items-center gap-2" style={{ color:'var(--text-muted)' }}>{r.icon}<span className="text-xs">{r.l}</span></div>
                  <button onClick={()=>setNotifs(n=>({...n,[r.k]:!n[r.k]}))} className="w-10 h-5 rounded-full transition-all relative" style={{ background:notifications[r.k]?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--border)' }}>
                    <div className="w-3.5 h-3.5 bg-white rounded-full absolute top-0.5 transition-all" style={{ left:notifications[r.k]?'calc(100% - 18px)':'2px' }}/>
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Schedule Modal */}
      {showSchedule && (
        <div className="modal-backdrop" onClick={()=>setShowSchedule(false)}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Schedule Interview</h2>
              <button onClick={()=>setShowSchedule(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-3">
              <div>
                <label className="label">Candidate *</label>
                <select className="input-3d text-sm" value={form.candidate_id} onChange={e=>setForm({...form,candidate_id:e.target.value})}>
                  <option value="">Select candidate...</option>
                  {candidates.filter(c=>!['Hired','Rejected'].includes(c.stage)).map(c=><option key={c.id} value={c.id}>{c.name} — {c.stage}</option>)}
                </select>
              </div>
              <div>
                <label className="label">Round</label>
                <select className="input-3d text-sm" value={form.round_name} onChange={e=>setForm({...form,round_name:e.target.value})}>
                  {['HR Telephonic','Technical L1','Manager L2','Final HR L3','Other'].map(r=><option key={r}>{r}</option>)}
                </select>
              </div>
              <div><label className="label">Interviewer</label><input className="input-3d text-sm" placeholder="Interviewer name" value={form.interviewer_name} onChange={e=>setForm({...form,interviewer_name:e.target.value})}/></div>
              <div><label className="label">Date & Time *</label><input type="datetime-local" className="input-3d text-sm" value={form.scheduled_at} onChange={e=>setForm({...form,scheduled_at:e.target.value})}/></div>
              <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>💡 Google Meet link will be auto-generated</p>
              <div className="flex gap-3">
                <button onClick={()=>setShowSchedule(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleSchedule} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>
                  {saving?'Scheduling…':'Schedule'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
      {/* Feedback Modal */}
      {showFeedback && (
        <div className="modal-backdrop" onClick={()=>setShowFeedback(null)}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Interview Feedback</h2>
              <button onClick={()=>setShowFeedback(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-3">
              <div><label className="label">Result *</label>
                <select className="input-3d text-sm" value={feedbackForm.result} onChange={e=>setFbForm(f=>({...f,result:e.target.value}))}>
                  {['Passed','Failed','On Hold'].map(r=><option key={r}>{r}</option>)}
                </select>
              </div>
              <p className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>Scores (out of 10)</p>
              <div className="grid grid-cols-3 gap-2">
                {[{k:'technical_score',l:'Technical'},{k:'communication_score',l:'Communication'},{k:'problem_solving_score',l:'Problem Solving'}].map(s=>(
                  <div key={s.k}>
                    <label className="label">{s.l}</label>
                    <input type="number" min="0" max="10" className="input-3d text-sm" placeholder="0-10" value={feedbackForm[s.k]} onChange={e=>setFbForm(f=>({...f,[s.k]:e.target.value}))}/>
                  </div>
                ))}
              </div>
              {(feedbackForm.technical_score || feedbackForm.communication_score || feedbackForm.problem_solving_score) && (
                <div className="px-3 py-2 rounded-xl text-center" style={{ background:'rgba(124,58,237,0.1)' }}>
                  <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>Overall Score: </span>
                  <span className="font-black text-sm" style={{ color:'#a78bfa' }}>
                    {Math.round(([feedbackForm.technical_score,feedbackForm.communication_score,feedbackForm.problem_solving_score].filter(Boolean).reduce((a,b)=>a+Number(b),0) / Math.max([feedbackForm.technical_score,feedbackForm.communication_score,feedbackForm.problem_solving_score].filter(Boolean).length,1)) * 10)}%
                  </span>
                </div>
              )}
              <div><label className="label">Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={feedbackForm.notes} onChange={e=>setFbForm(f=>({...f,notes:e.target.value}))}/></div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowFeedback(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleFeedback} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':'Submit Feedback'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
