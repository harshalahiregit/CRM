import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Pencil, UserX, Download, Printer, X, FileText, Eye, CheckCircle2, XCircle, Clock,
  LayoutDashboard, User, Phone, Briefcase, FileCheck, Landmark, History, CalendarCheck, ChevronLeft, ChevronRight, LogOut,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { offerPortalApi } from '@/services/offerPortalApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { ST_COLOR } from './Attendance'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const deptColor = d => DEPT_COLORS[d]||'#7C3AED'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const DOC_ST = s => s==='Verified'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Rejected'?{c:'#f87171',bg:'rgba(239,68,68,0.1)'}:{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}

// Canonical document list for the Documents tab, mapped onto stored onboarding types.
const DOC_LIST = [
  { key:'aadhaar', label:'Aadhaar' },
  { key:'pan', label:'PAN' },
  { key:'resume', label:'Resume' },
  { key:'educational_certificate', label:'Educational Certificates' },
  { key:'experience_document', label:'Experience Documents' },
  { key:'offer_letter', label:'Offer Letter', virtual:true },
  { key:'appointment_letter', label:'Appointment Letter', virtual:true },
  { key:'nda', label:'NDA', virtual:true },
]

const TABS = [
  { key:'overview',  label:'Overview',        icon:LayoutDashboard },
  { key:'personal',  label:'Personal Details', icon:User },
  { key:'contact',   label:'Contact',          icon:Phone },
  { key:'employment',label:'Employment',       icon:Briefcase },
  { key:'documents', label:'Documents',        icon:FileCheck },
  { key:'bank',      label:'Bank Details',     icon:Landmark },
  { key:'attendance',label:'Attendance',       icon:CalendarCheck },
  { key:'timeline',  label:'Timeline',         icon:History },
]

