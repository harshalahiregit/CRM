import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Users, ListChecks, FileText, Loader2, AlertTriangle,
  CheckCircle2, Save, Video, ExternalLink, PanelRightClose, PanelRightOpen, Clock,
} from 'lucide-react'
import { meetingEngineApi as kickoffApi, meetingBase } from '@/services/meetingEngineApi'
import { fmtDateTime } from '../kickoffConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * The meeting, run inside the CRM.
 *
 * Before this, joining meant opening the platform in a second tab: the agenda,
 * the roster and the minutes stayed behind in this one, so the chair spent the
 * meeting flipping between them and wrote the minutes afterwards from memory.
 * Attendance was then ticked by hand, from recollection, some time later.
 *
 * Here the call sits beside the record it belongs to. The agenda is on screen
 * with a box under each point, attendance ticks itself as people arrive, and
 * the notes are already saved when the call ends.
 *
 * ── Why this is a full-screen overlay ───────────────────────────────────
 * The route lives under /app so the meeting API resolves to the right engine
 * from the path (see meetingEngineApi). But rendering INSIDE the app shell left
 * the call sharing the viewport with the sidebar and the shell's padding, and a
 * 100vh page nested in a padded container overflows — which is what made the
 * video small and the layout sit wrong. A fixed overlay keeps the route where
 * the API needs it while giving the call the whole screen.
 *
 * ── Why the call does not start on page load ────────────────────────────
 * Mounting Jitsi immediately asks for the camera the moment the page opens, and
 * drops someone who has never seen Jitsi straight into an unfamiliar screen.
 * The start step below says what is about to happen — including the one thing
 * about the free public server that surprises people, that whoever starts the
 * meeting must sign in once — and only then loads the call.
 *
 * ── What is saved, and where ────────────────────────────────────────────
 *  - agenda notes  → meeting_agenda_items.discussion / .decision
 *  - minutes       → kickoff_meetings.minutes
 *    both through POST …/room/notes, autosaved while typing.
 *  - attendance    → recorded from the call itself through POST …/room/presence,
 *    which reports who is in the room every twenty seconds. Somebody who joins
 *    under a name that is not on the roster is added to it rather than dropped,
 *    which is what the earlier name-matching version did to nearly everyone.
 */
