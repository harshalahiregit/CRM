import { useState } from 'react'
import { Video, UserCheck, CheckCircle2, AlertTriangle } from 'lucide-react'
import { whereAmI } from '@/lib/meetings/whereAmI'

/**
 * The joining link, and the attendance record beside it — one component, every
 * screen that shows a meeting.
 *
 * The link used to be a bare href in four separate places, each fed by a
 * different endpoint, each with its own idea of when to draw it. That is how
 * the Purchase pages drifted from the TPV ones in the first place, so this is
 * one component and the differences are props.
 *
 * ── The link is no longer a toll ────────────────────────────────────────
 * It used to be: `meeting_link` was withheld until the person marked
 * attendance. That made sense while the CRM was the only place the link
 * existed. It is not any more — the moment the organiser pastes the real room,
 * MeetingLinkAnnouncer e-mails that exact URL to everyone invited, with a
 * calendar attachment carrying it. Withholding it here would only mean the CRM
 * is the slowest route to a link already in the reader's inbox.
 *
 * So a REAL room is shown to anyone who can see the meeting, and Mark
 * attendance is offered beside it as what it always should have been: a record,
 * not a turnstile. What is still withheld is an INSTANT-START link
 * (`link_pending`), because meet.google.com/new is not a room — it opens a
 * different, empty meeting for whoever clicks it.
 *
 * `has_meeting_link` is what distinguishes "this meeting is not online" from
 * "you have not unlocked it yet". Without it both render as nothing at all, and
 * a waiting action looks like a missing link.
 *

 * @param meeting  a row carrying has_meeting_link / meeting_link /
 *                 attendance_marked, plus the timing fields
 * @param onMark   (id, where) => Promise of the gate fields; the response is
 *                 kept here so the caller does not have to merge it back into
 *                 its own list. `where` carries { latitude, longitude } when
 *                 the browser offered them, and is {} otherwise.
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
  if (m?.mode === 'onsite' || m?.is_expired) return null
  if (m?.status === 'Completed' || m?.status === 'Cancelled') return null
  // The organiser has only an instant-start link so far — not a room anyone
  // else can enter. Say so, rather than showing nothing or a link to an
  // empty meeting of the reader's own.
  if (m?.link_pending) {
    return (
      <div style={{ fontSize: 12, color: '#d97706', fontWeight: 600 }}>
        Online meeting — the organiser has not shared the room yet. Check back here when the meeting starts.
      </div>
    )
  }
  if (!m?.has_meeting_link) return null

  /**
   * Mark attendance, and offer the browser's location alongside it.
   *
   * The server records the address and the device on its own — it can see
   * those. Coordinates it cannot, so they are asked for here, and `whereAmI`
   * resolves empty rather than rejecting if the person says no or the device
   * has nothing to give. Declining costs the record a line; it never costs
   * somebody the meeting.
   */
  const mark = () => {
    if (busy) return
    setBusy(true)
    setError('')
    whereAmI()
      .then(where => onMark(m.id, where))
      .then(r => setMarked(r))
      .catch(() => setError('Your attendance could not be recorded. The meeting link above still works — please try marking again.'))
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
          {/* The record, beside the link rather than in front of it. Pressing
              this is evidence that this account opened this meeting at this
              time — which is all the CRM can honestly claim about a call held
              on somebody else's servers, and is what the organiser reviews. */}
          {m.attendance_marked ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 800, color: '#16a34a' }}>
              <CheckCircle2 size={13} /> Attendance recorded
            </span>
          ) : m.can_mark_attendance !== false && (
            <button type="button" onClick={mark} disabled={busy}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 9,
                fontSize: 11.5, fontWeight: 800, cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.65 : 1,
                color: '#7C3AED', background: 'rgba(124,58,237,0.12)', border: '1px solid rgba(124,58,237,0.35)' }}>
              <UserCheck size={13} /> {busy ? 'Recording…' : 'Mark my attendance'}
            </button>
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
            The organiser has not opened the room yet. Your attendance can be recorded now.
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
