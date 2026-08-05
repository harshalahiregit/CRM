import { useState, useEffect, useCallback } from 'react'
import { CalendarDays, RefreshCw, Loader2, Info, ChevronLeft, ChevronRight } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

/**
 * Review comment #38 — the SangoeTrack half of "Employee loan, advance, and
 * sangoe track integration."
 *
 * What stood here before was static JSX reading "(Not available until
 * integration)". That was already untrue — SangoeTrackClient, AttendanceSyncService
 * and four scheduled sync commands exist — and it would have stayed on screen even
 * with the integration switched on and days already synced into hr_attendance,
 * because nothing on the tab ever fetched anything.
 *
 * This only wires the existing endpoints: GET /hr/employees/{id}/attendance for
 * the month, and POST /hr/attendance/sync-sangoetrack for the on-demand pull that
 * SangoeTrackSyncController was already written to serve (it accepts employee_id,
 * month and year for exactly this button). No new attendance service.
 */

const STATUS_C = {
  Present:  '#10b981', Late: '#f59e0b', 'Half Day': '#fbbf24',
  Absent:   '#f87171', Leave: '#60a5fa', Weekend: '#94a3b8', Holiday: '#a78bfa',
}

const monthInput = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`

export default function EmployeeAttendancePanel({ employeeId, canManage }) {
  const [month, setMonth]     = useState(() => monthInput(new Date()))
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [syncing, setSyncing] = useState(false)
  const [notice, setNotice]   = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setData(await hrApi.employees.attendance(employeeId, { month })); setError(null) }
    catch (e) {
      setError(e?.response?.status === 403
        ? 'You are not authorised to view attendance.'
        : (e?.response?.data?.message || 'Could not load attendance'))
    }
    finally { setLoading(false) }
  }, [employeeId, month])

  useEffect(() => { load() }, [load])

  const sync = async () => {
    setSyncing(true); setNotice(null)
    const [y, m] = month.split('-')
    try {
      const r = await hrApi.attendance.syncSangoeTrack({ employee_id: employeeId, month: Number(m), year: Number(y) })
      setNotice({ tone: 'ok', text: r?.message || 'Sync complete.' })
      await load()
    } catch (e) {
      // 422 = integration switched off; 502 = SangoeTrack itself failed. Both are
      // real answers and neither is the user's mistake, so say which it was.
      setNotice({ tone: 'warn', text: e?.response?.data?.message || 'Could not reach SangoeTrack.' })
    }
    finally { setSyncing(false) }
  }

  const shiftMonth = (delta) => {
    const [y, m] = month.split('-').map(Number)
    setMonth(monthInput(new Date(y, m - 1 + delta, 1)))
  }

  const K = data ? [
    { l: 'Present',  v: data.present_count,  c: STATUS_C.Present },
    { l: 'Late',     v: data.late_count,     c: STATUS_C.Late },
    { l: 'Absent',   v: data.absent_count,   c: STATUS_C.Absent },
    { l: 'Leave',    v: data.leave_count,    c: STATUS_C.Leave },
    { l: 'Half Day', v: data.half_day_count, c: STATUS_C['Half Day'] },
  ] : []

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 flex-wrap">
        <p className="text-[11px] font-bold uppercase flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
          <CalendarDays size={12}/> Attendance
        </p>
        <div className="flex items-center gap-1 ml-auto">
          <button onClick={()=>shiftMonth(-1)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><ChevronLeft size={13}/></button>
          <input type="month" value={month} onChange={e=>setMonth(e.target.value)} className="input-3d text-xs" style={{ width:150 }}/>
          <button onClick={()=>shiftMonth(1)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><ChevronRight size={13}/></button>
          {canManage && (
            <button onClick={sync} disabled={syncing}
              title="Pull this month again from SangoeTrack"
              className="ml-1 px-3 py-1.5 rounded-lg text-[11px] font-bold inline-flex items-center gap-1.5"
              style={{ background:'rgba(124,58,237,0.12)', border:'1px solid rgba(124,58,237,0.35)', color:'#a78bfa', opacity:syncing?0.7:1 }}>
              {syncing ? <Loader2 size={11} className="animate-spin"/> : <RefreshCw size={11}/>} Sync
            </button>
          )}
        </div>
      </div>

      {notice && (
        <div className="rounded-xl p-2.5 flex items-start gap-2"
          style={notice.tone === 'ok'
            ? { background:'rgba(16,185,129,0.08)', border:'1px solid rgba(16,185,129,0.25)' }
            : { background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
          <Info size={13} style={{ color: notice.tone === 'ok' ? '#10b981' : '#fbbf24', flexShrink:0, marginTop:1 }}/>
          <p className="text-[11px]" style={{ color: notice.tone === 'ok' ? '#10b981' : '#fbbf24' }}>{notice.text}</p>
        </div>
      )}

      {loading ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>Loading attendance…</p>
        : error ? <p className="text-xs" style={{ color:'#f87171' }}>{error}</p>
        : (
          <>
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
              {K.map(k => (
                <div key={k.l} className="kpi-3d">
                  <p className="text-3xl font-black" style={{ color:k.c }}>{k.v ?? 0}</p>
                  <p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p>
                </div>
              ))}
            </div>

            <div className="grid grid-cols-2 md:grid-cols-3 gap-2.5">
              {[['Attendance %', `${data.attendance_pct ?? 0}%`], ['Working Hours', data.working_hours ?? 0], ['Overtime Hours', data.overtime_hours ?? 0]].map(([l, v]) => (
                <div key={l} className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                  <p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{l}</p>
                  <p className="text-sm font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{v}</p>
                </div>
              ))}
            </div>

            {(data.calendar || []).length === 0 ? (
              <div className="rounded-xl p-3 flex items-start gap-2" style={{ background:'var(--bg-input)' }}>
                <Info size={13} style={{ color:'var(--text-muted)', flexShrink:0, marginTop:1 }}/>
                <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                  No attendance recorded for {data.month_label}. Attendance is captured in SangoeTrack and synced here
                  {canManage ? ' — use Sync to pull this month now.' : '.'}
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                <table className="w-full text-sm" style={{ minWidth:620 }}>
                  <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                    {['Date','Status','Shift','In','Out','Hours','OT'].map(h=>(
                      <th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>
                    ))}
                  </tr></thead>
                  <tbody>
                    {data.calendar.map(d => (
                      <tr key={d.id ?? d.date} style={{ borderBottom:'1px solid var(--border)' }}>
                        <td className="px-3 py-2" style={{ color:'var(--text-h)' }}>{d.date}</td>
                        <td className="px-3 py-2">
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                            style={{ background:`${STATUS_C[d.status] || '#94a3b8'}1f`, color: STATUS_C[d.status] || '#94a3b8' }}>{d.status}</span>
                        </td>
                        <td className="px-3 py-2" style={{ color:'var(--text-muted)' }}>{d.shift || '—'}</td>
                        <td className="px-3 py-2" style={{ color:'var(--text-muted)' }}>{d.check_in || '—'}</td>
                        <td className="px-3 py-2" style={{ color:'var(--text-muted)' }}>{d.check_out || '—'}</td>
                        <td className="px-3 py-2" style={{ color:'var(--text-h)' }}>{d.working_hours ?? '—'}</td>
                        <td className="px-3 py-2" style={{ color:'var(--text-muted)' }}>{d.overtime_hours ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
              Attendance is captured in SangoeTrack and synced into HRMS on a schedule; HRMS is not the system of record.
            </p>
          </>
        )}
    </div>
  )
}