export default function MeetingRoom() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [meeting, setMeeting] = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr]         = useState(null)
  const [tab, setTab]         = useState('agenda')
  const [panelOpen, setPanelOpen] = useState(true)
  const [started, setStarted] = useState(false)

  // Notes live here while they are being typed; the server is caught up by the
  // autosave below rather than on every keystroke.
  const [agendaNotes, setAgendaNotes] = useState({})   // { [agendaItemId]: { discussion, decision } }
  const [minutes, setMinutes]         = useState('')
  const [saveState, setSaveState]     = useState('idle')   // idle | saving | saved | error

  const [joined, setJoined]     = useState(false)
  const [inCall, setInCall]     = useState([])
  // The roster as the server last reconciled it — who has been in the call,
  // when they arrived, how long they stayed.
  const [roster, setRoster]     = useState(null)
  const [heldFrom, setHeldFrom] = useState(null)
  const [tick, setTick]         = useState(0)

  const frameRef  = useRef(null)
  const apiRef    = useRef(null)
  const saveTimer = useRef(null)
  const beatTimer = useRef(null)
  // The name this browser joins under. Read inside the heartbeat, which is
  // created once, so it has to be a ref rather than a captured value.
  const displayNameRef = useRef('')
  // The autosave fires from a timer and from the hang-up handler, both of which
  // close over state. A ref keeps them reading what is on screen NOW rather than
  // whatever was there when the handler was created.
  const latest = useRef({ agendaNotes: {}, minutes: '', id })
  latest.current = { agendaNotes, minutes, id }
  displayNameRef.current = meeting?.chairperson || meeting?.organizer || ''

  /* ── Load the meeting ─────────────────────────────────────────────── */

  useEffect(() => {
    let alive = true
    kickoffApi.get(id).then(d => {
      if (!alive) return
      const m = d?.data ?? d
      setMeeting(m)
      setMinutes(m.minutes || '')
      setAgendaNotes(Object.fromEntries(
        (m.agenda_items || []).map(a => [a.id, { discussion: a.discussion || '', decision: a.decision || '' }])
      ))
      setLoading(false)
    }).catch(() => { if (alive) { setErr('Could not load this meeting.'); setLoading(false) } })
    return () => { alive = false }
  }, [id])

  // The server's reconciled roster once the call has started, the stored one
  // before that — so the panel is populated the moment the page opens.
  const attendees = roster ?? meeting?.attendees ?? []
  const agenda    = meeting?.agenda_items || []
  const stillIn   = useMemo(() => new Set(inCall.map(norm)), [inCall])

  /* ── Autosave ─────────────────────────────────────────────────────── */

  const save = useCallback(async () => {
    const { agendaNotes: notes, minutes: mins, id: mId } = latest.current
    setSaveState('saving')
    try {
      await kickoffApi.roomNotes(mId, {
        minutes: mins,
        agenda: Object.entries(notes).map(([itemId, v]) => ({
          id: Number(itemId), discussion: v.discussion, decision: v.decision,
        })),
      })
      setSaveState('saved')
    } catch { setSaveState('error') }
  }, [])

  /** Queue a save a moment after typing stops, so a sentence is one request. */
  const queueSave = useCallback(() => {
    setSaveState('idle')
    clearTimeout(saveTimer.current)
    saveTimer.current = setTimeout(save, 1200)
  }, [save])

  // Leaving the page must not lose what is on screen — the timer may not have
  // fired yet when the chair clicks away.
  useEffect(() => () => { clearTimeout(saveTimer.current); save() }, [save])

  /* ── Attendance, recorded as the call happens ─────────────────────── */

  /**
   * Report who is in the call, right now.
   *
   * This used to tick the roster by matching a person's Jitsi display name
   * against their name on the roster, character for character — which is almost
   * nobody. People type their own name into the prejoin box, guests type a
   * first name, and the chair is often not on the roster at all. Everyone else
   * was watched arriving on screen and then dropped, so the meeting ended with
   * an empty attendance list.
   *
   * So the room now reports the WHOLE ROOM as it stands, repeatedly, and the
   * server reconciles it (see MeetingPresence). Sending a snapshot rather than
   * one arrival at a time is what makes it safe to repeat: the same snapshot
   * applied twice is the same result, so a dropped request, a reload or a crash
   * costs nothing, and nobody has to be matched by name to be counted.
   */
  const beat = useCallback(async (ended = false) => {
    const api = apiRef.current
    if (!api) return

    let people = []
    try {
      const me = api.myUserId?.()
      people = (api.getParticipantsInfo?.() || []).map(p => ({
        key: String(p.participantId ?? p.id ?? ''),
        name: p.displayName || p.formattedDisplayName || '',
        // The signed-in user, so the server can tie the chair to their account
        // rather than to whatever name their browser remembered.
        self: me != null && String(p.participantId ?? p.id) === String(me),
      }))

      // Some Jitsi builds list only the OTHER people in the room. Left at that,
      // the one person certain to be in the meeting — whoever opened it — would
      // be the one person never recorded, which is exactly the complaint this
      // set out to fix. Added explicitly when the list does not carry them.
      if (me != null && !people.some(p => p.self)) {
        people.push({ key: String(me), name: displayNameRef.current || '', self: true })
      }
    } catch { /* the call is going down; report the end with what we have */ }

    try {
      const d = await kickoffApi.roomPresence(latest.current.id, { in_call: people, ended })
      if (d?.attendees) setRoster(d.attendees)
      if (d?.actual_start_at) setHeldFrom(d.actual_start_at)
    } catch { /* a lost heartbeat is repaired by the next one */ }
  }, [])

  // While the call runs, every 20 seconds. Someone who joins and leaves between
  // two beats is still recorded — they appear in one of them.
  useEffect(() => {
    if (!joined) return
    beat()
    beatTimer.current = setInterval(beat, PRESENCE_MS)
    return () => clearInterval(beatTimer.current)
  }, [joined, beat])

  // Drives the "running for 12 min" counter without re-rendering the video.
  useEffect(() => {
    if (!joined) return
    const t = setInterval(() => setTick(n => n + 1), 30000)
    return () => clearInterval(t)
  }, [joined])

  /* ── The call ─────────────────────────────────────────────────────── */

  // A Jitsi link is https://<host>/<room>. Reading both out of the stored link
  // rather than hard-coding meet.jit.si means a move to a self-hosted server is
  // a change to the link, and old meetings keep reaching the server they were
  // created on.
  const jitsi = useMemo(() => {
    const link = meeting?.meeting_link || ''
    if (!/^https?:\/\//i.test(link)) return null
    try {
      const u = new URL(link)
      const room = u.pathname.replace(/^\/+/, '')
      return room ? { domain: u.host, room } : null
    } catch { return null }
  }, [meeting?.meeting_link])

  const isJitsi = (meeting?.meeting_platform ?? 'jitsi') === 'jitsi' && !!jitsi
  // Only the free public server demands a sign-in from whoever starts the
  // meeting. A self-hosted one does not, so do not warn about it there.
  const publicJitsi = jitsi?.domain === PUBLIC_JITSI

  useEffect(() => {
    if (!started || !isJitsi || !frameRef.current || apiRef.current) return
    let disposed = false

    loadJitsiScript(jitsi.domain).then(() => {
      if (disposed || !frameRef.current || apiRef.current) return
      const Ctor = window.JitsiMeetExternalAPI
      if (!Ctor) { setErr('The meeting could not be loaded. Use "Open in a new tab" instead.'); return }

      const api = new Ctor(jitsi.domain, {
        roomName: jitsi.room,
        parentNode: frameRef.current,
        width: '100%',
        height: '100%',
        // Pre-filled from the meeting record so people appear under the name the
        // roster knows them by — which is what makes the automatic tick possible.
        userInfo: { displayName: meeting?.chairperson || meeting?.organizer || undefined },
        configOverwrite: {
          prejoinPageEnabled: true,     // a moment to check camera and mic
          disableDeepLinking: true,     // never bounce into a native app
          subject: meeting?.title || 'Meeting',
        },
        interfaceConfigOverwrite: {
          SHOW_CHROME_EXTENSION_BANNER: false,
          MOBILE_APP_PROMO: false,
        },
      })
      apiRef.current = api

      // Every change to the room is reported straight away as well as on the
      // timer, so the panel keeps up with the call rather than lagging it.
      const names = () => (api.getParticipantsInfo?.() || []).map(p => p.displayName).filter(Boolean)

      api.addListener('videoConferenceJoined', () => { setJoined(true); setInCall(names()); beat() })
      api.addListener('participantJoined', () => { setInCall(names()); beat() })
      api.addListener('participantLeft', () => { setInCall(names()); beat() })
      api.addListener('displayNameChange', () => { setInCall(names()); beat() })
      // The call ended — the last person left, or the public server cut it off.
      // Close the record here rather than waiting for the chair to click away,
      // so the meeting stops reading as "In progress" the moment it is over.
      api.addListener('videoConferenceLeft', () => { setJoined(false); beat(true) })
      api.addListener('readyToClose', () => { setJoined(false); leave() })
    }).catch(() => {
      // The script is fetched from the meeting's own Jitsi server, so this
      // is the offline / blocked / server-down case. Say so and leave the
      // link, rather than showing a stage that never fills in.
      if (!disposed) setErr('Could not reach the meeting server. Check your connection, or use "Open in a new tab".')
    })

    return () => {
      disposed = true
      try { apiRef.current?.dispose() } catch { /* already gone */ }
      apiRef.current = null
    }
    // Keyed on the room alone: re-running this on every keystroke would tear the
    // call down and rebuild it mid-sentence.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [started, isJitsi, jitsi?.domain, jitsi?.room])

  /** Hang up, close the record, make sure the notes are stored, and go back. */
  const leave = useCallback(async () => {
    clearTimeout(saveTimer.current)
    clearInterval(beatTimer.current)
    // Before the hang-up: once the call is torn down there is nobody left to
    // report, and this is the moment the meeting actually finished.
    await beat(true)
    await save()
    try { apiRef.current?.executeCommand('hangup') } catch { /* already ended */ }
    navigate(`${meetingBase()}/kickoff/${latest.current.id}`)
  }, [save, beat, navigate])

  /*
   * The live counter, recomputed on every `tick` — which is what makes it move.
   *
   * It sits ABOVE the early returns deliberately. React matches hooks by call
   * order, so a hook below `if (loading) return …` is skipped on the loading
   * render and called on the next one: the hook count grows and React throws
   * "Rendered more hooks than during the previous render", taking the whole
   * screen down with it. Every dependency here is state declared at the top of
   * the component, so nothing is lost by hoisting it.
   */
  const running = useMemo(
    () => (heldFrom && joined ? elapsedSeconds(heldFrom) : null),
    [heldFrom, joined, tick],
  )

  /* ── Render ───────────────────────────────────────────────────────── */

  if (loading) return <Shell><Centre>Loading the meeting…</Centre></Shell>
  if (err && !meeting) return <Shell><Centre tone="#ef4444">{err}</Centre></Shell>

  const presentCount = attendees.filter(a => a.attended).length
  const timing = describeTiming(meeting.scheduled_at, meeting.end_at)

  return (
    <Shell>
      {/* Header */}
      <header style={SX.header}>
        <button onClick={leave} title="Leave and go back to the meeting" style={SX.iconBtn}>
          <ArrowLeft size={15} />
        </button>
        <div style={{ minWidth: 0, flex: '0 1 auto' }}>
          <h1 style={SX.title}>{meeting.title}</h1>
          <p style={SX.subtitle}>
            {fmtDateTime(meeting.scheduled_at)}{meeting.meeting_no ? ` · ${meeting.meeting_no}` : ''}
          </p>
        </div>
        <span style={{ ...SX.timingPill, background: `${timing.color}1a`, color: timing.color, border: `1px solid ${timing.color}44` }}>
          <Clock size={11} /> {timing.label}
        </span>
        {/* How long the call has actually been running, as opposed to how long
            it was booked for. The two are routinely different and only one of
            them is a fact. */}
        {running !== null && (
          <span style={{ ...SX.timingPill, background: 'rgba(16,185,129,0.10)', color: '#10b981', border: '1px solid rgba(16,185,129,0.28)' }}>
            Running {fmtSpan(running)}
          </span>
        )}
        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
          <SaveBadge state={saveState} />
          <button onClick={() => setPanelOpen(o => !o)} title={panelOpen ? 'Hide the notes panel' : 'Show the notes panel'} style={SX.iconBtn}>
            {panelOpen ? <PanelRightClose size={15} /> : <PanelRightOpen size={15} />}
          </button>
          <button onClick={leave} style={SX.leaveBtn}>Leave meeting</button>
        </div>
      </header>

      {/* Call | work panel */}
      <div className="mr-body" style={{ ...SX.body, gridTemplateColumns: panelOpen ? 'minmax(0,1fr) clamp(320px, 27vw, 420px)' : 'minmax(0,1fr) 0px' }}>
        <section style={SX.stage}>
          {!isJitsi ? (
            <OtherPlatform meeting={meeting} />
          ) : !started ? (
            <StartCard
              meeting={meeting}
              timing={timing}
              publicJitsi={publicJitsi}
              onStart={() => setStarted(true)}
            />
          ) : (
            <>
              <div ref={frameRef} style={{ position: 'absolute', inset: 0 }} />
              {/* A failure AFTER the meeting loaded has to be shown here — the
                  full-page error branch above only runs when there is no meeting
                  to render, so this one would otherwise be set and never seen. */}
              {err ? (
                <div style={SX.stageError}>
                  <AlertTriangle size={18} style={{ color: '#f59e0b' }} />
                  <p style={{ margin: 0, color: 'var(--text-h)', fontSize: 13, fontWeight: 700 }}>{err}</p>
                  {meeting.meeting_link && (
                    <a href={meeting.meeting_link} target="_blank" rel="noopener noreferrer" style={{ ...SX.startBtn, textDecoration: 'none' }}>
                      <ExternalLink size={15} /> Open in a new tab
                    </a>
                  )}
                </div>
              ) : !joined && (
                <div style={SX.connecting}><Loader2 size={15} className="ko-spin" /> Connecting…</div>
              )}
            </>
          )}
        </section>

        {panelOpen && (
          <aside style={SX.panel}>
            <div style={SX.tabs}>
              {[
                ['agenda',    ListChecks, 'Agenda',   agenda.length],
                ['attendees', Users,      'Who came', `${presentCount}/${attendees.length}`],
                ['notes',     FileText,   'Notes',    null],
              ].map(([key, Icon, label, count]) => (
                <button key={key} onClick={() => setTab(key)}
                  style={{ ...SX.tab, borderBottomColor: tab === key ? '#a78bfa' : 'transparent', color: tab === key ? '#c4b5fd' : 'var(--text-muted)' }}>
                  <Icon size={13} /> {label}{count !== null && count !== undefined ? ` (${count})` : ''}
                </button>
              ))}
            </div>

            <div style={SX.panelBody}>
              {tab === 'agenda' && (
                agenda.length === 0 ? <Empty>No agenda was set for this meeting.</Empty> : agenda.map((a, i) => (
                  <div key={a.id} style={{ marginBottom: 16, paddingBottom: 14, borderBottom: i < agenda.length - 1 ? '1px solid var(--border)' : 'none' }}>
                    <div style={{ display: 'flex', gap: 7, alignItems: 'baseline' }}>
                      <span style={{ fontSize: 11, fontWeight: 900, color: '#a78bfa' }}>{i + 1}.</span>
                      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.item}</span>
                    </div>
                    {a.owner_names && <p style={{ margin: '3px 0 0 18px', fontSize: 11, color: 'var(--text-muted)' }}>Owner: {a.owner_names}</p>}
                    <NoteBox label="What was discussed" value={agendaNotes[a.id]?.discussion ?? ''}
                      onChange={v => { setAgendaNotes(n => ({ ...n, [a.id]: { ...n[a.id], discussion: v } })); queueSave() }} />
                    <NoteBox label="What was decided" value={agendaNotes[a.id]?.decision ?? ''}
                      onChange={v => { setAgendaNotes(n => ({ ...n, [a.id]: { ...n[a.id], decision: v } })); queueSave() }} />
                  </div>
                ))
              )}

              {tab === 'attendees' && (
                attendees.length === 0 ? <Empty>Nobody has joined this meeting yet.</Empty> : (
                  <>
                    <p style={SX.hint}>
                      Recorded from the call itself — who arrived, when, and how long they stayed.
                      Somebody who joins under a name that is not on the roster is added here rather
                      than left out.
                    </p>
                    {attendees.map(a => (
                      <div key={a.id} style={SX.attendeeRow}>
                        <span style={{ width: 18, display: 'flex', justifyContent: 'center' }}>
                          {a.attended
                            ? <CheckCircle2 size={15} style={{ color: '#10b981' }} />
                            : <span style={{ width: 11, height: 11, borderRadius: '50%', border: '1.5px solid var(--text-muted)' }} />}
                        </span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                            {a.name}
                            {a.is_guest && <span style={SX.guestTag}>GUEST</span>}
                          </div>
                          {a.organisation && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{a.organisation}</div>}
                          {a.joined_at && (
                            <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>
                              Joined {fmtClock(a.joined_at)}
                              {a.seconds_in_call > 0 ? ` · ${fmtSpan(a.seconds_in_call)} in the call` : ''}
                            </div>
                          )}
                        </div>
                        {stillIn.has(norm(a.name)) && <span style={{ fontSize: 9.5, fontWeight: 800, color: '#10b981' }}>IN CALL</span>}
                      </div>
                    ))}
                  </>
                )
              )}

              {tab === 'notes' && (
                <>
                  <p style={SX.hint}>General notes for the whole meeting. Point-by-point notes go under the Agenda tab.</p>
                  <textarea value={minutes}
                    onChange={e => { setMinutes(e.target.value); queueSave() }}
                    placeholder="Anything that does not belong to one agenda point…"
                    style={{ ...SX.textarea, minHeight: 300 }} />
                </>
              )}
            </div>

            <div style={SX.panelFoot}>
              <button onClick={() => { clearTimeout(saveTimer.current); save() }} style={SX.smallBtn}>
                <Save size={13} /> Save now
              </button>
              <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Notes save on their own as you type.</span>
            </div>
          </aside>
        )}
      </div>
    </Shell>
  )
}

/* ── The start step ──────────────────────────────────────────────────────── */

/**
 * What happens before the call loads.
 *
 * Two jobs. It tells someone who has never used Jitsi what they are about to
 * see — in particular that on the free public server the FIRST person has to
 * sign in once, which otherwise looks like the app demanding an account it
 * never asked for. And it answers the scheduling question plainly: the room is
 * not locked to the scheduled time, so a meeting that starts late, or has to be
 * held again, still opens.
 */
function StartCard({ meeting, timing, publicJitsi, onStart }) {
  return (
    <div style={SX.startWrap}>
      <div style={SX.startCard}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 4 }}>
          <Video size={18} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Ready to join</h2>
        </div>
        <p style={{ margin: 0, fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.55 }}>
          {meeting.title} · {fmtDateTime(meeting.scheduled_at)}
        </p>

        <div style={{ ...SX.startNote, borderColor: `${timing.color}44`, background: `${timing.color}12` }}>
          <Clock size={14} style={{ color: timing.color, flexShrink: 0, marginTop: 1 }} />
          <div>
            <strong style={{ color: timing.color, fontSize: 12.5 }}>{timing.label}</strong>
            <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5 }}>
              {timing.explain}
            </p>
          </div>
        </div>

        {publicJitsi && (
          <div style={{ ...SX.startNote, borderColor: 'rgba(239,68,68,0.35)', background: 'rgba(239,68,68,0.10)' }}>
            <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
            <div>
              <strong style={{ color: '#ef4444', fontSize: 12.5 }}>
                On the free public server this call will be cut off after 5 minutes
              </strong>
              <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5 }}>
                {PUBLIC_JITSI} allows a call to run <em>inside</em> another site for five minutes only
                — it is a demo allowance, and 8x8 end the call when it runs out. It also asks whoever
                starts a meeting to sign in with Google, GitHub or Facebook. Both are their rules for
                their free server, and nothing in this system can change them.
                <br /><br />
                <strong style={{ color: 'var(--text-h)' }}>To hold a real meeting:</strong> open it in a
                new tab, where the five-minute limit does not apply — or, so that neither problem comes
                up again, set your own Jitsi address under <em>Settings → Meeting Server</em>. Attendance
                and the notes are recorded either way; in a new tab the attendance is ticked by hand on
                the meeting page.
              </p>
            </div>
          </div>
        )}

        <button onClick={onStart} style={SX.startBtn}>
          <Video size={16} /> Start the call
        </button>

        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-muted)', textAlign: 'center', lineHeight: 1.5 }}>
          Your camera and microphone are only asked for after you press this.
          {meeting.meeting_link && (
            <> · <a href={meeting.meeting_link} target="_blank" rel="noopener noreferrer" style={{ color: '#a78bfa', fontWeight: 700 }}>Open in a new tab instead</a></>
          )}
        </p>
      </div>
    </div>
  )
}