export default function EmployeeProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [data, setData]     = useState(null)
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

  if (loading) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Loading profile…</div>
  if (notFound || !data) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Employee not found.</div>

  const e = data.employee
  const ss = STATUS_S(e.status)
  const dc = deptColor(e.department)
  const docByType = Object.fromEntries((data.documents||[]).map(d=>[d.type,d]))

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
            {/* Exit Interview (SPK-1) — internal form, prefilled from this record */}
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
        const pct = Number(e.onboarding_progress || 0)
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
                      {String(st).replace('_',' ')} {pct ? `(${pct}%)` : ''}
                    </span>
                    <div className="mt-2 rounded-full" style={{ height:6, background:'var(--bg-input)' }}>
                      <div className="h-full rounded-full transition-all" style={{ width:`${pct}%`, background:o.c }}/>
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
          <Grid>
            <Field k="Employee ID" v={e.employee_code} mono/>
            <Field k="Status" v={e.status}/>
            <Field k="Employment Type" v={data.offer ? 'Full-time' : '—'}/>
            <Field k="Department" v={e.department}/>
            <Field k="Designation" v={e.designation}/>
            <Field k="Joining Date" v={fmtDate(e.joining_date)}/>
            <Field k="Confirmation Date" v={fmtDate(e.confirmation_date)}/>
            <Field k="Reporting Manager" v={e.reporting_manager_name}/>
            <Field k="Applied For" v={data.recruitment?.applied_job}/>
            <Field k="Source" v={data.recruitment?.source}/>
            <Field k="Candidate Ref" v={data.recruitment?.reference} mono/>
            <Field k="Offer" v={data.offer ? `${data.offer.reference} · ${data.offer.status}` : '—'}/>
          </Grid>
        )}

        {tab==='personal' && (
          <Grid>
            <Field k="Date of Birth" v={fmtDate(e.dob) !== '—' ? fmtDate(e.dob) : fmtDate(data.submission?.personal?.dob)}/>
            <Field k="Gender" v={e.gender || data.submission?.personal?.gender}/>
            <Field k="Blood Group" v={data.submission?.personal?.blood_group}/>
            <Field k="Marital Status" v={data.submission?.personal?.marital_status}/>
            <Field k="Nationality" v={data.recruitment?.nationality}/>
            <Field k="Father / Guardian" v={data.submission?.personal?.father_name}/>
          </Grid>
        )}

        {tab==='contact' && (
          <Grid>
            <Field k="Mobile" v={e.phone}/>
            <Field k="Email" v={e.email}/>
            <Field k="Present Address" v={data.submission?.address?.current || e.address} full/>
            <Field k="Permanent Address" v={data.submission?.address?.permanent} full/>
            <Field k="City / State" v={[data.submission?.address?.city, data.submission?.address?.state].filter(Boolean).join(', ')}/>
            <Field k="Pincode" v={data.submission?.address?.pincode}/>
            <Field k="Emergency Contact" v={data.submission?.emergency?.name ? `${data.submission.emergency.name}${data.submission.emergency.relation?` (${data.submission.emergency.relation})`:''}${data.submission.emergency.phone?` · ${data.submission.emergency.phone}`:''}` : '—'} full/>
          </Grid>
        )}

        {tab==='employment' && (
          <Grid>
            <Field k="Company" v="—"/>
            <Field k="Branch" v="—"/>
            <Field k="Department" v={e.department}/>
            <Field k="Designation" v={e.designation}/>
            <Field k="Shift" v="—"/>
            <Field k="Reporting Manager" v={e.reporting_manager_name}/>
            <Field k="Probation" v={fmtDate(e.probation_end_date)!=='—' ? `Until ${fmtDate(e.probation_end_date)}` : (data.offer?.probation_period||'—')}/>
            <Field k="Notice Period" v={data.offer?.notice_period}/>
            <Field k="Work Location" v="—"/>
            <Field k="Offered CTC" v={data.offer?.offered_ctc ? `₹${Number(data.offer.offered_ctc).toLocaleString('en-IN')}` : '—'}/>
          </Grid>
        )}

        {tab==='documents' && (
          <div className="space-y-2">
            <p className="text-xs mb-2" style={{ color:'var(--text-muted)' }}>Documents are reused from onboarding verification — read-only here.</p>
            {DOC_LIST.map(d=>{
              const doc = docByType[d.key]
              const isOffer = d.key==='offer_letter'
              const available = !!doc || (isOffer && offerLetterUrl)
              const st = doc ? DOC_ST(doc.status) : null
              return (
                <div key={d.key} className="flex items-center gap-2 px-3 py-2.5 rounded-xl flex-wrap" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <FileText size={13} style={{ color:'#a78bfa' }}/>
                  <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{d.label}</span>
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
          </div>
        )}

        {tab==='bank' && (
          <Grid>
            <Field k="Account Holder" v={data.submission?.bank?.account_name}/>
            <Field k="Bank" v={data.submission?.bank?.bank_name}/>
            <Field k="Account Number" v={data.submission?.bank?.account_number} mono/>
            <Field k="IFSC" v={data.submission?.bank?.ifsc} mono/>
            <Field k="Branch" v={data.submission?.bank?.branch}/>
          </Grid>
        )}

        {tab==='attendance' && <AttendanceTab employeeId={id} />}

        {tab==='timeline' && (
          <div>
            <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>Full lifecycle — reused from audit logs (Applied → Interview → Offer → Joining → Employee, and every subsequent change).</p>
            <AuditTimeline entries={data.timeline} />
          </div>
        )}
      </div>

      {editing && <EditModal employee={e} onClose={()=>setEditing(false)} onSaved={()=>{ setEditing(false); load() }} showToast={showToast} />}
    </div>
  )
}

// ── Attendance tab: summary + monthly calendar + day details ──
const monthKey = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`
function AttendanceTab({ employeeId }) {
  const [month, setMonth] = useState(monthKey(new Date()))
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [day, setDay] = useState(null)

  useEffect(() => {
    let live = true; setLoading(true)
    hrApi.employees.attendance(employeeId, { month })
      .then(d => { if (live) setData(d) })
      .catch(() => {})
      .finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [employeeId, month])

  const shiftMonth = (delta) => {
    const [y, m] = month.split('-').map(Number)
    setMonth(monthKey(new Date(y, m - 1 + delta, 1)))
  }

  if (loading || !data) return <p className="text-sm py-6" style={{ color:'var(--text-muted)' }}>Loading attendance…</p>

  const byDate = Object.fromEntries((data.calendar||[]).map(d => [d.date, d]))
  const [y, m] = month.split('-').map(Number)
  const firstDow = new Date(y, m - 1, 1).getDay()
  const daysInMonth = new Date(y, m, 0).getDate()
  const cells = [...Array(firstDow).fill(null), ...Array.from({length:daysInMonth}, (_, i) => i + 1)]

  const SUM = [
    { l:'Attendance %', v:`${data.attendance_pct}%`, c:'#10b981' },
    { l:'Present',      v:data.present_count,  c:'#10b981' },
    { l:'Late',         v:data.late_count,     c:'#f59e0b' },
    { l:'Absent',       v:data.absent_count,   c:'#ef4444' },
    { l:'Leave',        v:data.leave_count,    c:'#3b82f6' },
    { l:'Overtime',     v:`${data.overtime_hours}h`, c:'#a78bfa' },
  ]

  return (
    <div className="space-y-4">
      {/* Today's attendance */}
      <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
        <p className="text-[11px] font-bold mb-1" style={{ color:'var(--text-h)' }}>Today’s Attendance</p>
        {data.today ? (
          <div className="flex items-center gap-3 flex-wrap text-xs" style={{ color:'var(--text-muted)' }}>
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${ST_COLOR(data.today.status)}1f`, color:ST_COLOR(data.today.status) }}>{data.today.status}</span>
            <span>In: <b style={{ color:'var(--text-h)' }}>{data.today.check_in||'—'}</b></span>
            <span>Out: <b style={{ color:'var(--text-h)' }}>{data.today.check_out||'—'}</b></span>
            <span>Hours: <b style={{ color:'var(--text-h)' }}>{data.today.working_hours ?? '—'}</b></span>
            <span>OT: <b style={{ color:'var(--text-h)' }}>{data.today.overtime_hours ?? '—'}</b></span>
          </div>
        ) : <p className="text-xs" style={{ color:'var(--text-muted)' }}>No attendance marked today.</p>}
      </div>

      {/* Monthly summary */}
      <div className="grid grid-cols-3 md:grid-cols-6 gap-2">
        {SUM.map(s=>(
          <div key={s.l} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
            <p className="text-lg font-black" style={{ color:s.c }}>{s.v}</p>
            <p className="text-[10px] font-semibold" style={{ color:'var(--text-muted)' }}>{s.l}</p>
          </div>
        ))}
      </div>

      {/* Calendar */}
      <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-3">
          <button onClick={()=>shiftMonth(-1)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><ChevronLeft size={14}/></button>
          <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{data.month_label}</p>
          <button onClick={()=>shiftMonth(1)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><ChevronRight size={14}/></button>
        </div>
        <div className="grid grid-cols-7 gap-1.5">
          {['Su','Mo','Tu','We','Th','Fr','Sa'].map(d=><div key={d} className="text-center text-[10px] font-bold py-1" style={{ color:'var(--text-muted)' }}>{d}</div>)}
          {cells.map((n, i) => {
            if (!n) return <div key={`b${i}`} />
            const dateStr = `${month}-${String(n).padStart(2,'0')}`
            const rec = byDate[dateStr]
            const col = rec ? ST_COLOR(rec.status) : null
            return (
              <button key={dateStr} onClick={()=>rec && setDay(rec)} disabled={!rec}
                className="aspect-square rounded-lg flex flex-col items-center justify-center text-xs font-bold transition-all"
                style={{ background: rec ? `${col}22` : 'var(--bg-card)', color: rec ? col : 'var(--text-muted)', border:`1px solid ${rec ? col+'55' : 'var(--border)'}`, cursor: rec ? 'pointer' : 'default' }}>
                {n}
                {rec && <span className="w-1.5 h-1.5 rounded-full mt-0.5" style={{ background:col }} />}
              </button>
            )
          })}
        </div>
        {/* Legend */}
        <div className="flex flex-wrap gap-3 mt-3">
          {[['Present','#10b981'],['Absent','#ef4444'],['Late','#f59e0b'],['Leave','#3b82f6'],['Holiday','#94a3b8']].map(([l,c])=>(
            <div key={l} className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:c }}/><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{l}</span></div>
          ))}
        </div>
      </div>

      {/* Day details modal */}
      {day && (
        <div className="modal-backdrop" onClick={()=>setDay(null)}>
          <div className="modal-box max-w-sm" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4"><h2 className="font-black text-base" style={{ color:'var(--text-h)' }}>{day.date}</h2><button onClick={()=>setDay(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <span className="text-[11px] font-bold px-2.5 py-1 rounded-xl" style={{ background:`${ST_COLOR(day.status)}1f`, color:ST_COLOR(day.status) }}>{day.status}</span>
            <div className="grid grid-cols-2 gap-2 mt-3">
              {[['Check In',day.check_in],['Check Out',day.check_out],['Break Start',day.break_start],['Break End',day.break_end],['Working Hours',day.working_hours],['Overtime',day.overtime_hours],['Shift',day.shift]].map(([k,v])=>(
                <div key={k} className="px-2.5 py-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}><p className="text-[9px]" style={{ color:'var(--text-muted)' }}>{k}</p><p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{v ?? '—'}</p></div>
              ))}
            </div>
            {day.remarks && <div className="mt-2 px-2.5 py-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}><p className="text-[9px]" style={{ color:'var(--text-muted)' }}>Remarks</p><p className="text-xs" style={{ color:'var(--text-h)' }}>{day.remarks}</p></div>}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Edit modal ──
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
