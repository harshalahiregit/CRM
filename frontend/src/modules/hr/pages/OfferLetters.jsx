import { useState, useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { Plus, Send, X, Mail, AlertCircle, ExternalLink, Copy, RefreshCw, UserCheck } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData } from '@/modules/hr/useMasterData'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import GenerateOfferDrawer from '@/modules/hr/components/GenerateOfferDrawer'
import WorkflowProgress from '@/components/ui/WorkflowProgress'

const STATUS_COLORS = {
  Draft: '#94a3b8', 'Pending Approval': '#f59e0b', Approved: '#06b6d4',
  Generated: '#94a3b8', Sent: '#a78bfa', Viewed: '#8b5cf6', Accepted: '#10b981',
  Declined: '#f87171', Rejected: '#f87171', Expired: '#f59e0b', Withdrawn: '#6b7280', Completed: '#0d9488',
}
// Statuses at which an offer may still be withdrawn/revised (mirrors HrOffer::PRE_ACCEPTANCE).
const PRE_ACCEPTANCE = ['Draft', 'Pending Approval', 'Approved', 'Generated', 'Sent', 'Viewed', 'Expired']
const STATUS_S = s => { const c = STATUS_COLORS[s] || '#fbbf24'; return { c, bg: `${c}1f` } }
const fmtCTC = v => v ? '₹'+Number(v).toLocaleString('en-IN') : '—'
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()