function OtherPlatform({ meeting }) {
  const name = { google_meet: 'Google Meet', zoom: 'Zoom', teams: 'Microsoft Teams' }[meeting.meeting_platform] || 'another platform'
  return (
    <div style={SX.startWrap}>
      <div style={{ ...SX.startCard, textAlign: 'center', alignItems: 'center' }}>
        <Video size={26} style={{ color: '#a78bfa' }} />
        <p style={{ margin: 0, color: 'var(--text-h)', fontSize: 14, fontWeight: 700 }}>This meeting runs on {name}.</p>
        <p style={{ margin: 0, color: 'var(--text-muted)', fontSize: 12.5, lineHeight: 1.55 }}>
          {name} does not allow its call to run inside another site, so it opens in its own tab.
          Keep this page open beside it — the agenda, the roster and the notes all still work.
        </p>
        {meeting.meeting_link && (
          <a href={meeting.meeting_link} target="_blank" rel="noopener noreferrer" style={{ ...SX.startBtn, textDecoration: 'none' }}>
            <ExternalLink size={15} /> Open the meeting
          </a>
        )}
      </div>
    </div>
  )
}

/* ── Small formatters ────────────────────────────────────────────────────── */

/** How often the room reports who is in the call. */
const PRESENCE_MS = 20000

