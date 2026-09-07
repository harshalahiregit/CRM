import { useState, useEffect } from 'react'
import { Send, Upload, Calendar, ChevronDown, ChevronRight, FileCheck, Video, AlertTriangle, CheckCircle2 } from 'lucide-react'
import RichText from '@/components/ui/RichText'

/**
 * §32 governance tabs shared by both vendor portals (TPV + Purchase). Purely
 * presentational and API-agnostic: each tab takes a `gov` object (the portal's
 * own governance API block) and, for certificates, a `listWorkers` fetcher. The
 * two portals hit their own separate backends — nothing here is module-specific.
 */

const label = (s) => String(s || '').replace(/_/g, ' ')
const dt = (v) => (v ? new Date(v).toLocaleString() : '—')
const d = (v) => (v ? new Date(v).toLocaleDateString() : '—')
const STATUS_TONE = { Open: '#d97706', In_Progress: '#0891b2', Closed: '#16a34a', Verified: '#16a34a', Pending: '#64748b' }

/* ── Meeting timing ────────────────────────────────────────────────────────
 *
 * `status` is what people decided about the meeting; `timing_state` is where it
 * sits against the clock, and the backend derives it (App\Support\Shared\
 * MeetingTiming) so the portal, the dashboards and the admin screens cannot
 * disagree about whether a meeting has passed. Both are shown, because
 * "Scheduled · Expired" is the honest description of a meeting nobody closed.
 */
const TIMING_TONE = { upcoming: '#0891b2', live: '#16a34a', ended: '#475569', expired: '#dc2626', closed: '#64748b', draft: '#64748b' }

/** "2:30 PM – 3:30 PM" from a start and an end on the same day. */
const timeRange = (start, end) => {
  if (!start) return '—'
  const from = new Date(start)
  const opts = { hour: 'numeric', minute: '2-digit' }
  if (!end) return from.toLocaleString()
  const to = new Date(end)
  const sameDay = from.toDateString() === to.toDateString()
  return sameDay
    ? `${from.toLocaleDateString()}, ${from.toLocaleTimeString([], opts)} – ${to.toLocaleTimeString([], opts)}`
    : `${from.toLocaleString()} – ${to.toLocaleString()}`
}

const durationText = (mins) => {
  const n = Number(mins)
  if (!Number.isFinite(n) || n <= 0) return null
  return n >= 60 ? `${Math.floor(n / 60)}h${n % 60 ? ` ${n % 60}m` : ''}` : `${n} min`
}

