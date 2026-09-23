import { useEffect, useRef, useState } from 'react'
import { Clock, LogIn, LogOut, Coffee, Play } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/components/ui/Toast'
import { getLocation, buildNote } from '@/lib/punchEvidence'
import { hrTime } from '@/modules/hr/constants'
import SelfieCapture from '@/modules/hr/components/SelfieCapture'

/**
 * Clock in / out from the top bar, beside the eye, theme and bell.
 *
 * Punching is the one thing everybody does every day, and it used to live
 * inside HR → Dashboard: two navigations away from wherever you actually were.
 * Up here it is in front of you all day, which is the point — you cannot forget
 * a button you can always see.
 *
 * Who sees it: anyone whose permissions include hr_attendance at ANY scope, so
 * an ordinary employee with only their own record gets it, and a workspace
 * without the HR module gets nothing. A login with no employee record behind it
 * answers 403 on /today; that is a normal state here, not an error, so the
 * control simply does not render. The HR dashboard card is the place that
 * explains why — a header is no place to tell somebody to contact HR.
 *
 * Every decision about which action is available comes from the server's `can`
 * block. Re-deriving it here from nullable columns is how two clients end up
 * disagreeing about whether you are on a break.
 */
export default function HeaderPunch() {
  const { canSee } = useAuth()
  const hasHrModule = canSee('hr_attendance')

  const [data, setData] = useState(null)      // null until loaded, or if hidden
  const [hidden, setHidden] = useState(false) // 403 / no module / load failed
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState(false)
  const [now, setNow] = useState(() => Date.now())
  const [policy, setPolicy] = useState({ selfie: false, location: true })
  const [selfieFor, setSelfieFor] = useState(null) // 'in' | 'out' while the camera dialog is open
  const popRef = useRef(null)
  const toast = useToast()

  const load = async () => {
    try {
      const res = await hrApi.attendance.me.today()
      setData(res.data)
      setHidden(false)
    } catch (e) {
      // 403 = this login has no employee record. Expected for plenty of
      // accounts; say nothing rather than putting an error in the chrome.
      setHidden(true)
    }
  }

  useEffect(() => { if (hasHrModule) load() }, [hasHrModule])

  // What this workspace asks for on a web punch. Failing to read it must not
  // disable punching, so the defaults stand: location asked, selfie not.
  useEffect(() => {
    if (!hasHrModule) return
    hrApi.settings.mine()
      .then(s => setPolicy({
        selfie: !!s?.web_punch_require_selfie,
        location: s?.web_punch_require_location !== false,
      }))
      .catch(() => {})
  }, [hasHrModule])

  // Tick only while a shift is actually open, so an idle tab is not re-rendering
  // once a second for a timer nobody is looking at.
  const checkIn = data?.attendance?.check_in
  const checkOut = data?.attendance?.check_out
  const running = !!checkIn && !checkOut
  useEffect(() => {
    if (!running) return
    const t = setInterval(() => setNow(Date.now()), 30000)
    return () => clearInterval(t)
  }, [running])

  useEffect(() => {
    if (!open) return
    const away = (e) => { if (popRef.current && !popRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', away)
    return () => document.removeEventListener('mousedown', away)
  }, [open])

  if (!hasHrModule || hidden || !data) return null

  const can = data.can ?? {}
  const onBreak = !!can.break_end

  const act = async (fn, done) => {
    setBusy(true)
    try {
      await fn()
      await load()
      toast.success(done)
      setOpen(false)
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not work. Try again.')
    } finally {
      setBusy(false)
    }
  }

  /**
   * Clock in or out with whatever the browser can prove.
   *
   * Location is asked for first because it is silent — the prompt is the
   * browser's own and most people have already answered it. A selfie needs a
   * dialog, so it is only opened when this workspace asks for one; the dialog
   * itself always offers a way through without a photo.
   */
  const punch = async (side, selfieBlob, selfieReason) => {
    const location = policy.location ? await getLocation() : null
    const evidence = {
      latitude: location?.ok ? location.latitude : undefined,
      longitude: location?.ok ? location.longitude : undefined,
      selfie: selfieBlob || undefined,
      verificationNote: buildNote({
        location, selfie: selfieBlob, selfieReason,
        requireSelfie: policy.selfie, requireLocation: policy.location,
      }),
    }
    const call = side === 'out' ? hrApi.attendance.me.checkOut : hrApi.attendance.me.checkIn
    await act(() => call(evidence), side === 'out' ? 'Clocked out' : 'Clocked in')
  }

  /** Opens the camera first when this workspace asks for a photo. */
  const startPunch = (side) => (policy.selfie ? setSelfieFor(side) : punch(side))

  /** "2h 14m" since clock-in. Hours matter, seconds do not. */
  const elapsed = () => {
    if (!checkIn) return ''
    const started = new Date(String(checkIn).replace(' ', 'T')).getTime()
    if (Number.isNaN(started)) return ''
    const mins = Math.max(0, Math.floor((now - started) / 60000))
    const h = Math.floor(mins / 60)
    return h > 0 ? `${h}h ${mins % 60}m` : `${mins}m`
  }

  // Same UTC substring bug as the dashboard card — the punch you just made
  // read back 5h30m earlier than the register showed it.
  const hhmm = hrTime

  // Three visual states: not started (green, inviting), running (purple, live),
  // done for the day (muted — nothing left to do, but the record is still there).
  const tone = !checkIn
    ? { bg: 'linear-gradient(135deg,rgba(16,185,129,0.15),rgba(5,150,105,0.08))', bd: 'rgba(16,185,129,0.3)', fg: '#10b981' }
    : running
      ? { bg: 'linear-gradient(135deg,rgba(124,58,237,0.15),rgba(91,33,182,0.08))', bd: 'rgba(124,58,237,0.3)', fg: '#a78bfa' }
      : { bg: 'var(--bg-input)', bd: 'var(--border)', fg: 'var(--text-muted)' }

  const label = !checkIn ? 'Clock in' : running ? (onBreak ? 'On break' : elapsed()) : hhmm(checkOut)

  return (
    <div className="relative hidden md:block" ref={popRef}>
      <button
        onClick={() => (can.check_in ? startPunch('in') : setOpen(o => !o))}
        disabled={busy}
        className="flex items-center gap-1.5 h-9 px-3 rounded-xl text-xs font-bold transition-all duration-200 disabled:opacity-60"
        style={{ background: tone.bg, border: `1px solid ${tone.bd}`, color: tone.fg }}
        aria-label={can.check_in ? 'Clock in' : 'Attendance'}
        title={checkIn ? `In ${hhmm(checkIn)}${checkOut ? ` · Out ${hhmm(checkOut)}` : ''}` : 'Clock in for today'}
        onMouseEnter={e => e.currentTarget.style.transform = 'scale(1.04)'}
        onMouseLeave={e => e.currentTarget.style.transform = 'scale(1)'}
      >
        {onBreak ? <Coffee size={14} /> : <Clock size={14} />}
        <span>{label}</span>
      </button>

      {open && (
        <div
          className="absolute right-0 mt-2 w-56 rounded-2xl p-2 z-50"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: '0 12px 32px rgba(0,0,0,0.18)' }}
        >
          <div className="px-3 py-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            In <b style={{ color: 'var(--text-h)' }}>{hhmm(checkIn)}</b>
            {checkOut && <> · Out <b style={{ color: 'var(--text-h)' }}>{hhmm(checkOut)}</b></>}
          </div>

          {can.break_start && (
            <PopAction icon={Coffee} label="Start break" disabled={busy}
              onClick={() => act(hrApi.attendance.me.breakStart, 'Break started')} />
          )}
          {can.break_end && (
            <PopAction icon={Play} label="End break" disabled={busy}
              onClick={() => act(hrApi.attendance.me.breakEnd, 'Break ended')} />
          )}
          {can.check_out && (
            <PopAction icon={LogOut} label="Clock out" tone="#f87171" disabled={busy}
              onClick={() => startPunch('out')} />
          )}
          {!can.break_start && !can.break_end && !can.check_out && (
            <div className="px-3 py-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Done for today.
            </div>
          )}
        </div>
      )}

      {selfieFor && (
        <SelfieCapture
          title={selfieFor === 'out' ? 'Photo for your clock-out' : 'Photo for your clock-in'}
          onCancel={() => setSelfieFor(null)}
          onDone={({ blob, reason }) => {
            const side = selfieFor
            setSelfieFor(null)
            punch(side, blob, reason)
          }}
        />
      )}
    </div>
  )
}

function PopAction({ icon: Icon, label, onClick, disabled, tone }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold transition-colors disabled:opacity-60"
      style={{ color: tone || 'var(--text-h)' }}
      onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.08)'}
      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
    >
      <Icon size={14} /> {label}
    </button>
  )
}
