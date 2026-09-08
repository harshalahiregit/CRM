import { useState } from 'react'
import { Video, UserCheck, CheckCircle2, AlertTriangle } from 'lucide-react'

/**
 * The joining link, and what it costs — one component, every screen that shows
 * a meeting.
 *
 * The link used to be a bare href in four separate places, each fed by a
 * different endpoint, each with its own idea of when to draw it. That is how
 * the Purchase pages drifted from the TPV ones in the first place, so this is
 * one component and the differences are props.
 *
 * ── What it is gating ───────────────────────────────────────────────────
 * `meeting_link` is NOT in the payload until the person marks attendance — the
 * server withholds it (see MeetingAttendanceGate). So this component is not
 * hiding anything: before marking there is genuinely nothing here to reveal,
 * which is the only version of this that survives someone opening the network
 * tab.
 *
 * `has_meeting_link` is what distinguishes "this meeting is not online" from
 * "you have not unlocked it yet". Without it both render as nothing at all, and
 * a waiting action looks like a missing link.
 *
 * ── Two clicks, deliberately ────────────────────────────────────────────
 * Mark attendance, then open. Doing both in one gesture would put the link
 * behind a popup blocker — a browser only allows window.open during the click
 * that asked for it — and would make the attendance record a side effect of
 * leaving rather than something the person did.
 *
 * @param meeting  a row carrying has_meeting_link / meeting_link /
 *                 attendance_marked, plus the timing fields
 * @param onMark   (id) => Promise of the gate fields; the response is kept here
 *                 so the caller does not have to merge it back into its own list
 * @param compact  drop the readable address and the countdown — for a card with
 *                 no room for them
 */
export default function MeetingJoinGate({ meeting, onMark, compact = false }) {
  const [marked, setMarked] = useState(null)   // the gate fields, once earned
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const m = marked ? { ...meeting, ...marked } : meeting

  // Not an online meeting, or one nobody should still be joining. The clock
  // matters as well as the status: the link used to be offered for meetings
  // that had already finished, which reads as though they are still open.
  if (!m?.has_meeting_link) return null
  if (m.mode === 'onsite' || m.is_expired) return null
  if (m.status === 'Completed' || m.status === 'Cancelled') return null

  const mark = () => {
    if (busy) return
    setBusy(true)
    setError('')
    Promise.resolve(onMark(m.id))
      .then(r => setMarked(r))
      .catch(() => setError('Your attendance could not be recorded, so the link is still locked. Please try again.'))
      .finally(() => setBusy(false))
  }

  return (
    <div>
      {m.meeting_link ? (
        <div style={row}>
          <a href={m.meeting_link} target="_blank" rel="noopener noreferrer"
            style={{ ...btn, background: m.is_live ? 'linear-gradient(145deg,#22c55e,#16a34a)' : 'linear-gradient(145deg,#38bdf8,#0284c7)', textDecoration: 'none' }}>
            <Video size={14} /> {m.is_live ? 'Join now' : 'Join meeting'}
          </a>
          {m.attendance_marked && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 800, color: '#16a34a' }}>
              <CheckCircle2 size={13} /> Attendance recorded
            </span>
          )}
          {m.is_live && <span style={{ fontSize: 11.5, fontWeight: 800, color: '#16a34a' }}>● In progress</span>}
          {!compact && (
            /* The address itself, readable — so it can be copied, read out, or
               opened on another device. */
            <a href={m.meeting_link} target="_blank" rel="noopener noreferrer" title={m.meeting_link}
              style={{ fontSize: 11.5, color: 'var(--text-muted)', maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {String(m.meeting_link).replace(/^https?:\/\//, '')}
            </a>
          )}
        </div>
      ) : (
        <div style={row}>
          <button type="button" onClick={mark} disabled={busy}
            style={{ ...btn, border: 'none', cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.65 : 1, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}>
            <UserCheck size={14} /> {busy ? 'Recording…' : 'Mark attendance'}
          </button>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            The joining link appears here once your attendance is recorded.
          </span>
        </div>
      )}

      {error && (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 7, marginTop: 8, padding: '8px 11px', borderRadius: 9, background: 'rgba(220,38,38,0.08)', border: '1px solid rgba(220,38,38,0.25)' }}>
          <AlertTriangle size={13} style={{ color: '#dc2626', flexShrink: 0, marginTop: 1 }} />
          <span style={{ fontSize: 12, color: '#b91c1c', fontWeight: 600 }}>{error}</span>
        </div>
      )}
    </div>
  )
}

const row = { display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }
const btn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px',
  borderRadius: 9, fontSize: 12.5, fontWeight: 800, color: '#fff',
}