export default function OfferLetters() {
  const { isDark } = useTheme()
  const location = useLocation()
  const navigate = useNavigate()
  const [offers, setOffers]       = useState([])
  const [completed, setCompleted] = useState([])
  const { masters } = useMasterData()
  const [view, setView]           = useState('active')   // 'active' | 'completed'
  // #3 — hiring manager, resolved through candidate → job posting → requisition.
  const [mgrF, setMgrF]           = useState('All')
  const [candidates, setCands]    = useState([])
  const [loading, setLoading]     = useState(true)
  const [genFor, setGenFor]       = useState(null)  // { candidateId, candidateName } when fixed
  const [showModal, setShowModal] = useState(false)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)
  const [rejectModal, setRejectModal] = useState({ open:false, id:null, reason:'' })
  const [buckets, setBuckets]     = useState(null)
  const [history, setHistory]     = useState({ open:false, offer:null, revisions:[] })
  const [reviseOffer, setReviseOffer] = useState(null)  // offer being revised (reuses the offer drawer)
  const openRevise = (offer) => { setReviseOffer(offer); setGenFor(null); setShowModal(true) }

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    const mgr = mgrF !== 'All' ? { hiring_manager_id: mgrF } : {}
    try {
      // Offer-ready = candidates whose onboarding has been verified and APPROVED
      // (Sprint 2 gate). Offers can only be generated after onboarding approval.
      const [offs, done, approvedOnb, jb] = await Promise.all([
        hrApi.offers.list(mgr),                            // active offers (exclude Completed)
        hrApi.offers.list({ ...mgr, view: 'history' }),    // Completed / joined offers
        hrApi.onboarding.list({ verification_status: 'Approved' }),
        hrApi.offers.joiningBuckets().catch(() => null),
      ])
      setOffers(offs); setCompleted(done); setCands(approvedOnb); setBuckets(jb)
    } catch { showToast('Failed to load offers','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[mgrF])  // eslint-disable-line react-hooks/exhaustive-deps

  const patchOffer = (id, updated) => setOffers(prev => prev.map(o => o.id === id ? updated : o))
  const openPortal = (token) => window.open(hrApi.offers.portalUrl(token), '_blank', 'noopener,noreferrer')
  const copyLink = async (token) => { try { await navigator.clipboard.writeText(hrApi.offers.portalUrl(token)) } catch { /* */ } showToast('Offer portal link copied!') }
  const handleConfirmJoining = async (id) => {
    // Offer becomes Completed → it leaves the active list, so refetch both lists.
    try { await hrApi.offers.confirmJoining(id); showToast('Joining confirmed — employee created!'); fetchData() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const handleRegenerate = async (id) => {
    const v = window.prompt('New validity date (YYYY-MM-DD), or leave blank for +7 days:')
    if (v === null) return
    try { patchOffer(id, await hrApi.offers.regenerate(id, v || undefined)); showToast('Offer regenerated — send the new link') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  // ── Lifecycle actions (Phase 3) ──────────────────────────────────────────
  const handleSubmitApproval = async (id) => {
    try { patchOffer(id, await hrApi.offers.submitForApproval(id)); showToast('Submitted for approval') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const handleApprove = async (id) => {
    try { patchOffer(id, await hrApi.offers.approve(id)); showToast('Offer approved — ready to send') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const handleWithdraw = async (id) => {
    const reason = window.prompt('Reason for withdrawing this offer? (required)')
    if (reason === null) return
    if (!reason.trim()) return showToast('A reason is required to withdraw.', 'error')
    try { patchOffer(id, await hrApi.offers.withdraw(id, reason.trim())); showToast('Offer withdrawn') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const handleExtend = async (id) => {
    const v = window.prompt('New validity date (YYYY-MM-DD), or leave blank for +7 days:')
    if (v === null) return
    try { patchOffer(id, await hrApi.offers.extend(id, v || undefined)); showToast('Offer validity extended') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const openHistory = async (offer) => {
    try { const revs = await hrApi.offers.revisions(offer.id); setHistory({ open: true, offer, revisions: revs }) }
    catch { showToast('Could not load history', 'error') }
  }

  // Approved-onboarding candidates without an offer letter yet — one click generates it.
  // Count offers from BOTH lists (incl. Completed) so a joined candidate never reappears.
  const offeredCandidateIds = new Set([...offers, ...completed].map(o => Number(o.candidate_id)))
  const readyForOffer = candidates.filter(o => o.candidate_id && o.status !== 'Completed' && !offeredCandidateIds.has(Number(o.candidate_id)))
  // Arriving from the candidate workspace's "Generate Offer" CTA: open the existing
  // form prefilled for that candidate. If the candidate is not offer-eligible yet the
  // recruiter is told why instead of being dropped on an empty screen.
  useEffect(() => {
    const st = location.state
    if (!st || loading) return
    if (st.generateForCandidate) {
      const onb = candidates.find(o => Number(o.candidate_id) === Number(st.generateForCandidate))
      const existing = [...offers, ...completed].find(o => Number(o.candidate_id) === Number(st.generateForCandidate))
      if (existing) showToast('An offer already exists for this candidate.')
      else { setGenFor({ candidateId: Number(st.generateForCandidate), candidateName: st.candidateName || onb?.candidate_name || '' }); setShowModal(true) }
    }
    navigate(location.pathname, { replace: true, state: null })   // consume the intent
  }, [location.state, loading]) // eslint-disable-line react-hooks/exhaustive-deps

  const openGenerateFor = (onb) => {
    setGenFor({ candidateId: Number(onb.candidate_id), candidateName: onb.candidate_name || '' })
    setShowModal(true)
  }

  const handleSend = async (id) => {
    try {
      const updated = await hrApi.offers.send(id)
      setOffers(prev=>prev.map(o=>o.id===id?updated:o))
      showToast('Offer sent to candidate!')
    } catch { showToast('Failed','error') }
  }

  const handleStatus = async (id, status, rejection_reason = '') => {
    try {
      const updated = await hrApi.offers.updateStatus(id, { status, rejection_reason })
      setOffers(prev=>prev.map(o=>o.id===id?updated:o))
      showToast(`Offer ${status}!`)
      setRejectModal({ open:false, id:null, reason:'' })
    } catch { showToast('Failed','error') }
  }

  const openOfferReject = (id) => setRejectModal({ open:true, id, reason:'' })

  const handleStartOnboarding = async (offer) => {
    try {
      const onboardingData = {
        candidate_id: offer.candidate_id,
        candidate_name: offer.candidate?.name || '',
        position: offer.position,
        department: offer.department,
        joining_date: offer.joining_date
      }
      
      await hrApi.onboarding.start(onboardingData)
      showToast('Onboarding started successfully! Check Onboarding page.')
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed to start onboarding', 'error')
    }
  }

  // Counters reflect ACTIVE offers only; Completed (joined) are counted separately.
  const stats = { active:offers.length, sent:offers.filter(o=>['Sent','Viewed','Accepted'].includes(o.status)).length, accepted:offers.filter(o=>o.status==='Accepted').length, completed:completed.length }
  const shown = view === 'completed' ? completed : offers

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Offer <span className="text-gradient">Letters</span></h1></div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Generate Offer</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{l:'Active Offers',v:stats.active,c:'#7C3AED'},{l:'Sent / Awaiting',v:stats.sent,c:'#a78bfa'},{l:'Accepted · to join',v:stats.accepted,c:'#10b981'},{l:'Completed (Joined)',v:stats.completed,c:'#0d9488'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Active / Completed view toggle */}
      <div className="flex gap-2 items-center flex-wrap">
        {[{k:'active',l:`Active · ${offers.length}`},{k:'completed',l:`Completed Offers · ${completed.length}`}].map(t=>(
          <button key={t.k} onClick={()=>setView(t.k)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background: view===t.k ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: view===t.k ? '#fff' : 'var(--text-muted)', border:`1px solid ${view===t.k ? 'transparent' : 'var(--border)'}` }}>
            {t.l}
          </button>
        ))}
        {/* #3 — hiring manager, applied server-side. */}
        <select className="input-3d text-xs ml-auto" style={{ maxWidth: 210 }} value={mgrF} onChange={e => setMgrF(e.target.value)}>
          <option value="All">All Hiring Managers</option>
          {(masters.managers || []).map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      </div>

      {/* HR Pre-Joining — accepted offers bucketed by days-to-joining */}
      {view === 'active' && buckets && (buckets.in_15_days + buckets.in_7_days + buckets.tomorrow + buckets.today + buckets.late) > 0 && (
        <div className="card-3d" style={{ padding:'16px' }}>
          <p className="text-xs font-bold mb-3 flex items-center gap-2" style={{ color:'var(--text-h)' }}>🗓️ Pre-Joining — awaiting Confirm Joining</p>
          <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
            {[
              {l:'Within 15 days',v:buckets.in_15_days,c:'#7C3AED'},
              {l:'Within 7 days',v:buckets.in_7_days,c:'#a78bfa'},
              {l:'Tomorrow',v:buckets.tomorrow,c:'#f59e0b'},
              {l:'Today',v:buckets.today,c:'#10b981'},
              {l:'Late joining',v:buckets.late,c:'#f87171'},
            ].map(b=>(
              <div key={b.l} className="px-3 py-2.5 rounded-xl text-center" style={{ background:`${b.c}12`, border:`1px solid ${b.c}30` }}>
                <p className="text-2xl font-black" style={{ color:b.c }}>{b.v}</p>
                <p className="text-[10px] font-semibold mt-0.5" style={{ color:'var(--text-muted)' }}>{b.l}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Ready for Offer — candidates the pipeline auto-moved to the Offer stage */}
      {view === 'active' && !loading && readyForOffer.length > 0 && (
        <div className="rounded-2xl p-4" style={{ background:'rgba(16,185,129,0.08)', border:'1px solid rgba(16,185,129,0.25)' }}>
          <p className="text-xs font-bold mb-3 flex items-center gap-2" style={{ color:'#10b981' }}>
            <Mail size={13}/> Ready for Offer · {readyForOffer.length}
            <span className="font-medium" style={{ color:'var(--text-muted)' }}>— cleared interviews, awaiting an offer letter</span>
          </p>
          <div className="flex flex-wrap gap-2">
            {readyForOffer.map(o => (
              <div key={o.id} className="flex items-center gap-2 px-3 py-1.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{o.candidate_name}</span>
                {o.position && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>· {o.position}</span>}
                <button onClick={()=>openGenerateFor(o)} className="text-[10px] font-bold px-2 py-1 rounded-lg text-white flex items-center gap-1" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                  <Plus size={9}/> Generate Offer
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Acceptance progress — accepted-or-joined across all offers */}
      {view === 'active' && (offers.length + completed.length) > 0 && (() => {
        const totalAll = offers.length + completed.length
        const acceptedOrJoined = offers.filter(o=>o.status==='Accepted').length + completed.length
        const rate = Math.round((acceptedOrJoined/totalAll)*100)
        return (
          <div className="card-3d" style={{ padding:'20px' }}>
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>Offer Acceptance Rate</span>
              <span className="text-sm font-black" style={{ color:'#10b981' }}>{rate}%</span>
            </div>
            <div className="h-3 rounded-full" style={{ background:'var(--bg-input)' }}>
              <div className="h-full rounded-full" style={{ width:`${rate}%`, background:'linear-gradient(90deg,#34d399,#10b981)' }}/>
            </div>
          </div>
        )
      })()}

      {loading ? <HrLoading label="Loading offers…" /> : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          {shown.map(offer=>{
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

                {/* #14 — the same nine-phase pipeline every other recruitment
                    screen shows. Compact on a card: the next action line would
                    repeat once per offer in a grid. */}
                <div className="mb-3">
                  <WorkflowProgress kind="offer" record={offer} compact />
                </div>
                <div className="grid grid-cols-2 gap-2 mb-4">
                  <div className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Offered CTC</p><p className="text-sm font-black mt-0.5" style={{ color:'var(--text-h)' }}>{fmtCTC(offer.offered_ctc)}</p></div>
                  <div className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Joining Date</p><p className="text-xs font-bold mt-0.5" style={{ color:'var(--text-h)' }}>{fmtDate(offer.joining_date)}</p></div>
                </div>
                <div className="flex flex-wrap gap-2 mt-auto">
                  {(offer.status==='Draft' || offer.status==='Generated') && (
                    <button onClick={()=>handleSubmitApproval(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold" style={{ background:'rgba(245,158,11,0.12)', color:'#f59e0b', border:'1px solid rgba(245,158,11,0.3)' }}>Submit for Approval</button>
                  )}
                  {offer.status==='Pending Approval' && (
                    <button onClick={()=>handleApprove(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#06b6d4,#0891b2)' }}>Approve</button>
                  )}
                  {['Generated','Approved'].includes(offer.status) && <button onClick={()=>handleSend(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Send size={11}/> Send Offer</button>}

                  {/* Sent / Viewed / Accepted → the candidate acts on the secure portal */}
                  {offer.access_token && ['Sent','Viewed','Accepted'].includes(offer.status) && (
                    <div className="flex gap-1.5">
                      <button onClick={()=>openPortal(offer.access_token)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><ExternalLink size={11}/> Offer Portal</button>
                      <button onClick={()=>copyLink(offer.access_token)} title="Copy link" className="px-2.5 py-2 rounded-xl text-[11px] font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Copy size={12}/></button>
                    </div>
                  )}

                  {offer.status==='Accepted' && (
                    <button onClick={()=>handleConfirmJoining(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><UserCheck size={12}/> Confirm Joining</button>
                  )}

                  {offer.status==='Expired' && (
                    <button onClick={()=>handleExtend(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold" style={{ background:'rgba(245,158,11,0.12)', color:'#f59e0b', border:'1px solid rgba(245,158,11,0.3)' }}>Extend</button>
                  )}
                  {['Declined','Rejected','Expired'].includes(offer.status) && (
                    <button onClick={()=>handleRegenerate(offer.id)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-[11px] font-bold" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}><RefreshCw size={11}/> Regenerate</button>
                  )}

                  {/* Revise + Withdraw — available before the candidate accepts */}
                  {PRE_ACCEPTANCE.includes(offer.status) && (
                    <>
                      <button onClick={()=>openRevise(offer)} title="Revise offer" className="py-2 px-2.5 rounded-xl text-[11px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Revise</button>
                      <button onClick={()=>handleWithdraw(offer.id)} title="Withdraw offer" className="py-2 px-2.5 rounded-xl text-[11px] font-bold" style={{ background:'rgba(239,68,68,0.08)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}>Withdraw</button>
                    </>
                  )}

                  {offer.status==='Withdrawn' && (
                    <div className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold" style={{ background:'rgba(107,114,128,0.12)', color:'#6b7280', border:'1px solid rgba(107,114,128,0.25)' }}>Withdrawn{offer.withdraw_reason ? ` · ${offer.withdraw_reason.slice(0,32)}` : ''}</div>
                  )}
                  {offer.status==='Completed' && (
                    <div className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold" style={{ background:'rgba(13,148,136,0.1)', color:'#0d9488', border:'1px solid rgba(13,148,136,0.2)' }}>
                      <UserCheck size={12}/> Joined{offer.joining_confirmed_at ? ` · ${fmtDate(offer.joining_confirmed_at)}` : ''}
                    </div>
                  )}

                  {offer.version > 1 && (
                    <button onClick={()=>openHistory(offer)} title="Revision history" className="py-2 px-2.5 rounded-xl text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'#a78bfa', border:'1px solid var(--border)' }}>v{offer.version} · History</button>
                  )}
                </div>
              </div>
            )
          })}
          {shown.length===0 && <div className="col-span-1 md:col-span-2 xl:col-span-3"><HrEmpty icon={Send} title={view==='completed' ? 'No completed offers yet' : 'No active offers'} hint={view==='completed' ? 'Offers move here once the candidate joins.' : 'Generate an offer for an approved candidate to see it here.'} /></div>}
        </div>
      )}

      {/* The ONE offer form — also mounted by the candidate workspace and reused for Revise */}
      <GenerateOfferDrawer
        open={showModal}
        eligible={readyForOffer}
        candidateId={genFor?.candidateId ?? null}
        candidateName={genFor?.candidateName || ''}
        reviseOffer={reviseOffer}
        onClose={() => { setShowModal(false); setGenFor(null); setReviseOffer(null) }}
        onCreated={(offer) => { setOffers(prev => [offer, ...prev]); setGenFor(null) }}
        onRevised={(offer) => { patchOffer(offer.id, offer); setReviseOffer(null) }}
        showToast={showToast}
      />

      {/* Offer revision history (immutable) */}
      {history.open && (
        <div className="modal-backdrop" onClick={()=>setHistory({open:false,offer:null,revisions:[]})}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'85vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Offer Revision History</h2>
              <button onClick={()=>setHistory({open:false,offer:null,revisions:[]})} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>Current version: <b style={{ color:'var(--text-h)' }}>v{history.offer?.version}</b> · {history.revisions.length} prior version(s) preserved.</p>
            <div className="space-y-2">
              {history.revisions.length === 0 && <p className="text-sm" style={{ color:'var(--text-muted)' }}>No prior versions.</p>}
              {history.revisions.map(r => (
                <div key={r.id} className="px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold" style={{ color:'#a78bfa' }}>Version {r.version}</span>
                    <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{fmtDate(r.created_at)}{r.revised_by_name ? ` · ${r.revised_by_name}` : ''}</span>
                  </div>
                  <p className="text-[11px] mt-1" style={{ color:'var(--text-h)' }}>{r.snapshot?.position} · {fmtCTC(r.snapshot?.offered_ctc)} · status {r.snapshot?.status}</p>
                  {r.revision_reason && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>Reason: {r.revision_reason}</p>}
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
      {/* Offer Reject Reason Modal */}
      {rejectModal.open && (
        <div className="modal-backdrop" onClick={()=>setRejectModal({open:false,id:null,reason:''})}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <div className="flex items-center gap-2">
                <AlertCircle size={18} style={{ color:'#f87171' }}/>
                <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Reject Offer</h2>
              </div>
              <button onClick={()=>setRejectModal({open:false,id:null,reason:''})} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-4">
              <p className="text-sm" style={{ color:'var(--text-muted)' }}>Please provide a reason for rejecting this offer. This will be recorded and can be communicated to the candidate.</p>
              <div>
                <label className="label">Rejection Reason *</label>
                <textarea rows={3} className="input-3d text-sm resize-none" placeholder="e.g. Candidate declined the offer, salary mismatch..." value={rejectModal.reason} onChange={e=>setRejectModal(m=>({...m,reason:e.target.value}))}/>
              </div>
              <div className="flex gap-3">
                <button onClick={()=>setRejectModal({open:false,id:null,reason:''})} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={()=>handleStatus(rejectModal.id,'Rejected',rejectModal.reason)} disabled={!rejectModal.reason || saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#ef4444,#dc2626)', opacity:(!rejectModal.reason||saving)?0.5:1 }}>Confirm Reject</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