/** 8x8's free server: it signs the host in, and cuts embedded calls off at 5 minutes. */
const PUBLIC_JITSI = 'meet.jit.si'

const norm = (s) => String(s || '').trim().toLowerCase()

/**
 * "14:32" in the reader's own timezone.
 *
 * Unlike the booked slot — a wall clock somebody typed, which must not be
 * re-localised — these are machine instants stamped as the call happened, so
 * localising them is exactly right: everyone sees when it happened for them.
 */
const fmtClock = (v) => {
  const d = asInstant(v)
  return d ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
}

/**
 * A UTC instant the server sent as a bare "Y-m-d H:i:s".
 *
 * The Z has to be put back before parsing: without it the browser reads the
 * value as local time and everything derived from it is out by the viewer's
 * offset. A value that already carries an offset is left alone.
 */
function asInstant(v) {
  const raw = String(v || '').trim()
  if (!raw) return null
  const hasZone = /[Zz]$|[+-]\d\d:?\d\d$/.test(raw)
  const d = new Date(raw.replace(' ', 'T') + (hasZone ? '' : 'Z'))
  return Number.isNaN(d.getTime()) ? null : d
}

/** A span of seconds as something a person would say out loud. */
function fmtSpan(seconds) {
  const s = Math.max(0, Math.round(seconds))
  if (s < 60) return 'under a minute'
  const mins = Math.round(s / 60)
  if (mins < 60) return `${mins} min`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return m ? `${h} hr ${m} min` : `${h} hr`
}

