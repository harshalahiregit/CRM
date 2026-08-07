import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Pencil, UserX, Download, Printer, X, FileText, Eye, LogOut,
  LayoutDashboard, User, Briefcase, FileCheck, Landmark, History, FolderOpen,
  CalendarCheck, CalendarDays, Target, GraduationCap, Mail, Boxes,
  Sparkles, ShieldCheck, Wallet,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import { canManageHrQueue } from '../constants'
import EmployeeLifecyclePanel from '../components/EmployeeLifecyclePanel'
import EmployeeLoanCard from '../components/EmployeeLoanCard'
import EmployeeScoreCard from '../components/EmployeeScoreCard'
import EmployeeSkillsPanel from '../components/EmployeeSkillsPanel'      // #43
import EmployeeAttendancePanel from '../components/EmployeeAttendancePanel' // #38
import { useMasterData, withInactive } from '@/modules/hr/useMasterData'
import { offerPortalApi } from '@/services/offerPortalApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import EmployeeNotifications from '@/modules/notifications/EmployeeNotifications'
import EmployeeSalarySection from '@/modules/hr/components/EmployeeSalarySection'
import EmployeeAssetsPanel from '@/modules/hr/components/EmployeeAssetsPanel'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const deptColor = d => DEPT_COLORS[d]||'#7C3AED'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const money    = v => (v === null || v === undefined || v === '') ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const DOC_ST = s => s==='Verified'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}

const has = v => v !== null && v !== undefined && String(v).trim() !== ''

// Laravel's response()->json(null) serialises to `{}` (Symfony turns null into an
// empty ArrayObject), and `{}` is truthy — so a "no record" response slipped past
// truthiness guards and the render then read .progress.cleared off undefined.
// Treat an object with no keys as absent.
const present = v => (v && typeof v === 'object' && Object.keys(v).length === 0 ? null : (v || null))
const pct = (filled, total) => total ? Math.round((filled/total)*100) : 0

// Canonical document list for the Documents tab, mapped onto stored onboarding types.
// `virtual` entries are generated letters (not uploaded files) and don't count toward completeness.
const DOC_LIST = [
  { key:'aadhaar', label:'Aadhaar' },
  { key:'pan', label:'PAN' },
  { key:'passport', label:'Passport' },
  { key:'resume', label:'Resume' },
  { key:'educational_certificate', label:'Educational Certificates' },
  { key:'experience_document', label:'Experience Documents' },
  { key:'offer_letter', label:'Offer Letter', virtual:true },
  { key:'appointment_letter', label:'Appointment Letter', virtual:true },
  { key:'confirmation_letter', label:'Confirmation Letter', virtual:true },
  { key:'nda', label:'NDA', virtual:true },
]

// The 12 lifecycle tabs. Present ones read live data; future ones are structure-only
// placeholders that later plug into their own modules (no business logic here).
const TABS = [
  { key:'overview',    label:'Overview',             icon:LayoutDashboard },
  { key:'personal',    label:'Personal Information',  icon:User },
  { key:'employment',  label:'Employment',            icon:Briefcase },
  { key:'documents',   label:'Documents',             icon:FileCheck },
  { key:'bank',        label:'Bank & Tax',            icon:Landmark },
  { key:'assets',      label:'Assets',                icon:Boxes },
  { key:'attendance',  label:'Attendance',            icon:CalendarCheck },
  { key:'leave',       label:'Leave',                 icon:CalendarDays },
  { key:'performance', label:'Performance',           icon:Target },
  { key:'exit',        label:'Exit',                  icon:LogOut },
  { key:'training',    label:'Training',              icon:GraduationCap },
  { key:'probation',   label:'Probation',             icon:ShieldCheck },
  { key:'letters',     label:'Letters',               icon:Mail },
  { key:'work',        label:'Work',                  icon:FolderOpen },
  { key:'timeline',    label:'Timeline',              icon:History },
]

// Dynamic profile completeness from the existing profile payload (client-side; no API).
function computeCompleteness(data) {
  const e = data.employee, s = data.submission || {}
  const basic = [
    e.name, e.email, e.phone, e.dob || s.personal?.dob, e.gender || s.personal?.gender,
    e.address || s.address?.current, s.personal?.blood_group, s.personal?.marital_status,
    s.emergency?.name, data.recruitment?.nationality,
  ]
  const employment = [e.department, e.designation, e.reporting_manager_name, e.joining_date, e.confirmation_date]
  const bank = [s.bank?.account_name, s.bank?.bank_name, s.bank?.account_number, s.bank?.ifsc]
  const realDocs = DOC_LIST.filter(d => !d.virtual)
  const docByType = Object.fromEntries((data.documents || []).map(d => [d.type, d]))
  const docsFilled = realDocs.filter(d => docByType[d.key]).length

  const sections = [
    { label:'Basic Information', pct: pct(basic.filter(has).length, basic.length) },
    { label:'Employment',        pct: pct(employment.filter(has).length, employment.length) },
    { label:'Documents',         pct: pct(docsFilled, realDocs.length) },
    { label:'Bank',              pct: pct(bank.filter(has).length, bank.length) },
  ]
  const overall = Math.round(sections.reduce((a, x) => a + x.pct, 0) / sections.length)
  return { sections, overall }
}

// Probation status derived from existing employee fields (no probation module needed).
const probationStatus = (e) => {
  if (e.confirmation_date) return { label:'Confirmed', c:'#10b981' }
  if (e.probation_end_date) {
    const ended = new Date(e.probation_end_date) < new Date()
    return ended ? { label:'Probation Ended', c:'#f59e0b' } : { label:'On Probation', c:'#3b82f6' }
  }
  return { label:'Not set', c:'var(--text-muted)' }
}