/* ── Meetings & MOM ───────────────────────────────────────────────────── */
export function MeetingsTab({ gov }) {
  const [rows, setRows] = useState(null)
  const [open, setOpen] = useState(null)     // meeting id whose MOM is expanded
  const [mom, setMom] = useState({})         // id → loaded MOM detail
  const [momErr, setMomErr] = useState({})   // id → why it could not be shown

  useEffect(() => { gov.meetings().then(r => setRows(r?.data ?? [])).catch(() => setRows([])) }, [])

  /**
   * Open the meeting, recording that this person did.
   *
   * The window is opened FIRST, synchronously, because a browser only allows a
   * popup during the click that asked for it — opening it after the request
   * came back would be blocked. The recording follows into the tab already open.
   */
  const joinMeeting = (m) => {
    const tab = window.open('', '_blank', 'noopener')
    const go = (url) => { if (tab) tab.location = url; else window.location.href = url }

    if (!gov?.joinMeeting) return go(m.meeting_link)

    gov.joinMeeting(m.id)
      .then(r => go(r?.link || m.meeting_link))
      // Never stand between somebody and their meeting: a register entry that
      // did not save is a smaller problem than a vendor who could not join.
      .catch(() => go(m.meeting_link))
  }

  const toggle = (id) => {
    if (open === id) { setOpen(null); return }
    setOpen(id)
    if (mom[id] || momErr[id]) return

    gov.meetingMom(id)
      .then(m => setMom(s => ({ ...s, [id]: m })))
      // Minutes that are not yet approved and distributed answer 403, which is
      // an ANSWER, not a failure. Swallowing it left the row spinning on
      // "Loading minutes…" indefinitely with nothing to explain why.
      .catch(e => setMomErr(s => ({
        ...s,
        [id]: e?.response?.status === 403
          ? 'The minutes for this meeting have not been shared yet. They appear here once they are approved and issued.'
          : 'The minutes could not be loaded. Please try again in a moment.',
      })))
  }

  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No meetings recorded for you yet." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(m => (
        <div key={m.id} style={card}>
          <button onClick={() => toggle(m.id)} style={rowBtn}>
            {open === m.id ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            <Calendar size={15} style={{ color: '#0891b2' }} />
            <span style={{ fontWeight: 800, color: 'var(--text-h)' }}>{m.reference} · {m.title}</span>
            <span style={{ flex: 1 }} />
            <Pill text={label(m.meeting_type)} tone="#64748b" />
            <Pill text={label(m.status)} tone={STATUS_TONE[m.status]} />
            {/* Where it sits against the clock, beside what was decided. */}
            {m.timing_state && m.timing_state !== 'closed' && (
              <Pill text={m.timing_label || m.timing_state} tone={TIMING_TONE[m.timing_state]} />
            )}
          </button>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px 0 0', paddingLeft: 26 }}>
            {/* The full slot, not just the start — a start time alone never said
                when the meeting was over, so nothing on this screen could tell
                you whether it had. */}
            {timeRange(m.scheduled_at, m.ends_at)}
            {durationText(m.duration_minutes) ? ` · ${durationText(m.duration_minutes)}` : ''}
            {' · '}{label(m.mode)}{m.location ? ` · ${m.location}` : ''}
          </div>

          {/* Expired: the slot passed and nobody completed or cancelled it. Said
              plainly, because the vendor's question is "is this still happening?" */}
          {/* A meeting that was actually held and has finished. Different news
              from an expired one, and the vendor was in it — telling them it
              "expired" would contradict what they just sat through. */}
          {m.timing_state === 'ended' && (
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, margin: '8px 0 0 26px', padding: '8px 11px', borderRadius: 9, background: 'rgba(100,116,139,0.10)', border: '1px solid rgba(100,116,139,0.25)' }}>
              <CheckCircle2 size={14} style={{ color: '#475569', flexShrink: 0, marginTop: 1 }} />
              <span style={{ fontSize: 12, color: '#475569', fontWeight: 600 }}>
                This meeting has ended{m.actual_start_at ? ` — held ${timeRange(m.actual_start_at, m.actual_end_at)}` : ''}
                {m.held_minutes ? ` (${m.held_minutes} min)` : ''}. The minutes will be shared with you once they are approved.
              </span>
            </div>
          )}

          {m.timing_state === 'expired' && (
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, margin: '8px 0 0 26px', padding: '8px 11px', borderRadius: 9, background: 'rgba(220,38,38,0.08)', border: '1px solid rgba(220,38,38,0.25)' }}>
              <AlertTriangle size={14} style={{ color: '#dc2626', flexShrink: 0, marginTop: 1 }} />
              <span style={{ fontSize: 12, color: '#b91c1c', fontWeight: 600 }}>
                This meeting has expired — its time passed on {dt(m.ends_at)} and it was never
                marked complete. The join link is no longer available. The organiser has been notified.
              </span>
            </div>
          )}

          {/* Join the online meeting straight from the portal (point 11).
              Deliberately gated on the CLOCK as well as the status: the link was
              offered for meetings that had already finished, which reads as
              though they are still open. */}
          {m.meeting_link && m.mode !== 'onsite' && !m.is_expired && m.status !== 'Completed' && m.status !== 'Cancelled' && (
            <div style={{ paddingLeft: 26, marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
              {/* A button, not a bare link: opening the meeting is recorded on
                  the way through, which is the only attendance evidence that
                  exists for a meeting held on Google Meet, Zoom or Teams. The
                  href stays so it still behaves like a link — middle-click,
                  copy address — and so it works if the recording call fails. */}
              <a href={m.meeting_link} target="_blank" rel="noopener noreferrer"
                onClick={(e) => { e.preventDefault(); joinMeeting(m) }}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, textDecoration: 'none', color: '#fff', background: m.is_live ? 'linear-gradient(145deg,#22c55e,#16a34a)' : 'linear-gradient(145deg,#38bdf8,#0284c7)' }}>
                <Video size={14} /> {m.is_live ? 'Join now' : 'Join meeting'}
              </a>
              {m.is_live && <span style={{ fontSize: 11.5, fontWeight: 800, color: '#16a34a' }}>● In progress</span>}
              {/* The address itself, readable — so it can be copied, read out,
                  or opened on another device. */}
              <a href={m.meeting_link} target="_blank" rel="noopener noreferrer" title={m.meeting_link}
                style={{ fontSize: 11.5, color: 'var(--text-muted)', maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {String(m.meeting_link).replace(/^https?:\/\//, '')}
              </a>
              {!m.is_live && Number.isFinite(Number(m.minutes_until_start)) && Number(m.minutes_until_start) > 0 && (
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>starts in {durationText(m.minutes_until_start)}</span>
              )}
            </div>
          )}
          {open === m.id && <MomDetail data={mom[m.id]} error={momErr[m.id]} gov={gov} meetingId={m.id} />}
        </div>
      ))}
    </div>
  )
}