/** Seconds since the call started. */
function elapsedSeconds(startedAt) {
  const d = asInstant(startedAt)
  return d ? Math.max(0, Math.round((Date.now() - d.getTime()) / 1000)) : 0
}

/* ── Timing ──────────────────────────────────────────────────────────────── */

/**
 * Where we are relative to the scheduled slot.
 *
 * The room is deliberately NOT locked to that slot. A meeting that starts
 * twenty minutes late, runs long, or has to be picked up again the next morning
 * is ordinary; a room that refused to open outside its window would send people
 * back to WhatsApp and the record would lose the notes entirely. The scheduled
 * time is what everyone was told, not a gate — so this states plainly where we
 * are and lets the meeting go ahead either way.
 */
function describeTiming(scheduledAt, endAt) {
  const start = scheduledAt ? new Date(scheduledAt) : null
  if (!start || Number.isNaN(start.getTime())) {
    return { label: 'No time set', color: '#94a3b8', explain: 'This meeting has no scheduled time. You can still hold it now.' }
  }
  const end = endAt ? new Date(endAt) : new Date(start.getTime() + 60 * 60 * 1000)
  const now = Date.now()

  if (now < start.getTime()) {
    const mins = Math.round((start.getTime() - now) / 60000)
    const away = mins < 60 ? `${mins} min` : mins < 1440 ? `${Math.round(mins / 60)} hr` : `${Math.round(mins / 1440)} days`
    return {
      label: `Starts in ${away}`,
      color: '#a78bfa',
      explain: 'You are early. You can open the call now and wait — nothing stops you starting before the scheduled time.',
    }
  }
  if (now <= end.getTime()) {
    return { label: 'Happening now', color: '#10b981', explain: 'This is the scheduled slot.' }
  }
  return {
    label: 'Scheduled time has passed',
    color: '#f59e0b',
    explain: 'The slot has been and gone, but the link does not expire — you can still hold the meeting now, and the notes and attendance save to this same record.',
  }
}

