import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Pencil, UserX, Download, Printer, X, FileText, Eye, LogOut,
  LayoutDashboard, User, Briefcase, FileCheck, Landmark, History,
  CalendarCheck, CalendarDays, Target, GraduationCap, Mail, Boxes,
  Sparkles, Plug,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { offerPortalApi } from '@/services/offerPortalApi'
import AuditTimeline from '@/components/ui/AuditTimeline'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const deptColor = d => DEPT_COLORS[d]||'#7C3AED'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const money    = v => (v === null || v === undefined || v === '') ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const DOC_ST = s => s==='Verified'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}

const has = v => v !== null && v !== undefined && String(v).trim() !== ''
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
  { key:'training',    label:'Training',              icon:GraduationCap },
  { key:'letters',     label:'Letters',               icon:Mail },
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
  const [data, setData]     = useState(null)
  const [orgOpts, setOrgOpts] = useState(null)   // reuse existing Organization Setup options (read-only)
  const [salary, setSalary] = useState(null)     // Payroll Phase 3 — current + history (read-only here)
  const [payslips, setPayslips] = useState([])   // Payroll Phase 5 — payslip history (read-only)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [tab, setTab]       = useState('overview')
  const [toast, setToast]   = useState(null)
  const [editing, setEditing] = useState(false)

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
              <QuickCard label="Assigned Assets" value="—" color="#0ea5e9" note="Inventory" />
              <QuickCard label="Pending Documents" value={pendingDocs} color={pendingDocs?'#f59e0b':'#10b981'} />
              <QuickCard label="Pending Training" value="—" color="#a78bfa" note="L&D" />
              <QuickCard label="Pending Letters" value="—" color="#ec4899" note="Letters" />
            </div>

            {/* Core identity */}
            <Grid>
              <Field k="Employee ID" v={e.employee_code} mono/>
              <Field k="Status" v={e.status}/>
              <Field k="Department" v={e.department}/>
              <Field k="Designation" v={e.designation}/>
              <Field k="Reporting Manager" v={e.reporting_manager_name}/>
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
              Grade &amp; Role link to <b>Organization Setup</b> masters. Payroll (CTC/salary structure) is intentionally out of scope here.
            </p>
            <AiInsight hint="Will suggest promotion or role changes based on tenure, grade and performance signals." />
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
            <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Bank Details</p>
            <Grid>
              <Field k="Account Holder" v={data.submission?.bank?.account_name}/>
              <Field k="Bank" v={data.submission?.bank?.bank_name}/>
              <Field k="Account Number" v={data.submission?.bank?.account_number} mono/>
              <Field k="IFSC" v={data.submission?.bank?.ifsc} mono/>
              <Field k="Branch" v={data.submission?.bank?.branch}/>
            </Grid>
            <p className="text-[11px] font-bold uppercase mt-5 mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Tax (structure only — Payroll not implemented)</p>
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
          <div>
            <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
              <table className="w-full text-sm" style={{ minWidth:560 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Asset Name','Asset Code','Serial Number','Assigned Date','Status'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody><tr><td colSpan={5} className="px-3 py-10 text-center text-xs" style={{ color:'var(--text-muted)' }}>No assets assigned.</td></tr></tbody>
              </table>
            </div>
            <IntegrationNote icon={Boxes} title="Reserved for the existing Inventory module"
              hint="Assigned assets (Laptop, Monitor, Mobile, SIM, ID Card, Access Card…) will be read from Inventory and displayed here. HRMS will not manage inventory itself." />
            <AiInsight hint="Will suggest standard assets missing for this role (e.g. laptop, access card) based on department." />
          </div>
        )}

        {tab==='attendance' && (
          <div>
            {/* Explicit product decision: attendance lives in the external SangoeTrack
                app, never in HRMS. This is only an integration placeholder. */}
            <IntegrationNote icon={Plug} title="Attendance" subtitle="Coming from SangoeTrack"
              hint="(Not available until integration) — This employee's attendance will be displayed here once SangoeTrack integration is completed." big />
            <AiInsight hint="Reserved for SangoeTrack integration — will summarise attendance trends once connected." />
          </div>
        )}

        {tab==='leave' && (
          <div>
            <IntegrationNote icon={CalendarDays} title="Leave" subtitle="Future integration"
              hint="Leave balances, requests and approvals will appear here once the Leave module is available." chips={['Casual','Sick','Earned','LOP','Maternity','Paternity']} />
            <AiInsight hint="Will surface leave patterns and balance risks once the Leave module is connected." />
          </div>
        )}

        {tab==='performance' && (
          <div>
            <IntegrationNote icon={Target} title="Performance" subtitle="Future integration"
              hint="KRA, KPI, DPR and MPR with acceptance signatures will plug in here. No performance logic is implemented yet." chips={['KRA','KPI','DPR','MPR']} />
            <AiInsight hint="Will generate a performance summary, target-achievement and risk signals once PMS is live." />
          </div>
        )}

        {tab==='training' && (
          <div>
            <IntegrationNote icon={GraduationCap} title="Training" subtitle="Future Learning & Development integration"
              hint="Assigned, completed and pending trainings plus certificates will appear here once L&D is available." chips={['Assigned','Completed','Pending','Certificates']} />
            <AiInsight hint="Will recommend training and retraining based on role, skill gaps and history." />
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
const QuickCard = ({ label, value, color, note, bar }) => (
  <div className="rounded-xl px-3 py-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
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
  const save = async () => {
    setSaving(true)
    try { await hrApi.employees.update(employee.id, form); showToast('Employee updated'); onSaved() }
    catch (err) { showToast(err.response?.data?.message||'Failed to update','error'); setSaving(false) }
  }
  const set = (k,v) => setForm(f=>({...f,[k]:v}))
  return (
    <div className="modal-backdrop" onClick={onClose}>
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
                <option value="">Select...</option>{['Engineering','Sales','HR','Operations','Finance','Product','Marketing'].map(d=><option key={d}>{d}</option>)}
              </select>
            </div>
            <div><label className="label">Designation</label><input className="input-3d text-sm" value={form.designation} onChange={e=>set('designation',e.target.value)}/></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Reporting Manager</label><input className="input-3d text-sm" value={form.reporting_manager_name||''} onChange={e=>set('reporting_manager_name',e.target.value)}/></div>
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