export default function EmployeeProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  // #39/#40 — scores and risk factors are HR-only; the card degrades to a
  // read-only view (or a plain message) for anyone else.
  const { user } = useAuth()
  const canManageHr = canManageHrQueue(user)
  const [data, setData]     = useState(null)
  const [orgOpts, setOrgOpts] = useState(null)   // reuse existing Organization Setup options (read-only)
  const [salary, setSalary] = useState(null)     // Payroll Phase 3 — current + history (read-only here)
  const [payslips, setPayslips] = useState([])   // Payroll Phase 5 — payslip history (read-only)
  const [perf, setPerf] = useState(null)         // PMS Phase 7 — performance timeline (read-only)
  const [leave, setLeave] = useState(null)       // Leave Phase 2 — balances (read-only)
  const [leaveApps, setLeaveApps] = useState([]) // Leave Phase 3/4 — applications history (read-only)
  const [holidays, setHolidays] = useState([])   // Leave Phase 5 — upcoming holidays (read-only)
  const [exit, setExit] = useState({ loaded:false, req:null }) // Exit Phase 2 — current exit request (read-only)
  const [exitClr, setExitClr] = useState({ loaded:false, data:null }) // Exit Phase 4 — clearance progress (read-only)
  const [exitSet, setExitSet] = useState({ loaded:false, data:null }) // Exit Phase 5 — settlement summary (read-only)
  const [training, setTraining] = useState({ loaded:false, data:[] }) // L&D Phase 4 — training assignments (read-only)
  const [trainingRec, setTrainingRec] = useState([]) // L&D Phases 5-6 — attendance/assessment/quiz/certificate (read-only)
  const [probation, setProbation] = useState({ loaded:false, data:[] }) // Probation Phase 2 — probation history (read-only)
  const [probReviews, setProbReviews] = useState([]) // Probation Phase 3 — review history (read-only)
  const [probExts, setProbExts] = useState([]) // Probation Phase 4 — extension history (read-only)
  const [probConfs, setProbConfs] = useState([]) // Probation Phase 5 — confirmation (read-only)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [tab, setTab]       = useState('overview')
  const [toast, setToast]   = useState(null)
  const [editing, setEditing] = useState(false)
  // Asset counters for Overview; the Assets tab reads the same Inventory register.
  const [assetSummary, setAssetSummary] = useState(null)
  const [assetFilter, setAssetFilter]   = useState('all')

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const load = useCallback(async () => {
    try { setData(await hrApi.employees.profile(id)) } catch { setNotFound(true) } finally { setLoading(false) }
  }, [id])
  useEffect(()=>{ load() },[load])
  // Grade / Role names come from the existing Organization Setup masters — no new API.
  useEffect(()=>{ hrApi.organization.options().then(setOrgOpts).catch(()=>{}) },[])
  // Current salary + history from Payroll Phase 3 — displayed read-only in Bank & Tax.
  useEffect(()=>{ hrApi.payroll.employeeSalary.get(id).then(setSalary).catch(()=>{}) },[id])
  // Payslip history from Payroll Phase 5 — read-only, with PDF download.
  useEffect(()=>{ hrApi.payroll.payslips.forEmployee(id).then(setPayslips).catch(()=>{}) },[id])
  // Asset counters come from the Inventory register — HRMS stores none of them.
  useEffect(()=>{ hrApi.employees.assetSummary(id).then(setAssetSummary).catch(()=>{}) },[id])
  // Performance timeline from PMS Phase 7 — read-only (goals/reviews/promotion/increment).
  useEffect(()=>{ hrApi.performance.timeline(id).then(setPerf).catch(()=>{}) },[id])
  // Leave balances from Leave Phase 2 — read-only summary in the Leave tab.
  useEffect(()=>{ hrApi.leave.balances.forEmployee(id).then(setLeave).catch(()=>{}) },[id])
  // Leave applications history from Leave Phase 3/4 — read-only.
  useEffect(()=>{ hrApi.leave.approvals.history(id).then(setLeaveApps).catch(()=>{}) },[id])
  // Upcoming holidays applicable to this employee (Leave Phase 5) — read-only.
  useEffect(()=>{ const today=new Date().toISOString().slice(0,10); hrApi.leave.holidays.list({ employee_id:id, status:'Active', from:today }).then(r=>setHolidays(r.data||[])).catch(()=>{}) },[id])
  // Current (non-withdrawn) exit request from Exit Phase 2 — read-only in the Exit tab.
  useEffect(()=>{ hrApi.exit.requests.forEmployee(id).then(req=>setExit({ loaded:true, req:present(req) })).catch(()=>setExit({ loaded:true, req:null })) },[id])
  // Departmental clearance progress from Exit Phase 4 — read-only in the Exit tab.
  useEffect(()=>{ hrApi.exit.clearances.forEmployee(id).then(d=>setExitClr({ loaded:true, data:present(d) })).catch(()=>setExitClr({ loaded:true, data:null })) },[id])
  // Full & Final settlement summary from Exit Phase 5 — read-only in the Exit tab.
  useEffect(()=>{ hrApi.exit.settlements.forEmployee(id).then(d=>setExitSet({ loaded:true, data:present(d) })).catch(()=>setExitSet({ loaded:true, data:null })) },[id])
  // Training assignments from L&D Phase 4 — read-only in the Training tab.
  useEffect(()=>{ hrApi.learning.assignments.forEmployee(id).then(d=>setTraining({ loaded:true, data:Array.isArray(d)?d:[] })).catch(()=>setTraining({ loaded:true, data:[] })) },[id])
  // Training records (attendance/assessment/quiz/certificate) from L&D Phases 5-6 — read-only.
  useEffect(()=>{ hrApi.learning.completion.forEmployee(id).then(d=>setTrainingRec(Array.isArray(d)?d:[])).catch(()=>setTrainingRec([])) },[id])
  // Probation history from Probation Phase 2 — read-only in the Probation tab.
  useEffect(()=>{ hrApi.probation.employees.forEmployee(id).then(d=>setProbation({ loaded:true, data:Array.isArray(d)?d:[] })).catch(()=>setProbation({ loaded:true, data:[] })) },[id])
  // Probation reviews from Probation Phase 3 — read-only review history in the Probation tab.
  useEffect(()=>{ hrApi.probation.reviews.forEmployee(id).then(d=>setProbReviews(Array.isArray(d)?d:[])).catch(()=>setProbReviews([])) },[id])
  // Probation extensions from Probation Phase 4 — read-only extension history in the Probation tab.
  useEffect(()=>{ hrApi.probation.extensions.forEmployee(id).then(d=>setProbExts(Array.isArray(d)?d:[])).catch(()=>setProbExts([])) },[id])
  // Probation confirmation from Probation Phase 5 — read-only in the Probation tab.
  useEffect(()=>{ hrApi.probation.confirmations.forEmployee(id).then(d=>setProbConfs(Array.isArray(d)?d:[])).catch(()=>setProbConfs([])) },[id])

  const completeness = useMemo(()=> data ? computeCompleteness(data) : null, [data])

  if (loading) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Loading profile…</div>
  if (notFound || !data) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Employee not found.</div>

  const e = data.employee
  const ss = STATUS_S(e.status)
  const dc = deptColor(e.department)
  const prob = probationStatus(e)
  const docByType = Object.fromEntries((data.documents||[]).map(d=>[d.type,d]))
  const pendingDocs = (data.documents||[]).filter(d => (d.status||'Pending')==='Pending').length
  const gradeName = orgOpts?.grades?.find(g => g.id === e.grade_id)?.name
  const roleName  = orgOpts?.roles?.find(r => r.id === e.job_role_id)?.name

  const viewDoc = async (docId) => {
    try { const blob = await hrApi.onboarding.documentBlob(data.onboarding_id, docId); const url = URL.createObjectURL(blob); window.open(url,'_blank','noopener'); setTimeout(()=>URL.revokeObjectURL(url),30000) }
    catch { showToast('Failed to open document','error') }
  }
  const downloadDoc = async (docId, name) => {
    try { const blob = await hrApi.onboarding.documentBlob(data.onboarding_id, docId); const url = URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=name||'document'; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1500) }
    catch { showToast('Failed to download','error') }
  }
  const offerLetterUrl = data.offer?.access_token ? offerPortalApi.letterUrl(data.offer.access_token) : null

  const deactivate = async () => {
    if (e.status==='Inactive') return
    if (!window.confirm(`Deactivate ${e.name}? This records an Exit event in the timeline.`)) return
    try { await hrApi.employees.update(e.id, { status:'Inactive' }); showToast('Employee deactivated'); load() }
    catch (err) { showToast(err.response?.data?.message||'Failed','error') }
  }
  const downloadProfile = () => {
    const html = buildProfileHtml(data)
    const blob = new Blob([html], { type:'text/html' })
    const url = URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=`${e.employee_code}-profile.html`; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1500)
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      <style>{`@media print { .no-print{display:none!important} .card-3d{box-shadow:none!important;border:1px solid #ddd!important} }`}</style>
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl no-print" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <button onClick={()=>navigate('/app/hr/employees')} className="no-print flex items-center gap-1.5 text-xs font-semibold" style={{ color:'#a78bfa' }}><ArrowLeft size={14}/> Back to Employees</button>

      {/* ── Header ── */}
      <div className="card-3d" style={{ padding:'22px' }}>
        <div className="flex items-start justify-between flex-wrap gap-4">
          <div className="flex items-center gap-4">
            <div className="rounded-3xl flex items-center justify-center font-black text-white flex-shrink-0" style={{ width:72, height:72, fontSize:24, background:`linear-gradient(145deg,${dc}cc,${dc})`, boxShadow:`0 8px 22px ${dc}45` }}>{initials(e.name)}</div>
            <div>
              <div className="flex items-center gap-2 flex-wrap">
                <h1 className="font-black" style={{ fontSize:'clamp(1.2rem,2vw,1.5rem)', color:'var(--text-h)' }}>{e.name}</h1>
                <span className="text-[11px] font-bold px-2.5 py-1 rounded-xl" style={{ background:ss.bg, color:ss.c }}>{e.status}</span>
                <span className="text-[11px] font-bold px-2.5 py-1 rounded-xl" style={{ background:`${prob.c}1f`, color:prob.c }}>{prob.label}</span>
              </div>
              <p className="text-sm mt-0.5" style={{ color:'var(--text-muted)' }}>{e.designation} · <span style={{ color:dc, fontWeight:700 }}>{e.department}</span></p>
              <div className="flex items-center gap-3 mt-1.5 flex-wrap text-xs" style={{ color:'var(--text-muted)' }}>
                <span className="font-mono font-bold" style={{ color:'#a78bfa' }}>{e.employee_code}</span>
                <span>Joined {fmtDate(e.joining_date)}</span>
                <span>Reports to {e.reporting_manager_name||'—'}</span>
              </div>
            </div>
          </div>
          <div className="no-print flex items-center gap-2 flex-wrap">
            <button onClick={()=>setEditing(true)} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Pencil size={13}/> Edit</button>
            <button onClick={()=>navigate(`/app/hr/employees/${e.id}/exit-interview`)} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'rgba(245,158,11,0.1)', color:'#f59e0b', border:'1px solid rgba(245,158,11,0.25)' }}><LogOut size={13}/> Exit Interview</button>
            <button onClick={deactivate} disabled={e.status==='Inactive'} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', opacity:e.status==='Inactive'?0.5:1 }}><UserX size={13}/> Deactivate</button>
            <button onClick={downloadProfile} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Download size={13}/> Download</button>
            <button onClick={()=>window.print()} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Printer size={13}/> Print</button>
          </div>
        </div>
      </div>

      {/* ── Onboarding lifecycle — derived from the employee-onboarding record.
             Shown ALONGSIDE the employee status; it never replaces it. ── */}
      {(() => {
        const st = e.onboarding_status
        const p = Number(e.onboarding_progress || 0)
        const o = ({
          Pending:     { c:'#d97706', bg:'rgba(245,158,11,0.14)' },
          In_Progress: { c:'#2563eb', bg:'rgba(37,99,235,0.12)' },
          Completed:   { c:'#059669', bg:'rgba(16,185,129,0.12)' },
        })[st] || { c:'var(--text-muted)', bg:'var(--bg-input)' }
        return (
          <div className="card-3d" style={{ padding:'18px' }}>
            <div className="flex items-center justify-between gap-4 flex-wrap">
              <div>
                <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>Employee Status</p>
                <p className="text-sm font-black mt-0.5" style={{ color:ss.c }}>{e.status}</p>
              </div>
              <div style={{ flex:1, minWidth:180 }}>
                <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>Onboarding Status</p>
                {!st ? (
                  <p className="text-xs font-semibold mt-1" style={{ color:'var(--text-muted)' }}>Onboarding not started</p>
                ) : (
                  <>
                    <span className="inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-lg mt-1" style={{ background:o.bg, color:o.c }}>
                      {String(st).replace('_',' ')} {p ? `(${p}%)` : ''}
                    </span>
                    <div className="mt-2 rounded-full" style={{ height:6, background:'var(--bg-input)' }}>
                      <div className="h-full rounded-full transition-all" style={{ width:`${p}%`, background:o.c }}/>
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>
        )
      })()}

      {/* ── Tabs ── */}
      <div className="no-print flex gap-1.5 overflow-x-auto scrollbar-hide">
        {TABS.map(t=>(
          <button key={t.key} onClick={()=>setTab(t.key)} className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all"
            style={{ background: tab===t.key ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: tab===t.key ? '#fff' : 'var(--text-muted)', border:`1px solid ${tab===t.key?'transparent':'var(--border)'}` }}>
            <t.icon size={13}/> {t.label}
          </button>
        ))}
      </div>

      {/* ── Tab content ── */}
      <div className="card-3d" style={{ padding:'22px' }}>
        {tab==='overview' && (
          <div className="space-y-5">
            {/* Quick cards */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
              <QuickCard label="Profile Completion" value={`${completeness.overall}%`} color="#7C3AED" bar={completeness.overall} />
              <QuickCard label="Pending Documents" value={pendingDocs} color={pendingDocs?'#f59e0b':'#10b981'} />
              <QuickCard label="Pending Training" value="—" color="#a78bfa" note="L&D" />
              <QuickCard label="Pending Letters" value="—" color="#ec4899" note="Letters" />
            </div>

            {/* Assets, straight from the Inventory register — each card filters the Assets tab. */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {[
                { k:'assigned',          f:'assigned',    label:'Assigned Assets',   color:'#0ca30c' },
                { k:'returned',          f:'returned',    label:'Returned',          color:'#8b8b8b' },
                { k:'under_maintenance', f:'maintenance', label:'Under Maintenance', color:'#fab219' },
                { k:'lost',              f:'lost',        label:'Lost',              color:'#d03b3b' },
              ].map(c => (
                <QuickCard key={c.k} label={c.label} color={c.color} note="Inventory"
                  value={assetSummary ? assetSummary[c.k] : '—'}
                  onClick={()=>{ setAssetFilter(c.f); setTab('assets') }} />
              ))}
            </div>

            {/* Core identity */}
            <Grid>
              <Field k="Employee ID" v={e.employee_code} mono/>
              <Field k="Status" v={e.status}/>
              <Field k="Department" v={e.department}/>
              <Field k="Designation" v={e.designation}/>
              <Field k="Reporting Manager" v={e.reporting_manager_name}/>
              <Field k="Assigned Project" v={data.assigned_project?.name || e.project?.name}/>
              <Field k="Joining Date" v={fmtDate(e.joining_date)}/>
              <Field k="Probation Status" v={prob.label}/>
              <Field k="Confirmation Date" v={fmtDate(e.confirmation_date)}/>
              <Field k="Offer" v={data.offer ? `${data.offer.reference} · ${data.offer.status}` : '—'}/>
            </Grid>

            {/* Profile completeness breakdown */}
            <div>
              <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Profile Completeness</p>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2.5">
                {completeness.sections.map(s=>(
                  <div key={s.label}>
                    <div className="flex justify-between mb-1">
                      <span className="text-xs" style={{ color:'var(--text-muted)' }}>{s.label}</span>
                      <span className="text-xs font-black" style={{ color: s.pct>=80?'#10b981':s.pct>=50?'#f59e0b':'#f87171' }}>{s.pct}%</span>
                    </div>
                    <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${s.pct}%`, background: s.pct>=80?'#10b981':s.pct>=50?'#f59e0b':'#f87171' }}/></div>
                  </div>
                ))}
              </div>
            </div>

            <EmployeeNotifications employeeId={id} />

            <AiInsight hint="Will highlight missing information and suggest which fields to complete for a full profile." />
          </div>
        )}

        {tab==='personal' && (
          <div>
            <Grid>
              <Field k="Full Name" v={e.name}/>
              <Field k="Date of Birth" v={fmtDate(e.dob) !== '—' ? fmtDate(e.dob) : fmtDate(data.submission?.personal?.dob)}/>
              <Field k="Gender" v={e.gender || data.submission?.personal?.gender}/>
              <Field k="Blood Group" v={data.submission?.personal?.blood_group}/>
              <Field k="Marital Status" v={data.submission?.personal?.marital_status}/>
              <Field k="Nationality" v={data.recruitment?.nationality}/>
              <Field k="Mobile" v={e.phone}/>
              <Field k="Email" v={e.email}/>
              <Field k="Father / Guardian" v={data.submission?.personal?.father_name}/>
              <Field k="Present Address" v={data.submission?.address?.current || e.address} full/>
              <Field k="Permanent Address" v={data.submission?.address?.permanent} full/>
              <Field k="City / State" v={[data.submission?.address?.city, data.submission?.address?.state].filter(Boolean).join(', ')}/>
              <Field k="Pincode" v={data.submission?.address?.pincode}/>
              <Field k="Emergency Contact" v={data.submission?.emergency?.name ? `${data.submission.emergency.name}${data.submission.emergency.relation?` (${data.submission.emergency.relation})`:''}${data.submission.emergency.phone?` · ${data.submission.emergency.phone}`:''}` : '—'} full/>
            </Grid>
            <AiInsight hint="Will flag missing personal details (emergency contact, blood group, nationality) needed for compliance." />
          </div>
        )}

        {tab==='employment' && (
          <div>
            <Grid>
              <Field k="Department" v={e.department}/>
              <Field k="Designation" v={e.designation}/>
              <Field k="Grade" v={gradeName || (e.grade_id ? '—' : 'Not assigned')}/>
              <Field k="Role" v={roleName || (e.job_role_id ? '—' : 'Not assigned')}/>
              <Field k="Reporting Manager" v={e.reporting_manager_name}/>
              <Field k="Joining Date" v={fmtDate(e.joining_date)}/>
              <Field k="Confirmation Date" v={fmtDate(e.confirmation_date)}/>
              <Field k="Employment Status" v={e.status}/>
              <Field k="Probation" v={fmtDate(e.probation_end_date)!=='—' ? `Until ${fmtDate(e.probation_end_date)}` : (data.offer?.probation_period||'—')}/>
              <Field k="Notice Period" v={data.offer?.notice_period}/>
            </Grid>
            <p className="text-[11px] mt-3 px-3 py-2 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
              Grade &amp; Role link to <b>Organization Setup</b> masters.
            </p>

            {/* Read-only Salary Structure (central Salary Engine — managed under Payroll). */}
            <div className="mt-5">
              <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}><Wallet size={12}/> Salary Structure</p>
              <EmployeeSalarySection employeeId={id} />
            </div>

            {/* #43 — the employee's own skills, scored against the expected skills
                of the department / designation / grade / role shown above. Sits on
                this tab because those four fields are what it is scored against. */}
            <EmployeeSkillsPanel employeeId={id} canManage={canManageHr} />

            {/* #40 — the "coming soon" note here promised promotion and role-change
                suggestions. RecommendationService::generatePromotion already derives
                them from the latest rating and completed goals, and they are listed
                on the Performance tab. */}
            <p className="text-[11px] mt-3" style={{ color:'var(--text-muted)' }}>
              Promotion and increment recommendations are under <b>Performance</b>.
            </p>
          </div>
        )}

        {tab==='documents' && (
          <div className="space-y-2">
            <p className="text-xs mb-2" style={{ color:'var(--text-muted)' }}>Documents are reused from onboarding verification — read-only here. Full document management arrives in a later phase.</p>
            {DOC_LIST.map(d=>{
              const doc = docByType[d.key]
              const isOffer = d.key==='offer_letter'
              const st = doc ? DOC_ST(doc.status) : null
              return (
                <div key={d.key} className="flex items-center gap-2 px-3 py-2.5 rounded-xl flex-wrap" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <FileText size={13} style={{ color:'#a78bfa' }}/>
                  <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{d.label}</span>
                  {d.virtual && !doc && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Generated</span>}
                  {doc && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:st.bg, color:st.c }}>{doc.status}</span>}
                  {doc && <span className="text-[10px] truncate" style={{ color:'var(--text-muted)', maxWidth:180 }}>{doc.original_name}</span>}
                  <div className="ml-auto flex items-center gap-1.5">
                    {doc ? (
                      <>
                        <button onClick={()=>viewDoc(doc.id)} className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa' }}><Eye size={11}/> View</button>
                        <button onClick={()=>downloadDoc(doc.id, doc.original_name)} className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Download size={11}/> Download</button>
                      </>
                    ) : isOffer && offerLetterUrl ? (
                      <a href={offerLetterUrl} target="_blank" rel="noopener noreferrer" className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa' }}><Eye size={11}/> View</a>
                    ) : (
                      <span className="text-[10px] font-semibold" style={{ color:'var(--text-muted)' }}>Not available</span>
                    )}
                  </div>
                </div>
              )
            })}
            <AiInsight hint="Will detect missing or expiring documents (Aadhaar, PAN, passport) and prompt for uploads." />
          </div>
        )}

        {tab==='bank' && (
          <div>
            {/* #38 — loan position sits with the other money on this profile.
                Renders nothing when the employee has no loans. */}
            <EmployeeLoanCard employeeId={id} />

            <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Bank Details</p>
            <Grid>
              <Field k="Account Holder" v={data.submission?.bank?.account_name}/>
              <Field k="Bank" v={data.submission?.bank?.bank_name}/>
              <Field k="Account Number" v={data.submission?.bank?.account_number} mono/>
              <Field k="IFSC" v={data.submission?.bank?.ifsc} mono/>
              <Field k="Branch" v={data.submission?.bank?.branch}/>
            </Grid>
            <p className="text-[11px] font-bold uppercase mt-5 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Tax (read-only — managed under Payroll)</p>
            <Grid>
              <Field k="PAN" v={data.submission?.bank?.pan}/>
              <Field k="Tax Regime" v={null}/>
              <Field k="Investment Declaration" v={null}/>
              <Field k="Form 16" v={null}/>
            </Grid>

            {/* Payroll — current salary + history (Payroll Phase 3). Read-only here;
                assign/revise happens in Payroll → Employee Salary. */}
            <div className="flex items-center justify-between mt-5 mb-2">
              <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Payroll — Current Salary</p>
              {salary?.current && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>Active</span>}
            </div>
            {salary?.current ? (
              <>
                <Grid>
                  <Field k="Salary Structure" v={salary.current.structure_name}/>
                  <Field k="Effective Date" v={fmtDate(salary.current.effective_from)}/>
                  <Field k="Annual CTC" v={money(salary.current.annual_ctc)}/>
                  <Field k="Monthly CTC" v={money(salary.current.monthly_ctc)}/>
                  <Field k="Gross Salary" v={money(salary.current.gross_salary)}/>
                  <Field k="Benefits" v={money(salary.current.total_benefits)}/>
                  <Field k="Deductions" v={money(salary.current.total_deductions)}/>
                  <Field k="Net Salary" v={money(salary.current.net_salary)}/>
                </Grid>
                {salary.history?.length > 1 && (
                  <>
                    <p className="text-[11px] font-bold uppercase mt-4 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Salary History</p>
                    <div className="space-y-1.5">
                      {salary.history.map(h => (
                        <div key={h.id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)', opacity:h.status==='active'?1:0.7 }}>
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{h.structure_name}</span>
                            <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{fmtDate(h.effective_from)}{h.effective_to?` → ${fmtDate(h.effective_to)}`:' → present'}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-bold" style={{ color:'#10b981' }}>{money(h.monthly_ctc)}/mo</span>
                            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={h.status==='active'?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-card)',color:'var(--text-muted)'}}>{h.status}</span>
                          </div>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </>
            ) : (
              <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No salary assigned yet — assign one from <b>Payroll → Employee Salary</b>.</p>
            )}

            {/* Payslips (Payroll Phase 5) — read-only history with PDF download. */}
            <p className="text-[11px] font-bold uppercase mt-5 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Payslips</p>
            {payslips.length === 0 ? (
              <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No payslips yet — generated from a completed payroll run.</p>
            ) : (
              <div className="space-y-1.5">
                {payslips.map(p => (
                  <div key={p.id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)', opacity:p.status==='Cancelled'?0.6:1 }}>
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-xs font-mono font-bold" style={{ color:'#a78bfa' }}>{p.payslip_number}</span>
                      <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>{p.period_label}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold" style={{ color:'#10b981' }}>{money(p.net_salary)}</span>
                      <button onClick={()=>hrApi.payroll.payslips.download(p.id, `${p.payslip_number}.pdf`).catch(()=>showToast('Download failed','error'))}
                        className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa' }}>
                        <Download size={11}/> PDF
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            <AiInsight hint="Reserved for payroll & tax integration — will validate PAN/bank details and flag mismatches." />
          </div>
        )}

        {tab==='assets' && (
          <EmployeeAssetsPanel employeeId={id} filter={assetFilter} onFilterChange={setAssetFilter} />
        )}

        {tab==='attendance' && (
          // #38 — was a static "(Not available until integration)" note that would
          // have stayed on screen even with SangoeTrack connected and days synced,
          // because nothing here ever fetched. Now reads the existing
          // GET /hr/employees/{id}/attendance and offers the on-demand pull.
          <EmployeeAttendancePanel employeeId={id} canManage={canManageHr} />
        )}

        {tab==='leave' && (
          <div className="space-y-4">
            {/* Read-only leave summary (Leave Phase 2). Allocation/adjustment happens in Leave Management. */}
            <Field k="Current Leave Policy" v={leave?.current_policy?.name} full/>
            {!leave ? <p className="text-sm py-2" style={{ color:'var(--text-muted)' }}>Loading leave balances…</p>
              : (leave.balances||[]).length===0 ? (
                <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No leave balances yet — assign a policy from <b>Leave Management → Leave Balance</b>.</p>
              ) : (
                <>
                  <p className="text-[11px] font-bold uppercase mb-1" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Leave Balance</p>
                  <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                    <table className="w-full text-sm" style={{ minWidth:520 }}>
                      <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Leave Type','Allocated','Used','Available','History'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                      <tbody>{leave.balances.map(b=>(
                        <tr key={b.id} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2.5"><span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:b.color||'#7C3AED' }}/><span className="font-semibold" style={{ color:'var(--text-h)' }}>{b.leave_type}</span></span></td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{b.allocated}</td>
                          <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{b.used}</td>
                          <td className="px-3 py-2.5 font-black" style={{ color:'#10b981' }}>{b.available_balance}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{b.transactions_count} txns</td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                </>
              )}

            {/* Leave applications history (Phase 3/4) — read-only. */}
            <p className="text-[11px] font-bold uppercase mt-5 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Leave Applications</p>
            {leaveApps.length === 0 ? (
              <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No leave applications yet.</p>
            ) : (
              <div className="space-y-1.5">
                {leaveApps.map(a => {
                  const c = a.status==='Approved'?'#10b981':a.status==='Rejected'?'#f87171':a.status==='Submitted'?'#f59e0b':'#94a3b8'
                  return (
                    <div key={a.id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)' }}>
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="w-2.5 h-2.5 rounded-full" style={{ background:a.color||'#7C3AED' }}/>
                        <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{a.leave_type}</span>
                        <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{fmtDate(a.from_date)} → {fmtDate(a.to_date)} · {a.days}d</span>
                        {a.decision_remarks && <span className="text-[10px] italic" style={{ color:'var(--text-muted)' }}>“{a.decision_remarks}”</span>}
                      </div>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${c}1f`, color:c }}>{a.status}</span>
                    </div>
                  )
                })}
              </div>
            )}
            {/* Upcoming holidays (Phase 5) — read-only. */}
            <p className="text-[11px] font-bold uppercase mt-5 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Upcoming Holidays</p>
            {holidays.length === 0 ? (
              <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No upcoming holidays.</p>
            ) : (
              <div className="space-y-1.5">
                {holidays.map(h => {
                  const c = h.holiday_type==='National'?'#f87171':h.holiday_type==='Festival'?'#8b5cf6':h.holiday_type==='Company'?'#3b82f6':'#f59e0b'
                  return (
                    <div key={h.id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)' }}>
                      <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{h.title}</span>
                      <div className="flex items-center gap-2">
                        <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{fmtDate(h.holiday_date)}</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${c}1f`, color:c }}>{h.holiday_type}</span>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
            {/* #40 — the "coming soon" note here promised leave patterns and balance
                risks. LeaveBehaviourDimension scores exactly that (applied vs
                approved vs rejected, days taken) and it surfaces on the Performance
                tab with its evidence. */}
            <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
              Leave-behaviour analysis is under <b>Performance → Employee Score</b>.
            </p>
          </div>
        )}

        {tab==='performance' && (
          <div className="space-y-5">
            {/* #39/#40 — the overall score and the three insight groups, above
                the goals and reviews they are computed from. */}
            <EmployeeScoreCard employeeId={id} canManage={canManageHr} />

            {!perf ? <p className="text-sm py-4" style={{ color:'var(--text-muted)' }}>Loading performance…</p> : (
              <>
                {/* Goals */}
                <div>
                  <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Goals / KRA</p>
                  {perf.goals.length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No goals assigned.</p>
                    : <div className="space-y-1.5">{perf.goals.map(g=>(
                        <div key={g.id} className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                          <div className="flex items-center justify-between flex-wrap gap-2">
                            <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{g.goal_title}</span>
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={g.status==='Completed'?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'rgba(37,99,235,0.12)',color:'#2563eb'}}>{g.status} · {g.progress}%</span>
                          </div>
                          <div className="mt-1 h-1.5 rounded-full" style={{ background:'var(--bg-card)' }}><div className="h-full rounded-full" style={{ width:`${g.progress}%`, background:'#7C3AED' }}/></div>
                        </div>
                      ))}</div>}
                </div>
                {/* Reviews */}
                <div>
                  <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Reviews &amp; Ratings</p>
                  {perf.reviews.length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No reviews yet.</p>
                    : <div className="space-y-1.5">{perf.reviews.map(r=>(
                        <div key={r.id} className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                          <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{r.review_type} · {r.period_label||'—'}</span>
                          <span className="flex items-center gap-2"><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{r.status}</span><span className="text-sm font-black" style={{ color:'#8b5cf6' }}>{r.overall_rating}</span></span>
                        </div>
                      ))}</div>}
                </div>
                {/* Promotion + Increment history */}
                <Grid>
                  <Field k="Promotion Recommendations" v={perf.promotions.length ? `${perf.promotions.length} (${perf.promotions.filter(p=>p.eligible).length} eligible)` : 'None'}/>
                  <Field k="Increment Recommendations" v={perf.increments.length ? `${perf.increments.length} · latest ${perf.increments[0].suggested_percentage}%` : 'None'}/>
                </Grid>
              </>
            )}
            {/* #40 — the "coming soon" note that used to sit here promised a
                performance summary, promotion insight and attrition risk. All three
                are built and rendered by EmployeeScoreCard at the top of this very
                tab, so the placeholder was telling the user the opposite of what the
                page shows. */}
          </div>
        )}

        {tab==='exit' && (
          <div className="space-y-4">
            {/* Read-only current exit request (Exit Phase 2). Raise / edit / withdraw happens in Exit Management. */}
            {!exit.loaded ? <p className="text-sm py-4" style={{ color:'var(--text-muted)' }}>Loading exit request…</p>
              : !exit.req ? (
                <div className="px-3 py-4 rounded-xl text-xs" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
                  No active exit request for this employee. Raise one from <b>HR Records → Exit Management → Exit Requests</b>.
                </div>
              ) : (() => {
                const r = exit.req
                const sc = r.status==='Submitted'?{c:'#3b82f6',bg:'rgba(59,130,246,0.12)'}:r.status==='Withdrawn'?{c:'#94a3b8',bg:'rgba(148,163,184,0.15)'}:{c:'var(--text-muted)',bg:'var(--bg-input)'}
                return (
                  <>
                    <div className="flex items-center justify-between flex-wrap gap-2">
                      <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Current Exit Request</p>
                      <span className="text-[10px] font-bold px-2.5 py-1 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{r.status}</span>
                    </div>
                    <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                      <Field k="Exit Type" v={r.exit_type} />
                      <Field k="Exit Policy" v={r.policy_name} />
                      <Field k="Request Date" v={fmtDate(r.request_date)} />
                      <Field k="Last Working Date" v={fmtDate(r.last_working_date)} />
                      <Field k="Notice Period" v={`${r.notice_days} day(s)`} />
                      <Field k="Notice Window" v={r.notice_start_date?`${fmtDate(r.notice_start_date)} → ${fmtDate(r.notice_end_date)}`:'—'} />
                    </div>
                    {r.reason && <Field k="Reason" v={r.reason} full />}
                    {r.employee_remarks && <Field k="Employee Remarks" v={r.employee_remarks} full />}
                    {r.hr_remarks && <Field k="HR Remarks" v={r.hr_remarks} full />}

                    {/* Approval status (Exit Phase 3) — read-only. */}
                    {(r.status==='Approved'||r.status==='Rejected'||r.decided_at||r.review_remarks) && (
                      <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4 pt-1">
                        <Field k="Approval Status" v={r.status} />
                        <Field k="Approval Date" v={fmtDate(r.decided_at)} />
                        <Field k="Approval Remarks" v={r.decision_remarks} />
                      </div>
                    )}

                    {/* Clearance progress (Exit Phase 4) — read-only. */}
                    {exitClr.loaded && exitClr.data && (() => {
                      const cl = exitClr.data
                      const cs = cl.status==='Completed'?{c:'#10b981',bg:'rgba(16,185,129,0.14)'}:cl.status==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.12)'}:cl.status==='In Progress'?{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}:{c:'var(--text-muted)',bg:'var(--bg-input)'}
                      const istyle = s => s==='Cleared'?{c:'#10b981',bg:'rgba(16,185,129,0.14)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.12)'}:s==='In Progress'?{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}:{c:'var(--text-muted)',bg:'var(--bg-input)'}
                      return (
                        <div className="pt-2">
                          <div className="flex items-center justify-between flex-wrap gap-2 mb-2">
                            <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Clearance Progress</p>
                            <div className="flex items-center gap-2">
                              <span className="text-[10px] font-bold" style={{ color:'var(--text-muted)' }}>{cl.progress.cleared}/{cl.progress.total} cleared</span>
                              <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:cs.bg, color:cs.c }}>{cl.status}</span>
                            </div>
                          </div>
                          <div className="h-1.5 rounded-full mb-3" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${cl.progress.total?Math.round(cl.progress.cleared/cl.progress.total*100):0}%`, background:'#7C3AED' }}/></div>
                          <div className="space-y-1.5">
                            {cl.items.map(it=>(
                              <div key={it.id} className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                                <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{it.department}{it.assigned_to && <span className="text-[10px] ml-2" style={{ color:'var(--text-muted)' }}>· {it.assigned_to}</span>}</span>
                                <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:istyle(it.status).bg, color:istyle(it.status).c }}>{it.status}</span>
                              </div>
                            ))}
                          </div>
                          {cl.completed_at && <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>Completion Date: {fmtDate(cl.completed_at)}</p>}
                        </div>
                      )
                    })()}

                    {/* Full & Final settlement (Exit Phase 5) — read-only. */}
                    {exitSet.loaded && exitSet.data && (() => {
                      const st = exitSet.data
                      const ss = st.status==='Settled'?{c:'#10b981',bg:'rgba(16,185,129,0.14)'}:st.status==='Approved'?{c:'#a78bfa',bg:'rgba(124,58,237,0.14)'}:st.status==='Reviewed'?{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}:st.status==='Generated'?{c:'#3b82f6',bg:'rgba(59,130,246,0.12)'}:{c:'var(--text-muted)',bg:'var(--bg-input)'}
                      const when = st.settled_at||st.approved_at||st.generated_at
                      return (
                        <div className="pt-2">
                          <div className="flex items-center justify-between flex-wrap gap-2 mb-2">
                            <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Full &amp; Final Settlement</p>
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{st.status}</span>
                          </div>
                          <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                            <Field k="Settlement Status" v={st.status} />
                            <Field k="Settlement Amount" v={st.net_settlement!=null?money(st.net_settlement):'Not generated'} />
                            <Field k="Settlement Date" v={when?fmtDate(when):'—'} />
                          </div>
                        </div>
                      )
                    })()}

                    <p className="text-[11px] font-bold uppercase mt-3 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Timeline</p>
                    <div className="space-y-2.5">
                      {(r.timeline||[]).map((t,i)=>(
                        <div key={i} className="flex gap-3">
                          <div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/>
                          <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p></div>
                        </div>
                      ))}
                      {(!r.timeline||!r.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}
                    </div>
                    <p className="text-[10px] mt-3" style={{ color:'var(--text-muted)' }}>Read-only. Manage this request from <b>HR Records → Exit Management</b>.</p>
                  </>
                )
              })()}
          </div>
        )}

        {tab==='training' && (
          <div className="space-y-4">
            {/* Read-only training assignments (L&D Phase 4). Managed in Learning & Development. */}
            {!training.loaded ? <p className="text-sm py-4" style={{ color:'var(--text-muted)' }}>Loading trainings…</p>
              : (() => {
                const rows = training.data
                if (rows.length === 0) return <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No trainings assigned yet — assign from <b>Learning &amp; Development → Assignment</b>.</p>
                const AC = { Assigned:'#3b82f6', 'In Progress':'#f59e0b', Completed:'#10b981', Cancelled:'#f87171' }
                const now = new Date()
                const current = rows.filter(r => r.status==='Assigned' || r.status==='In Progress')
                const upcoming = current.filter(r => r.session_start && new Date(r.session_start) >= now)
                const completed = rows.filter(r => r.status==='Completed')
                // #23 — the retraining number, derived from the attempt_number and
                // is_retraining the API already returns on every row. No second
                // endpoint and no counting rule of its own: a repeat is whatever
                // EmployeeTrainingService stamped it as when the training was
                // assigned, so this view can never disagree with L&D.
                const retrainings = rows.filter(r => r.is_retraining)
                const repeatedPrograms = Object.values(
                  rows.reduce((acc, r) => {
                    const k = r.program_code || r.program
                    acc[k] = acc[k] || { program: r.program, code: r.program_code, attempts: 0, latest: 0, reason: null }
                    acc[k].attempts += 1
                    if ((r.attempt_number || 1) >= acc[k].latest) {
                      acc[k].latest = r.attempt_number || 1
                      acc[k].reason = r.retraining_reason || acc[k].reason
                    }
                    return acc
                  }, {})
                ).filter(p => p.attempts > 1)

                const K = [
                  { l:'Assigned', v:current.length, c:'#3b82f6' }, { l:'Upcoming', v:upcoming.length, c:'#7C3AED' },
                  { l:'Completed', v:completed.length, c:'#10b981' }, { l:'Total', v:rows.length, c:'#f59e0b' },
                  // #23 — retraining is a headline number, not a detail: repeated
                  // trainings are the signal that something did not stick.
                  { l:'Retrainings', v:retrainings.length, c:'#f97316' },
                ]
                const Table = ({ list }) => (
                  <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                    <table className="w-full text-sm" style={{ minWidth:640 }}>
                      <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Program','Attempt','Session','Due','Completion','Status'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                      <tbody>{list.map(r=>(
                        <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.program}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.program_code}</span></td>
                          {/* #23 — which go at this programme this is. A first attempt
                              is shown plainly; only a repeat is called out, so the
                              badge means something when it appears. */}
                          <td className="px-3 py-2.5 whitespace-nowrap">
                            <span className="font-bold" style={{ color: r.is_retraining ? '#f59e0b' : 'var(--text-muted)' }}>#{r.attempt_number || 1}</span>
                            {r.is_retraining && (
                              <span title={r.retraining_reason || 'Repeat of an earlier assignment'}
                                className="ml-1.5 text-[9px] font-bold px-1.5 py-0.5 rounded-md"
                                style={{ background:'rgba(245,158,11,0.14)', color:'#f59e0b' }}>Retraining</span>
                            )}
                          </td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.session_title||'—'}<div className="text-[10px]">{r.trainer_name} · {fmtDate(r.session_start)}</div></td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.due_date)}</td>
                          <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>{r.completion_percentage}%</td>
                          <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${AC[r.status]||'#7C3AED'}1f`, color:AC[r.status]||'#7C3AED' }}>{r.status}</span></td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                )
                return (
                  <>
                    <div className="grid grid-cols-2 md:grid-cols-5 gap-4">{K.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

                    {/* #23 — programmes taken more than once, with how many times.
                        Grouped from the same rows as the tables below, so the count
                        here and the "#N / Retraining" badges there always agree. */}
                    {repeatedPrograms.length > 0 && (
                      <div className="rounded-xl p-3" style={{ background:'rgba(249,115,22,0.06)', border:'1px solid rgba(249,115,22,0.2)' }}>
                        <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'#f97316', letterSpacing:'0.04em' }}>Repeated Training</p>
                        <div className="space-y-1.5">
                          {repeatedPrograms.map(p => (
                            <div key={p.code || p.program} className="flex items-start justify-between gap-3">
                              <div className="min-w-0">
                                <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{p.program}</span>
                                {p.reason && <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{p.reason}</p>}
                              </div>
                              <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap flex-shrink-0"
                                style={{ background:'rgba(249,115,22,0.14)', color:'#f97316' }}>
                                {p.attempts} attempts · {p.attempts - 1} retraining{p.attempts - 1 === 1 ? '' : 's'}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    <div>
                      <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Current Assignments</p>
                      {current.length===0 ? <p className="text-xs px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No active trainings.</p> : <Table list={current} />}
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase mb-2 mt-4" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Completed &amp; History</p>
                      {rows.filter(r=>r.status==='Completed'||r.status==='Cancelled').length===0 ? <p className="text-xs px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No completed trainings yet.</p> : <Table list={rows.filter(r=>r.status==='Completed'||r.status==='Cancelled')} />}
                    </div>
                    <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Read-only. Manage from <b>Learning &amp; Development</b>.</p>
                  </>
                )
              })()}

            {/* Attendance / Assessment / Quiz / Certificate (L&D Phases 5-6) — read-only. */}
            {trainingRec.length > 0 && (
              <div>
                <p className="text-[11px] font-bold uppercase mb-2 mt-4" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Attendance, Assessment &amp; Certificates</p>
                <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                  <table className="w-full text-sm" style={{ minWidth:600 }}>
                    <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Program','Attendance','Assessment','Quiz','Completion','Certificate'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                    <tbody>{trainingRec.map(r=>(
                      <tr key={r.employee_training_id} style={{ borderBottom:'1px solid var(--border)' }}>
                        <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.program}</span></td>
                        <td className="px-3 py-2.5" style={{ color:r.attendance==='Present'?'#10b981':r.attendance==='Absent'?'#f87171':'var(--text-muted)' }}>{r.attendance||'—'}</td>
                        <td className="px-3 py-2.5" style={{ color:r.assessment_result==='Pass'?'#10b981':r.assessment_result==='Fail'?'#f87171':'var(--text-muted)' }}>{r.assessment_result?`${r.assessment_result}${r.assessment_pct!=null?` (${r.assessment_pct}%)`:''}`:'—'}</td>
                        <td className="px-3 py-2.5" style={{ color:r.quiz_passed===true?'#10b981':r.quiz_passed===false?'#f87171':'var(--text-muted)' }}>{r.quiz_passed===null||r.quiz_passed===undefined?'—':(r.quiz_passed?'Passed':'Failed')}</td>
                        <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>{r.completion_percentage}%</td>
                        <td className="px-3 py-2.5">{r.certificate_number ? <a onClick={()=>hrApi.learning.certificates.download(r.certificate_id)} className="text-[11px] font-mono cursor-pointer" style={{ color:'#a78bfa' }}>{r.certificate_number}</a> : '—'}</td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              </div>
            )}
            {/* #40 — the "coming soon" note removed from here promised training and
                retraining recommendations from role, skill gaps and history. Those
                are produced by EmployeeInsightService ("Close the skill gap: …",
                "Complete overdue training: …") and shown on the Performance tab, and
                the retraining figures are now on this tab (#23). */}
            <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
              Training and skill-gap recommendations appear under <b>Performance → Areas for Improvement</b>.
            </p>
          </div>
        )}

        {tab==='probation' && (
          <div className="space-y-4">
            {/* Read-only probation (Probation Phase 2). Managed in Probation Management. */}
            {!probation.loaded ? <p className="text-sm py-4" style={{ color:'var(--text-muted)' }}>Loading probation…</p>
              : probation.data.length===0 ? (
                <p className="text-xs px-3 py-3 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No probation record — assign one from <b>HR Records → Probation Management → Employee Probation</b>.</p>
              ) : (() => {
                const PC = { Assigned:'#3b82f6', Active:'#10b981', Extended:'#f59e0b', Confirmed:'#8b5cf6', Failed:'#f87171', Cancelled:'#94a3b8' }
                const current = probation.data.find(p => ['Assigned','Active','Extended'].includes(p.current_status)) || probation.data[0]
                const cs = { c:PC[current.current_status]||'#7C3AED', bg:`${PC[current.current_status]||'#7C3AED'}1f` }
                return (
                  <>
                    <div className="flex items-center justify-between flex-wrap gap-2">
                      <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Current Probation</p>
                      <span className="text-[10px] font-bold px-2.5 py-1 rounded-lg" style={{ background:cs.bg, color:cs.c }}>{current.current_status}</span>
                    </div>
                    <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                      <Field k="Current Policy" v={current.policy} />
                      <Field k="Probation Type" v={current.probation_type} />
                      <Field k="Start Date" v={fmtDate(current.probation_start_date)} />
                      <Field k="End Date" v={fmtDate(current.probation_end_date)} />
                      <Field k="Remaining Days" v={current.remaining_days!=null?(current.remaining_days<0?`${-current.remaining_days} overdue`:`${current.remaining_days} days`):'—'} />
                      <Field k="Review Cycle" v={current.review_cycle} />
                      <Field k="Extension Count" v={current.extension_count} />
                      <Field k="Confirmation Due" v={fmtDate(current.confirmation_due_date)} />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase mb-2 mt-2 flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}><History size={12}/> Timeline</p>
                      <div className="space-y-2.5">
                        {(current.timeline||[]).map((t,i)=>(
                          <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p></div></div>
                        ))}
                        {(!current.timeline||!current.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}
                      </div>
                    </div>
                    {probation.data.length>1 && (
                      <div>
                        <p className="text-[11px] font-bold uppercase mb-2 mt-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>History</p>
                        <div className="space-y-1.5">{probation.data.map(p=>(
                          <div key={p.id} className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                            <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{p.policy} <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>· {fmtDate(p.probation_start_date)} → {fmtDate(p.probation_end_date)}</span></span>
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${PC[p.current_status]||'#7C3AED'}1f`, color:PC[p.current_status]||'#7C3AED' }}>{p.current_status}</span>
                          </div>
                        ))}</div>
                      </div>
                    )}
                    <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Read-only. Manage from <b>Probation Management</b>.</p>
                  </>
                )
              })()}

            {/* Review history (Probation Phase 3) — read-only. */}
            {probReviews.length > 0 && (() => {
              const RVC = { Draft:'#94a3b8', Submitted:'#3b82f6', Completed:'#10b981' }
              const RCC = { Continue:'#3b82f6', Extend:'#f59e0b', Confirm:'#10b981', Fail:'#f87171' }
              return (
                <div>
                  <p className="text-[11px] font-bold uppercase mb-2 mt-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Review History</p>
                  <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                    <table className="w-full text-sm" style={{ minWidth:600 }}>
                      <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['#','Date','Reviewer','Rating','Recommendation','Status'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                      <tbody>{probReviews.map(r=>(
                        <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2.5 font-bold" style={{ color:'#a78bfa' }}>#{r.review_no}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.review_date)}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.reviewer_name||'—'}</td>
                          <td className="px-3 py-2.5 font-bold" style={{ color:'#f59e0b' }}>{r.overall_rating}/5</td>
                          <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${RCC[r.recommendation]||'#7C3AED'}1f`, color:RCC[r.recommendation]||'#7C3AED' }}>{r.recommendation}</span></td>
                          <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${RVC[r.status]||'#7C3AED'}1f`, color:RVC[r.status]||'#7C3AED' }}>{r.status}</span></td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                </div>
              )
            })()}

            {/* Extension history (Probation Phase 4) — read-only. */}
            {probExts.length > 0 && (() => {
              const EC = { Pending:'#f59e0b', Approved:'#10b981', Rejected:'#f87171' }
              const approved = probExts.filter(x=>x.status==='Approved')
              const latest = approved[0] || probExts[0]
              return (
                <div>
                  <div className="flex items-center justify-between flex-wrap gap-2 mb-2 mt-2">
                    <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Extension History</p>
                    <span className="text-[10px] font-bold" style={{ color:'var(--text-muted)' }}>{approved.length} approved · latest end {latest?fmtDate(latest.extended_end_date):'—'}</span>
                  </div>
                  <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                    <table className="w-full text-sm" style={{ minWidth:600 }}>
                      <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['#','Current End','Extended End','Days','Requested By','Status'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                      <tbody>{probExts.map(x=>(
                        <tr key={x.id} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2.5 font-bold" style={{ color:'#a78bfa' }}>#{x.extension_number}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(x.current_end_date)}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{fmtDate(x.extended_end_date)}</td>
                          <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>+{x.extension_days}d</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{x.requested_by_name||'—'}</td>
                          <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${EC[x.status]||'#7C3AED'}1f`, color:EC[x.status]||'#7C3AED' }}>{x.status}</span></td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                </div>
              )
            })()}

            {/* Confirmation (Probation Phase 5) — read-only. */}
            {probConfs.length > 0 && (() => {
              const c = probConfs[0]
              const CC = { Pending:'#f59e0b', Approved:'#3b82f6', Rejected:'#f87171', Confirmed:'#10b981' }
              const cs = { c:CC[c.status]||'#7C3AED', bg:`${CC[c.status]||'#7C3AED'}1f` }
              return (
                <div>
                  <div className="flex items-center justify-between flex-wrap gap-2 mb-2 mt-2">
                    <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Confirmation</p>
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:cs.bg, color:cs.c }}>{c.status}</span>
                  </div>
                  <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                    <Field k="Confirmation Status" v={c.status} />
                    <Field k="Recommendation" v={c.recommendation} />
                    <Field k="Decision" v={c.decision} />
                    <Field k="Confirmation Date" v={fmtDate(c.confirmation_date)} />
                    <Field k="Effective Date" v={fmtDate(c.effective_date)} />
                    <Field k="Review Summary" v={c.review_summary?`#${c.review_summary.review_no} · ${c.review_summary.rating}/5 · ${c.review_summary.recommendation}`:'—'} />
                    <Field k="Extension Summary" v={c.extension_summary?`#${c.extension_summary.extension_number} · +${c.extension_summary.extension_days}d`:'—'} />
                  </div>
                </div>
              )
            })()}
          </div>
        )}

        {tab==='letters' && (
          <div>
            <div className="space-y-2">
              {['Appointment Letter','Confirmation Letter','Probation Extension','Promotion Letter','Warning Letter','Experience Letter','Relieving Letter'].map(l=>(
                <div key={l} className="flex items-center gap-2 px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <Mail size={13} style={{ color:'#a78bfa' }}/>
                  <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{l}</span>
                  <span className="ml-auto text-[10px] font-semibold" style={{ color:'var(--text-muted)' }}>Not available</span>
                </div>
              ))}
            </div>
            <IntegrationNote icon={FileText} title="Letters" subtitle="Future generated letters"
              hint="System-generated letters with templates & placeholders will be produced here. The existing Offer Letter continues to live in the Offer workflow." />
          </div>
        )}

        {tab==='work' && (
          <div className="space-y-3">
            <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
              Live from the Projects, Tasks, Helpdesk and Knowledge Base modules — nothing here is a copy.
              Every row opens the record in the module that owns it.
            </p>
            <EmployeeLifecyclePanel employeeId={id} />
          </div>
        )}

        {tab==='timeline' && (
          <div>
            <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>Full lifecycle — reused from audit logs (Applied → Interview → Offer → Joining → Employee, and every subsequent change). Future events (Promotion, Asset Assigned, Confirmation, Exit) will append here automatically.</p>
            <AuditTimeline entries={data.timeline} />
            <AiInsight hint="Will produce a plain-language career summary of this employee's journey from the timeline." />
          </div>
        )}
      </div>

      {editing && <EditModal employee={e} onClose={()=>setEditing(false)} onSaved={()=>{ setEditing(false); load() }} showToast={showToast} />}
    </div>
  )
}

/* ── Overview quick card ── */
const QuickCard = ({ label, value, color, note, bar, onClick }) => (
  <div onClick={onClick} role={onClick ? 'button' : undefined}
    className={`rounded-xl px-3 py-3${onClick ? ' cursor-pointer transition-transform hover:-translate-y-0.5' : ''}`}
    style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
    <div className="flex items-center justify-between">
      <p className="text-2xl font-black" style={{ color }}>{value}</p>
      {note && <span className="text-[8.5px] font-bold px-1.5 py-0.5 rounded" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>{note}</span>}
    </div>
    <p className="text-[10.5px] font-semibold mt-1" style={{ color:'var(--text-muted)' }}>{label}</p>
    {bar !== undefined && <div className="mt-1.5 h-1 rounded-full" style={{ background:'var(--bg-card)' }}><div className="h-full rounded-full" style={{ width:`${bar}%`, background:color }}/></div>}
  </div>
)

/* ── AI-ready integration point (no AI implemented — placeholder only) ── */
const AiInsight = ({ hint }) => (
  <div className="mt-4 rounded-xl p-3 flex items-start gap-2.5" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}>
    <Sparkles size={15} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
    <div>
      <p className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>AI Insights <span className="font-normal" style={{ color:'var(--text-muted)' }}>· coming soon</span></p>
      <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>{hint}</p>
    </div>
  </div>
)

/* ── Future-module / integration placeholder ── */
const IntegrationNote = ({ icon:Icon, title, subtitle, hint, chips, big }) => (
  <div className="flex flex-col items-center justify-center text-center rounded-xl mt-2" style={{ padding: big ? '48px 20px' : '32px 20px', background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
    <div className="rounded-2xl flex items-center justify-center mb-3" style={{ width: big?60:52, height: big?60:52, background:'rgba(124,58,237,0.1)' }}><Icon size={big?26:22} style={{ color:'#a78bfa' }}/></div>
    <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{title}</p>
    {subtitle && <p className="text-xs font-semibold mt-1" style={{ color:'#a78bfa' }}>{subtitle}</p>}
    {hint && <p className="text-[11px] mt-2 max-w-md" style={{ color:'var(--text-muted)' }}>{hint}</p>}
    {chips && <div className="flex gap-1.5 flex-wrap justify-center mt-3">{chips.map(c=><span key={c} className="text-[10px] font-bold px-2 py-1 rounded-lg" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>{c}</span>)}</div>}
  </div>
)

// ── Edit modal (unchanged behaviour — same fields, same update API) ──
function EditModal({ employee, onClose, onSaved, showToast }) {
  const F = ['name','email','phone','department','designation','reporting_manager_name','joining_date','probation_end_date','confirmation_date','status']
  const [form, setForm] = useState(Object.fromEntries(F.map(k=>[k, employee[k] ?? (k==='status'?'Active':'')])))
  const [saving, setSaving] = useState(false)
  // Department / Designation / Reporting Manager from Org Setup master data (single
  // source, active-only). No hardcoded lists; saved-but-inactive values stay marked.
  const { masters } = useMasterData()
  const deptOptions    = withInactive((masters.departments  || []).map(d => d.name), form.department)
  const desigOptions   = withInactive((masters.designations || []).map(d => d.name), form.designation)
  const managerOptions = withInactive((masters.managers     || []).map(m => m.name), form.reporting_manager_name)
  const save = async () => {
    setSaving(true)
    try { await hrApi.employees.update(employee.id, form); showToast('Employee updated'); onSaved() }
    catch (err) { showToast(err.response?.data?.message||'Failed to update','error'); setSaving(false) }
  }
  const set = (k,v) => setForm(f=>({...f,[k]:v}))
  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Edit Employee</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
        <div className="space-y-3">
          <div><label className="label">Full Name</label><input className="input-3d text-sm" value={form.name} onChange={e=>set('name',e.target.value)}/></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Email</label><input className="input-3d text-sm" value={form.email||''} onChange={e=>set('email',e.target.value)}/></div>
            <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone||''} onChange={e=>set('phone',e.target.value)}/></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Department</label>
              <select className="input-3d text-sm" value={form.department} onChange={e=>set('department',e.target.value)}>
                <option value="">Select...</option>{deptOptions.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div><label className="label">Designation</label>
              <select className="input-3d text-sm" value={form.designation} onChange={e=>set('designation',e.target.value)}>
                <option value="">Select...</option>{desigOptions.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Reporting Manager</label>
              <select className="input-3d text-sm" value={form.reporting_manager_name||''} onChange={e=>set('reporting_manager_name',e.target.value)}>
                <option value="">Select…</option>{managerOptions.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div><label className="label">Status</label><select className="input-3d text-sm" value={form.status} onChange={e=>set('status',e.target.value)}>{['Active','On Leave','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Probation End</label><input type="date" className="input-3d text-sm" value={form.probation_end_date||''} onChange={e=>set('probation_end_date',e.target.value)}/></div>
            <div><label className="label">Confirmation Date</label><input type="date" className="input-3d text-sm" value={form.confirmation_date||''} onChange={e=>set('confirmation_date',e.target.value)}/></div>
          </div>
          <div className="flex gap-3 pt-1">
            <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Changes'}</button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Primitives ──
const Grid = ({ children }) => <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">{children}</div>
const Field = ({ k, v, mono, full }) => (
  <div className={full?'md:col-span-2 lg:col-span-3':''} style={{ background:'var(--bg-input)', borderRadius:12, padding:'12px 14px' }}>
    <p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{k}</p>
    <p className={`text-sm font-semibold mt-1 ${mono?'font-mono':''}`} style={{ color:'var(--text-h)', wordBreak:'break-word' }}>{v || '—'}</p>
  </div>
)

// Self-contained printable/downloadable HTML export of the full profile.
function buildProfileHtml(data) {
  const e = data.employee
  const row = (k,v) => `<tr><td style="padding:6px 12px;color:#64748b;font-size:12px">${k}</td><td style="padding:6px 12px;font-weight:600;font-size:13px">${v||'—'}</td></tr>`
  const sub = data.submission||{}
  return `<!doctype html><html><head><meta charset="utf-8"><title>${e.employee_code} — ${e.name}</title></head>
  <body style="font-family:system-ui,sans-serif;max-width:760px;margin:24px auto;color:#0f172a">
    <h1 style="margin:0">${e.name}</h1>
    <p style="color:#64748b;margin:4px 0 16px">${e.designation||''} · ${e.department||''} · ${e.employee_code} · ${e.status}</p>
    <table style="border-collapse:collapse;width:100%">
      ${row('Employee ID', e.employee_code)}${row('Status', e.status)}${row('Department', e.department)}${row('Designation', e.designation)}
      ${row('Joining Date', fmtDate(e.joining_date))}${row('Confirmation Date', fmtDate(e.confirmation_date))}${row('Reporting Manager', e.reporting_manager_name)}
      ${row('Mobile', e.phone)}${row('Email', e.email)}
      ${row('Date of Birth', fmtDate(e.dob)!=='—'?fmtDate(e.dob):fmtDate(sub.personal?.dob))}${row('Gender', e.gender||sub.personal?.gender)}${row('Blood Group', sub.personal?.blood_group)}
      ${row('Bank', sub.bank?.bank_name)}${row('Account Number', sub.bank?.account_number)}${row('IFSC', sub.bank?.ifsc)}
      ${row('Applied For', data.recruitment?.applied_job)}${row('Candidate Ref', data.recruitment?.reference)}${row('Offer', data.offer?`${data.offer.reference} · ${data.offer.status}`:'—')}
    </table>
    <p style="color:#94a3b8;font-size:11px;margin-top:20px">Generated from the Employee Management module.</p>
  </body></html>`
}