/* ── Pieces ──────────────────────────────────────────────────────────────── */

/**
 * A fixed overlay rather than a page in the app shell.
 *
 * Inside the shell the call had to share the width with the sidebar and sit
 * inside the shell's padding, and a 100vh child of a padded container overflows
 * the viewport — between them that is what made the video small and pushed the
 * layout out of place. Fixed to the viewport, the call gets the screen and the
 * route still lives under /app where the meeting API needs it.
 */
const Shell = ({ children }) => (
  <div style={{ position: 'fixed', inset: 0, zIndex: 1200, display: 'flex', flexDirection: 'column', background: 'var(--bg-global, #0b0b12)' }}>
    <style>{KIT3D_STYLE}</style>
    <style>{`
      @media (max-width: 860px) {
        .mr-body { grid-template-columns: 1fr !important; grid-template-rows: minmax(0,1fr) minmax(0,45vh); }
      }
    `}</style>
    {children}
  </div>
)

const Centre = ({ children, tone }) => (
  <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', color: tone || 'var(--text-muted)', fontSize: 13 }}>{children}</div>
)

const Empty = ({ children }) => <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>{children}</p>

function NoteBox({ label, value, onChange }) {
  return (
    <div style={{ marginTop: 8, marginLeft: 18 }}>
      <label style={{ fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', letterSpacing: '0.04em', textTransform: 'uppercase' }}>{label}</label>
      <textarea value={value} onChange={e => onChange(e.target.value)} rows={2} style={SX.textarea} />
    </div>
  )
}

function SaveBadge({ state }) {
  const cfg = { saving: ['Saving…', '#a78bfa'], saved: ['Saved', '#10b981'], error: ['Not saved', '#ef4444'] }[state]
  if (!cfg) return null
  const [label, color] = cfg
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 700, color }}>
      {state === 'saving' ? <Loader2 size={12} className="ko-spin" /> : state === 'error' ? <AlertTriangle size={12} /> : <CheckCircle2 size={12} />}
      {label}
    </span>
  )
}

