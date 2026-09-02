import { useEffect, useState } from 'react'
import { Clock, LogIn, LogOut, Coffee, Play } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useToast } from '@/components/ui/Toast'

/**
 * Clock yourself in and out.
 *
 * Shown on the main dashboard and in the HR module, so the same person sees the
 * same control wherever they happen to be. What you can do next is decided by the
 * SERVER (`data.can`) rather than re-derived here from nullable columns — two
 * screens deriving it separately is how they end up disagreeing about whether you
 * are already clocked in.
 *
 * The "not linked" case is deliberately quiet. Until the identity backfill runs,
 * most logins have no employee record, and a red error on everybody's dashboard
 * would be alarming and useless. It says what is missing and who fixes it.
 */
export default function MyAttendanceCard({ compact = false }) {
  const [state, setState] = useState({ loading: true, data: null, unlinked: false, error: null })
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  const load = async () => {
    try {
      const res = await hrApi.attendance.me.today()
      setState({ loading: false, data: res.data, unlinked: false, error: null })
    } catch (e) {
      // 403 here means "your login has no employee record" — expected for many
      // people right now, so it is a state rather than a failure.
      if (e?.response?.status === 403) {
        setState({ loading: false, data: null, unlinked: true, error: e?.response?.data?.message || null })
        return
      }
      setState({ loading: false, data: null, unlinked: false, error: e?.response?.data?.message || 'Could not load your attendance.' })
    }
  }

  useEffect(() => { load() }, [])

  const act = async (fn, done) => {
    setBusy(true)
    try {
      await fn()
      await load()
      toast.success(done)
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not work. Try again.')
    } finally {
      setBusy(false)
    }
  }

  const time = (v) => (v ? String(v).slice(11, 16) || String(v).slice(0, 5) : '—')

  if (state.loading) {
    return (
      <div className="card-3d" style={{ padding: '18px 20px' }}>
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading your attendance…</div>
      </div>
    )
  }

  if (state.unlinked) {
    return (
      <div className="card-3d" style={{ padding: '18px 20px' }}>
        <div className="flex items-center gap-2 mb-1">
          <Clock size={15} style={{ color: 'var(--text-muted)' }} />
          <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Attendance</span>
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {state.error || 'Your login is not linked to an employee record yet. Contact HR.'}
        </p>
      </div>
    )
  }

  if (state.error) {
    return (
      <div className="card-3d" style={{ padding: '18px 20px' }}>
        <div className="text-xs" style={{ color: '#f87171' }}>{state.error}</div>
        <button onClick={load} className="text-xs font-semibold mt-2" style={{ color: 'var(--accent)' }}>Try again</button>
      </div>
    )
  }

  const { attendance: a, can, employee } = state.data

  const Btn = ({ onClick, icon: Icon, label, tone }) => (
    <button
      onClick={onClick}
      disabled={busy}
      className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold disabled:opacity-50 transition-all"
      style={tone === 'primary'
        ? { background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff' }
        : { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-body)' }}
    >
      <Icon size={13} /> {label}
    </button>
  )

  return (
    <div className="card-3d" style={{ padding: compact ? '16px 18px' : '20px 22px' }}>
      <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div className="flex items-center gap-2">
          <Clock size={15} style={{ color: '#a78bfa' }} />
          <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>My Attendance</span>
          <span className="text-[10px] font-mono" style={{ color: 'var(--text-muted)' }}>{employee?.employee_code}</span>
        </div>
        {a?.status && (
          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
            style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{a.status}</span>
        )}
      </div>

      <div className="flex items-center gap-5 mb-4">
        <div>
          <p className="label-caps mb-0.5" style={{ color: 'var(--text-muted)' }}>In</p>
          <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{time(a?.check_in)}</p>
        </div>
        <div>
          <p className="label-caps mb-0.5" style={{ color: 'var(--text-muted)' }}>Out</p>
          <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{time(a?.check_out)}</p>
        </div>
        {a?.working_hours != null && (
          <div>
            <p className="label-caps mb-0.5" style={{ color: 'var(--text-muted)' }}>Hours</p>
            <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{a.working_hours}</p>
          </div>
        )}
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        {can.check_in    && <Btn onClick={() => act(hrApi.attendance.me.checkIn,    'Clocked in')}  icon={LogIn}  label="Clock in"  tone="primary" />}
        {can.check_out   && <Btn onClick={() => act(hrApi.attendance.me.checkOut,   'Clocked out')} icon={LogOut} label="Clock out" tone="primary" />}
        {can.break_start && <Btn onClick={() => act(hrApi.attendance.me.breakStart, 'Break started')} icon={Coffee} label="Start break" />}
        {can.break_end   && <Btn onClick={() => act(hrApi.attendance.me.breakEnd,   'Break ended')} icon={Play}   label="End break" />}
        {!can.check_in && !can.check_out && !can.break_start && !can.break_end && (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Done for today.</span>
        )}
      </div>
    </div>
  )
}
