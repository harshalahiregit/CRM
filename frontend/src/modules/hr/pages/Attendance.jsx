import { useState, useEffect, useMemo, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import {
  Search, Plus, X, LogIn, LogOut, Coffee, Pencil, Download, Printer, CalendarCheck,
  Users, UserCheck, UserX, Clock, Hourglass, Plane, Home, Sun, CalendarDays, Percent, Timer, MapPin } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { hrTime } from '../constants'

const STATUSES = ['Present', 'Absent', 'Late', 'Half Day', 'Leave', 'Holiday', 'Weekend', 'Work From Home', 'Remote']
const SHIFTS = ['General', 'Morning', 'Evening', 'Night', 'Custom']
const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const deptColor = d => DEPT_COLORS[d] || '#7C3AED'
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()

export const ST_COLOR = s => ({
  Present:'#10b981', Absent:'#ef4444', Late:'#f59e0b', 'Half Day':'#f97316', Leave:'#3b82f6',
  Holiday:'#94a3b8', Weekend:'#94a3b8', 'Work From Home':'#8b5cf6', Remote:'#14b8a6',
}[s] || '#94a3b8')
const stStyle = s => { const c = ST_COLOR(s); return { c, bg:`${c}1f` } }
/*
 | The Register was the screen that always converted, so the others were fixed to
 | agree with it. It now defers to the same shared helper instead of carrying its
 | own copy: it was the last place hardcoding a 24-hour clock and the browser's
 | timezone, which would have left it reading 14:30 while every other attendance
 | screen read 02:30 pm for the same punch.
 */
const fmtT = hrTime
const today = () => new Date().toISOString().slice(0,10)

/**
 * Where a punch happened.
 *
 * The coordinates are the record; the address is shown when a caller supplied
 * one. Links out to a map rather than embedding one — this is a dense register,
 * and the question being asked of it is "was this the site?", which one click
 * answers without turning every row into a map tile.
 */
function PunchLocation({ lat, lng, address, verification }) {
  if (!lat || !lng) {
    // A dash says nothing. When the punch recorded WHY there are no
    // coordinates, show that instead — "declined" is worth asking about and an
    // office desktop with no GPS is not.
    const why = (verification || '').replace(/^(Verified|Unverified)\s*—\s*/, '')
    return (
      <span className="text-[11px]" style={{ color: 'var(--text-muted)' }} title={verification || ''}>
        {why ? why.slice(0, 26) : '—'}
      </span>
    )
  }

  return (
    <a href={`https://www.google.com/maps?q=${lat},${lng}`} target="_blank" rel="noreferrer"
      className="inline-flex items-center gap-1 text-[11px] underline whitespace-nowrap"
      style={{ color: '#a78bfa' }}
      title={address || `${lat}, ${lng}`}>
      <MapPin size={12} />
      {address ? address.slice(0, 22) : `${Number(lat).toFixed(4)}, ${Number(lng).toFixed(4)}`}
    </a>
  )
}

/**
 * The photo taken at the punch.
 *
 * A thumbnail that opens full size. The URL is signed and expires within the
 * hour, so it is fetched with the row rather than held — a page left open all
 * afternoon will need a refresh to view one again, which is the intended
 * trade for not minting permanent public links to photographs of people.
 */
function PunchSelfie({ url, who, when }) {
  const [open, setOpen] = useState(false)
  if (!url) return <span style={{ color: 'var(--text-muted)' }}>—</span>

  return (
    <>
      <button onClick={() => setOpen(true)} title={`${who} at ${when}`} className="block">
        <img src={url} alt={`${who} at ${when}`} loading="lazy"
          className="w-9 h-9 rounded-lg object-cover"
          style={{ border: '1px solid var(--border)' }} />
      </button>
      {open && (
        <div className="drawer-backdrop" onClick={() => setOpen(false)}>
          <div className="card-3d" onClick={e => e.stopPropagation()}
            style={{ maxWidth: 420, margin: '10vh auto', padding: 16 }}>
            <p className="text-xs font-bold mb-2" style={{ color: 'var(--text-h)' }}>{who} — {when}</p>
            <img src={url} alt={`${who} at ${when}`} className="w-full rounded-xl" />
          </div>
        </div>
      )}
    </>
  )
}

export default function Attendance() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState(null)
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)

  // Filters
  const [date, setDate] = useState(today())
  const [deptF, setDeptF] = useState('All')
  const [desigF, setDesigF] = useState('All')
  const [statusF, setStatusF] = useState('All')
  const [shiftF, setShiftF] = useState('All')
  const [search, setSearch] = useState('')

  const [modal, setModal] = useState(null)   // { mode:'manual'|'correct', record? }

  const showToast = (m, type='success') => { setToast({m,type}); setTimeout(()=>setToast(null), 2600) }

  const params = useMemo(() => {
    const p = { date }
    if (deptF!=='All') p.department = deptF
    if (desigF!=='All') p.designation = desigF
    if (statusF!=='All') p.status = statusF
    if (shiftF!=='All') p.shift = shiftF
    if (search) p.search = search
    return p
  }, [date, deptF, desigF, statusF, shiftF, search])

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [list, st] = await Promise.all([hrApi.attendance.list(params), hrApi.attendance.stats({ date })])
      setRows(list); setStats(st)
    } catch { showToast('Failed to load attendance', 'error') }
    finally { setLoading(false) }
  }, [params, date])
  useEffect(()=>{ fetchData() }, [fetchData])
  useEffect(()=>{ hrApi.employees.list({ status: 'Active', per_page: 200 }).then(setEmployees).catch(()=>{}) }, [])

  const departments = useMemo(()=>['All', ...new Set(employees.map(e=>e.department).filter(Boolean))], [employees])
  const designations = useMemo(()=>['All', ...new Set(employees.map(e=>e.designation).filter(Boolean))], [employees])

  const op = async (fn, employee_id) => {
    try { await fn({ employee_id, date }); showToast('Attendance updated'); fetchData() }
    catch (e) { showToast(e.response?.data?.message || 'Action failed', 'error') }
  }

  const exportCsv = async () => {
    try {
      const blob = await hrApi.attendance.exportBlob(params)
      const url = URL.createObjectURL(blob); const a = document.createElement('a')
      a.href = url; a.download = `attendance-${date}.csv`; a.click(); setTimeout(()=>URL.revokeObjectURL(url), 1500)
    } catch { showToast('Export failed', 'error') }
  }

  const CARDS = stats ? [
    { l:'Total Employees',  v:stats.total_employees, c:'#7C3AED', I:Users },
    { l:'Present Today',    v:stats.present,         c:'#10b981', I:UserCheck },
    { l:'Absent',           v:stats.absent,          c:'#ef4444', I:UserX },
    { l:'Late',             v:stats.late,            c:'#f59e0b', I:Clock },
    { l:'Half Day',         v:stats.half_day,        c:'#f97316', I:Hourglass },
    { l:'On Leave',         v:stats.on_leave,        c:'#3b82f6', I:Plane },
    { l:'Remote / WFH',     v:stats.remote,          c:'#14b8a6', I:Home },
    { l:'Holiday',          v:stats.holiday,         c:'#94a3b8', I:Sun },
    { l:'Weekend',          v:stats.weekend,         c:'#94a3b8', I:CalendarDays },
    { l:'Avg Work Hours',   v:stats.avg_working_hours, c:'#a78bfa', I:Timer },
    { l:'Attendance %',     v:`${stats.attendance_pct}%`, c:'#10b981', I:Percent },
  ] : []

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      <style>{`@media print { .no-print{display:none!important} .card-3d{box-shadow:none!important;border:1px solid #ddd!important} }`}</style>
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl no-print" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.m}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3 no-print">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Attendance <span className="text-gradient">Management</span></h1></div>
        <div className="flex items-center gap-2">
          <button onClick={exportCsv} className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Download size={13}/> CSV</button>
          <button onClick={()=>window.print()} className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Printer size={13}/> Print</button>
          <button onClick={()=>setModal({ mode:'manual' })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Mark Attendance</button>
        </div>
      </div>

      {/* Dashboard cards */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
        {CARDS.map(k=>(
          <div key={k.l} className="kpi-3d" style={{ padding:16 }}>
            <div className="flex items-center gap-2 mb-2"><div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background:`${k.c}20` }}><k.I size={14} style={{ color:k.c }}/></div></div>
            <p className="text-2xl font-black" style={{ color:k.c }}>{k.v ?? 0}</p>
            <p className="text-[11px] font-semibold mt-0.5" style={{ color:'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d no-print" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[150px]"><label className="label">Date</label><input type="date" className="input-3d text-sm" value={date} onChange={e=>setDate(e.target.value)}/></div>
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Name, Employee ID, dept…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}>{departments.map(d=><option key={d}>{d}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Designation</label><select className="input-3d text-sm" value={desigF} onChange={e=>setDesigF(e.target.value)}>{designations.map(d=><option key={d}>{d}</option>)}</select></div>
          <div className="min-w-[120px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All',...STATUSES].map(s=><option key={s}>{s}</option>)}</select></div>
          <div className="min-w-[110px]"><label className="label">Shift</label><select className="input-3d text-sm" value={shiftF} onChange={e=>setShiftF(e.target.value)}>{['All',...SHIFTS].map(s=><option key={s}>{s}</option>)}</select></div>
        </div>
      </div>

      {/* Attendance table */}
      <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
        <table className="w-full text-sm" style={{ minWidth:1050 }}>
          <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Emp ID','Department','Designation','Shift','Check In','In Location','In Selfie','Check Out','Out Location','Out Selfie','IP','Break','Hours','OT','Status','Actions'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
          <tbody>
            {loading ? <tr><td colSpan="17" className="text-center py-10" style={{ color:'var(--text-muted)' }}>Loading…</td></tr>
              : rows.length===0 ? <tr><td colSpan="17" className="text-center py-10" style={{ color:'var(--text-muted)' }}>No attendance records for this date. Use “Mark Attendance”.</td></tr>
              : rows.map(r=>{
              const emp = r.employee || {}; const ss = stStyle(r.status)
              const brk = r.break_start && r.break_end ? `${fmtT(r.break_start)}–${fmtT(r.break_end)}` : r.break_start ? `${fmtT(r.break_start)}…` : '—'
              return (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }} onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                  <td className="px-3 py-2.5"><div className="flex items-center gap-2.5 cursor-pointer" onClick={()=>navigate(`/app/hr/employees/${emp.id}`)}><div className="w-8 h-8 rounded-xl flex items-center justify-center text-[10px] font-black text-white" style={{ background:`linear-gradient(145deg,${deptColor(emp.department)}cc,${deptColor(emp.department)})` }}>{initials(emp.name)}</div><span className="font-semibold whitespace-nowrap" style={{ color:'var(--text-h)' }}>{emp.name}</span></div></td>
                  <td className="px-3 py-2.5 font-mono font-bold whitespace-nowrap" style={{ color:'#a78bfa' }}>{emp.employee_code}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${deptColor(emp.department)}18`, color:deptColor(emp.department) }}>{emp.department||'—'}</span></td>
                  <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{emp.designation||'—'}</td>
                  <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{r.shift}</td>
                  <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-h)' }}>{fmtT(r.check_in)}</td>
                  <td className="px-3 py-2.5"><PunchLocation lat={r.check_in_latitude} lng={r.check_in_longitude} address={r.check_in_address} verification={r.check_in_verification}/></td>
                  <td className="px-3 py-2.5"><PunchSelfie url={r.check_in_selfie_url} who={emp.name} when="clock-in"/></td>
                  <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-h)' }}>{fmtT(r.check_out)}</td>
                  <td className="px-3 py-2.5"><PunchLocation lat={r.check_out_latitude} lng={r.check_out_longitude} address={r.check_out_address} verification={r.check_out_verification}/></td>
                  <td className="px-3 py-2.5"><PunchSelfie url={r.check_out_selfie_url} who={emp.name} when="clock-out"/></td>
                  <td className="px-3 py-2.5 whitespace-nowrap font-mono text-[10px]" style={{ color:'var(--text-muted)' }}>{r.check_in_ip || r.check_out_ip || '—'}</td>
                  <td className="px-3 py-2.5 whitespace-nowrap text-xs" style={{ color:'var(--text-muted)' }}>{brk}</td>
                  <td className="px-3 py-2.5 font-bold whitespace-nowrap" style={{ color:'var(--text-h)' }}>{r.working_hours ?? '—'}</td>
                  <td className="px-3 py-2.5 font-bold whitespace-nowrap" style={{ color: r.overtime_hours>0?'#10b981':'var(--text-muted)' }}>{r.overtime_hours>0?r.overtime_hours:'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap" style={{ background:ss.bg, color:ss.c }}>{r.status}</span></td>
                  <td className="px-3 py-2.5 no-print">
                    <div className="flex gap-1">
                      {!r.check_in && <button onClick={()=>op(hrApi.attendance.checkIn, emp.id)} title="Check In" className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}><LogIn size={13}/></button>}
                      {r.check_in && !r.check_out && !r.break_start && <button onClick={()=>op(hrApi.attendance.breakStart, emp.id)} title="Break Start" className="p-1.5 rounded-lg" style={{ background:'rgba(245,158,11,0.12)', color:'#f59e0b' }}><Coffee size={13}/></button>}
                      {r.break_start && !r.break_end && <button onClick={()=>op(hrApi.attendance.breakEnd, emp.id)} title="Break End" className="p-1.5 rounded-lg" style={{ background:'rgba(245,158,11,0.18)', color:'#d97706' }}><Coffee size={13}/></button>}
                      {r.check_in && !r.check_out && <button onClick={()=>op(hrApi.attendance.checkOut, emp.id)} title="Check Out" className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><LogOut size={13}/></button>}
                      <button onClick={()=>setModal({ mode:'correct', record:r })} title="Correct / Remarks" className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Pencil size={13}/></button>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {modal && <AttendanceModal modal={modal} date={date} employees={employees} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); fetchData() }} showToast={showToast} />}
    </div>
  )
}

// ── Manual entry / correction modal ──
function AttendanceModal({ modal, date, employees, onClose, onSaved, showToast }) {
  const rec = modal.record
  const isCorrect = modal.mode === 'correct'
  const toLocal = t => t ? new Date(t).toISOString().slice(0,16) : ''
  const [form, setForm] = useState({
    employee_id: rec?.employee_id ? String(rec.employee_id) : '',
    date: rec?.date ? String(rec.date).slice(0,10) : date,
    status: rec?.status || 'Present',
    shift: rec?.shift || 'General',
    check_in: toLocal(rec?.check_in), check_out: toLocal(rec?.check_out),
    break_start: toLocal(rec?.break_start), break_end: toLocal(rec?.break_end),
    remarks: rec?.remarks || '',
  })
  const [saving, setSaving] = useState(false)
  const set = (k,v) => setForm(f=>({...f,[k]:v}))

  const save = async () => {
    if (!isCorrect && !form.employee_id) return showToast('Select an employee', 'error')
    setSaving(true)
    const payload = { ...form }
    Object.keys(payload).forEach(k => { if (payload[k]==='') delete payload[k] })
    try {
      if (isCorrect) await hrApi.attendance.correct(rec.id, payload)
      else await hrApi.attendance.manual({ ...payload, employee_id: Number(form.employee_id) })
      showToast(isCorrect ? 'Attendance corrected' : 'Attendance marked')
      onSaved()
    } catch (e) { showToast(e.response?.data?.message || 'Save failed', 'error'); setSaving(false) }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}><CalendarCheck size={18} style={{ color:'#a78bfa' }}/> {isCorrect ? 'Attendance Correction' : 'Mark Attendance'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
        <div className="space-y-3">
          {!isCorrect && (
            <div><label className="label">Employee *</label>
              <select className="input-3d text-sm" value={form.employee_id} onChange={e=>set('employee_id', e.target.value)}>
                <option value="">Select employee…</option>
                {employees.map(e=><option key={e.id} value={e.id}>{e.employee_code} · {e.name}</option>)}
              </select>
            </div>
          )}
          {isCorrect && <p className="text-xs" style={{ color:'var(--text-muted)' }}>{rec.employee?.name} · {rec.employee?.employee_code}</p>}
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Date *</label><input type="date" disabled={isCorrect} className="input-3d text-sm" value={form.date} onChange={e=>set('date', e.target.value)}/></div>
            <div><label className="label">Status *</label><select className="input-3d text-sm" value={form.status} onChange={e=>set('status', e.target.value)}>{STATUSES.map(s=><option key={s}>{s}</option>)}</select></div>
          </div>
          <div><label className="label">Shift</label><select className="input-3d text-sm" value={form.shift} onChange={e=>set('shift', e.target.value)}>{SHIFTS.map(s=><option key={s}>{s}</option>)}</select></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Check In</label><input type="datetime-local" className="input-3d text-sm" value={form.check_in} onChange={e=>set('check_in', e.target.value)}/></div>
            <div><label className="label">Check Out</label><input type="datetime-local" className="input-3d text-sm" value={form.check_out} onChange={e=>set('check_out', e.target.value)}/></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Break Start</label><input type="datetime-local" className="input-3d text-sm" value={form.break_start} onChange={e=>set('break_start', e.target.value)}/></div>
            <div><label className="label">Break End</label><input type="datetime-local" className="input-3d text-sm" value={form.break_end} onChange={e=>set('break_end', e.target.value)}/></div>
          </div>
          <div><label className="label">Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.remarks} onChange={e=>set('remarks', e.target.value)} placeholder="Optional note (shown in audit trail)"/></div>
          <div className="flex gap-3 pt-1">
            <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':isCorrect?'Save Correction':'Mark Attendance'}</button>
          </div>
        </div>
      </div>
    </div>
  )
}