/* ── Styles ──────────────────────────────────────────────────────────────── */

const SX = {
  header: { display: 'flex', alignItems: 'center', gap: 12, padding: '10px 16px', borderBottom: '1px solid var(--border)', flexShrink: 0, background: 'var(--bg-card)' },
  iconBtn: { width: 32, height: 32, borderRadius: 9, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', flexShrink: 0 },
  title: { margin: 0, fontSize: 14.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' },
  subtitle: { margin: 0, fontSize: 11, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' },
  timingPill: { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, whiteSpace: 'nowrap', flexShrink: 0 },
  leaveBtn: { padding: '7px 14px', borderRadius: 9, cursor: 'pointer', fontSize: 12.5, fontWeight: 800, background: 'linear-gradient(145deg,#f87171,#ef4444)', border: 'none', color: '#fff', flexShrink: 0 },

  body: { flex: 1, display: 'grid', minHeight: 0, transition: 'grid-template-columns .18s ease' },
  stage: { position: 'relative', background: '#0b0b12', minHeight: 0, minWidth: 0, overflow: 'hidden' },
  connecting: { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 9, color: '#8b85a8', fontSize: 13, pointerEvents: 'none' },

  panel: { borderLeft: '1px solid var(--border)', display: 'flex', flexDirection: 'column', minHeight: 0, minWidth: 0, background: 'var(--bg-card)', overflow: 'hidden' },
  tabs: { display: 'flex', borderBottom: '1px solid var(--border)', flexShrink: 0 },
  tab: { flex: 1, padding: '10px 4px', cursor: 'pointer', border: 'none', borderBottom: '2px solid transparent', background: 'transparent', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 4, whiteSpace: 'nowrap' },
  panelBody: { flex: 1, overflowY: 'auto', padding: 14, minHeight: 0 },
  panelFoot: { padding: '9px 14px', borderTop: '1px solid var(--border)', flexShrink: 0, display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' },
  smallBtn: { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, cursor: 'pointer', fontSize: 12, fontWeight: 700, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' },
  hint: { margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 },
  guestTag: { marginLeft: 6, padding: '1px 5px', borderRadius: 5, fontSize: 8.5, fontWeight: 900, letterSpacing: '0.04em', background: 'rgba(167,139,250,0.16)', color: '#a78bfa' },
  attendeeRow: { display: 'flex', alignItems: 'center', gap: 9, padding: '8px 10px', marginBottom: 6, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' },
  textarea: { width: '100%', marginTop: 3, resize: 'vertical', padding: '7px 9px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12, lineHeight: 1.5, fontFamily: 'inherit' },

  stageError: { position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 12, padding: 24, textAlign: 'center', background: 'var(--bg-global, #0b0b12)' },
  startWrap: { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, overflowY: 'auto' },
  startCard: { width: 'min(460px, 100%)', display: 'flex', flexDirection: 'column', gap: 13, padding: 22, borderRadius: 16, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: '0 24px 60px -20px rgba(0,0,0,0.6)' },
  startNote: { display: 'flex', gap: 9, padding: '11px 12px', borderRadius: 11, border: '1px solid' },
  startBtn: { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8, padding: '12px 18px', borderRadius: 11, cursor: 'pointer', fontSize: 13.5, fontWeight: 800, background: 'linear-gradient(145deg,#34d399,#10b981)', border: 'none', color: '#fff', boxShadow: '0 10px 24px -8px #10b98188' },
}

/**
 * Load Jitsi's embedding script, once per host.
 *
 * Fetched from the meeting's OWN server rather than a fixed address, so the
 * script and the room always come from the same place — including after a move
 * to a self-hosted Jitsi. Cached by host so revisiting the room, or opening a
 * second meeting on the same server, does not re-fetch it.
 */
const scriptPromises = {}
function loadJitsiScript(domain) {
  if (window.JitsiMeetExternalAPI) return Promise.resolve()
  if (scriptPromises[domain]) return scriptPromises[domain]

  scriptPromises[domain] = new Promise((resolve, reject) => {
    const el = document.createElement('script')
    el.src = `https://${domain}/external_api.js`
    el.async = true
    el.onload = resolve
    // Forget the failure so a retry actually retries instead of replaying the
    // rejected promise for the rest of the session.
    el.onerror = () => { delete scriptPromises[domain]; reject(new Error('jitsi script failed to load')) }
    document.head.appendChild(el)
  })
  return scriptPromises[domain]
}