/**
 * The distributed minutes, as the vendor reads them.
 *
 * This used to read `mom_items` and `decisions` off whatever the portal sent.
 * Those are the SHARED engine's names; Purchase sends `action_items` and
 * `mom_decisions`, so a Purchase vendor opened their minutes and found only the
 * agenda — every action and every decision was in the response, under names
 * this screen was not looking for, and dropped without a word. Issues were
 * dropped for both, and so was the minutes text itself.
 *
 * Both portals now answer in one agreed shape (VendorMomView on the server), so
 * this reads one set of names and the next thing added cannot go missing from
 * one portal only.
 */
function MomDetail({ data, error, gov, meetingId }) {
  const [downloading, setDownloading] = useState(false)

  // Not available is not the same as not loaded. Minutes that have not been
  // approved and distributed return 403, and swallowing that left the row
  // spinning on "Loading minutes…" for ever with no reason given.
  if (error) {
    return (
      <div style={{ padding: '10px 26px', color: 'var(--text-muted)', fontSize: 12.5, lineHeight: 1.5 }}>
        {error}
      </div>
    )
  }
  if (!data) return <div style={{ padding: '10px 26px', color: 'var(--text-muted)', fontSize: 12.5 }}>Loading minutes…</div>

  const agenda = data.agenda ?? []
  const actions = data.actions ?? []
  const decisions = data.decisions ?? []
  const issues = data.issues ?? []
  const documents = data.documents ?? []
  const minutes = data.minutes

  const save = (blob, filename) => {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = filename
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  }

  // Download a supporting document. The minutes are shown as structured info
  // (never a raw embedded PDF); the file is offered as an explicit download.
  const download = async (doc) => {
    if (!gov?.meetingDocument) return
    try { save(await gov.meetingDocument(meetingId, doc.id), doc.original_name || doc.label) }
    catch { /* swallow — a failed download must not break the view */ }
  }

  // The minutes document. The point of approving and distributing minutes is
  // that the vendor can read them; until now the only route that served this
  // file was behind an admin role, so they never could.
  const downloadMom = async () => {
    if (!gov?.meetingMomFile) return
    setDownloading(true)
    try { save(await gov.meetingMomFile(meetingId), `Minutes-${data.meeting?.meeting_no || meetingId}.pdf`) }
    catch { /* the button stays; nothing else on the page should break */ }
    finally { setDownloading(false) }
  }

  const empty = agenda.length + actions.length + decisions.length + issues.length === 0 && !minutes

  return (
    <div style={{ paddingLeft: 26, marginTop: 10, display: 'grid', gap: 12 }}>
      {data.mom_document_available && gov?.meetingMomFile && (
        <button onClick={downloadMom} disabled={downloading}
          style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8, padding: '9px 14px', borderRadius: 9, cursor: downloading ? 'wait' : 'pointer', fontSize: 12.5, fontWeight: 800, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#38bdf8,#0284c7)', justifySelf: 'start' }}>
          <FileCheck size={15} /> {downloading ? 'Opening…' : 'Download the minutes (PDF)'}
        </button>
      )}

      {minutes && (
        <div>
          <div style={sectionHead}>Minutes</div>
          <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{minutes}</p>
        </div>
      )}

      {agenda.length > 0 && (
        <Section title="Agenda">
          {agenda.map((a, i) => (
            <li key={i} style={li}>
              <RichText html={a.description_html} text={a.item || a.description} />
              {/* What was said and settled under each point — captured in the
                  meeting room and, until now, never shown to the vendor. */}
              {a.discussion && <div style={subLine}>Discussed: {a.discussion}</div>}
              {a.decision && <div style={subLine}>Decided: {a.decision}</div>}
            </li>
          ))}
        </Section>
      )}

      {actions.length > 0 && (
        <Section title="Action items (MOM)">
          {actions.map((it, i) => (
            <li key={i} style={li}>
              <b>{it.ref ? `${it.ref} · ` : ''}</b>
              <RichText html={it.description_html} text={it.description} />
              <span style={{ color: 'var(--text-muted)' }}>
                {' '}— {it.owner || 'Unassigned'} · due {d(it.target_date)} · {label(it.status)}
              </span>
            </li>
          ))}
        </Section>
      )}

      {decisions.length > 0 && (
        <Section title="Decisions">
          {decisions.map((x, i) => (
            <li key={i} style={li}>
              <b>{x.ref ? `${x.ref} · ` : ''}</b>{x.decision}
              {x.decided_by && <span style={{ color: 'var(--text-muted)' }}> — {x.decided_by}</span>}
            </li>
          ))}
        </Section>
      )}

      {/* Issues were fetched, serialised and then never rendered — the one part
          of the minutes a vendor most needs to see about their own work. */}
      {issues.length > 0 && (
        <Section title="Issues raised">
          {issues.map((x, i) => (
            <li key={i} style={li}>
              <b>{x.ref ? `${x.ref} · ` : ''}</b>{x.title}
              <span style={{ color: 'var(--text-muted)' }}>
                {x.severity ? ` — ${label(x.severity)}` : ''}{x.owner ? ` · ${x.owner}` : ''}
                {x.due_date ? ` · due ${d(x.due_date)}` : ''} · {label(x.status)}
              </span>
              {x.description && <RichText html={x.description_html} text={x.description} style={subLine} />}
            </li>
          ))}
        </Section>
      )}

      {documents.length > 0 && gov?.meetingDocument && (
        <div>
          <div style={sectionHead}>Documents</div>
          <div style={{ display: 'grid', gap: 6 }}>
            {documents.map((doc) => (
              <button key={doc.id} onClick={() => download(doc)}
                style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 11px', borderRadius: 9, cursor: 'pointer', textAlign: 'left', background: 'var(--bg-input, #f8fafc)', border: '1px solid var(--border, #e2e8f0)' }}>
                <FileCheck size={15} style={{ color: '#0891b2', flexShrink: 0 }} />
                <span style={{ flex: 1, minWidth: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{doc.label}</span>
                <span style={{ fontSize: 11, fontWeight: 700, color: '#0891b2' }}>Download</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {empty && <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>No minutes captured for this meeting.</div>}
    </div>
  )
}

const sectionHead = { fontSize: 11.5, fontWeight: 800, letterSpacing: '0.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 6 }
const subLine = { fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2, lineHeight: 1.45 }

/* ── Action items — vendor adds progress ──────────────────────────────── */
export function ActionsTab({ gov }) {
  const [rows, setRows] = useState(null)
  const [draft, setDraft] = useState({})
  const load = () => gov.actions().then(r => setRows(r?.data ?? [])).catch(() => setRows([]))
  useEffect(() => { load() }, [])

  const submit = (id) => {
    if (!draft[id]?.trim()) return
    gov.respondAction(id, { note: draft[id] }).then(() => { setDraft(s => ({ ...s, [id]: '' })); load() })
  }

  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No action items assigned to you." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(a => (
        <div key={a.id} style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
            {/* The ref stays a heading; the instruction itself is rich text and
                is rendered as such. Concatenated into one text node it produced
                a heading made of `<span style=...>` and a base64 image src — the
                vendor could not read what they had been asked to do. */}
            <div style={{ minWidth: 0, flex: 1 }}>
              {a.action_ref && <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{a.action_ref}</div>}
              <RichText html={a.description_html} text={a.description}
                style={{ fontWeight: 700, color: 'var(--text-h)' }} />
            </div>
            <div style={{ display: 'flex', gap: 6 }}>
              {a.priority && <Pill text={label(a.priority)} tone="#64748b" />}
              <Pill text={label(a.status)} tone={STATUS_TONE[a.status]} />
            </div>
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
            {a.responsible?.name || a.responsible_names || 'Unassigned'} · due {d(a.target_date)}
          </div>
          {a.remark && (
            <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-muted)' }}>
              <b>Progress:</b>
              <RichText html={a.remark_html} text={a.remark} />
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
            <input value={draft[a.id] || ''} onChange={e => setDraft(s => ({ ...s, [a.id]: e.target.value }))}
              placeholder="Add a progress update…" style={input} />
            <button onClick={() => submit(a.id)} style={btnPrimary}><Send size={14} /> Update</button>
          </div>
        </div>
      ))}
    </div>
  )
}

/* ── Worker certificate upload ────────────────────────────────────────── */
export function CertificatesTab({ gov, listWorkers }) {
  const [workers, setWorkers] = useState(null)
  const [form, setForm] = useState({ worker_id: '', kind: 'training', name: '', category: '', valid_until: '' })
  const [file, setFile] = useState(null)
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    listWorkers().then(r => setWorkers(r?.data ?? r ?? [])).catch(() => setWorkers([]))
  }, [])

  const submit = () => {
    if (!form.worker_id || !form.name.trim() || !file) { setMsg('Pick a worker, name the certificate and attach a file.'); return }
    const fd = new FormData()
    fd.append('kind', form.kind)
    fd.append('name', form.name)
    if (form.category) fd.append('category', form.category)
    if (form.valid_until) fd.append('valid_until', form.valid_until)
    fd.append('certificate', file)
    setBusy(true)
    gov.uploadCertificate(form.worker_id, fd)
      .then(() => { setMsg('Certificate uploaded.'); setForm(f => ({ ...f, name: '', category: '', valid_until: '' })); setFile(null) })
      .catch(() => setMsg('Upload failed — check the file type (PDF/PNG/JPG, ≤10 MB).'))
      .finally(() => setBusy(false))
  }

  if (workers === null) return <Loading />
  return (
    <div style={{ ...card, padding: 16, maxWidth: 560 }}>
      <h3 style={h3}><FileCheck size={15} /> Upload a training / competency certificate</h3>
      {msg && <div style={{ color: msg.includes('failed') || msg.includes('Pick') ? '#dc2626' : '#16a34a', fontSize: 12.5, fontWeight: 600, marginBottom: 8 }}>{msg}</div>}
      {!workers.length
        ? <Empty text="No workers on your roster yet." />
        : (
          <div style={{ display: 'grid', gap: 8 }}>
            <select value={form.worker_id} onChange={e => setForm(f => ({ ...f, worker_id: e.target.value }))} style={input}>
              <option value="">Select worker…</option>
              {workers.map(w => <option key={w.id} value={w.id}>{w.name}{w.worker_code ? ` (${w.worker_code})` : ''}</option>)}
            </select>
            <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} style={input}>
              <option value="training">Training</option>
              <option value="competency">Competency</option>
            </select>
            <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="Certificate name (e.g. Working at Height)" style={input} />
            <input value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} placeholder="Category (optional)" style={input} />
            <label style={{ fontSize: 12, color: 'var(--text-muted)' }}>Valid until (optional)
              <input type="date" value={form.valid_until} onChange={e => setForm(f => ({ ...f, valid_until: e.target.value }))} style={{ ...input, marginTop: 4 }} />
            </label>
            <input type="file" accept=".pdf,.png,.jpg,.jpeg" onChange={e => setFile(e.target.files?.[0] ?? null)} style={{ fontSize: 12.5, color: 'var(--text-muted)' }} />
            <button onClick={submit} disabled={busy} style={{ ...btnPrimary, marginTop: 4, opacity: busy ? 0.6 : 1 }}>
              <Upload size={14} /> {busy ? 'Uploading…' : 'Upload certificate'}
            </button>
          </div>
        )}
    </div>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────── */
const Loading = () => <div style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</div>
const Empty = ({ text }) => <div style={{ ...card, padding: 20, color: 'var(--text-muted)' }}>{text}</div>
function Pill({ text, tone }) {
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: `${tone || '#64748b'}1f`, color: tone || '#64748b' }}>{text}</span>
}
function Section({ title, children }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', marginBottom: 4 }}>{title}</div>
      <ul style={{ margin: 0, paddingLeft: 18, display: 'grid', gap: 3 }}>{children}</ul>
    </div>
  )
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 14 }
const input = { width: '100%', padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input, var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: '#0891b2', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700, whiteSpace: 'nowrap' }
const h3 = { display: 'flex', alignItems: 'center', gap: 6, margin: '0 0 10px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }
const rowBtn = { display: 'flex', alignItems: 'center', gap: 8, width: '100%', background: 'none', border: 'none', cursor: 'pointer', padding: 0, textAlign: 'left' }
const li = { fontSize: 12.5, color: 'var(--text-h)' }
